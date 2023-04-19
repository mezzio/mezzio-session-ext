<?php

declare(strict_types=1);

namespace Mezzio\Session\Ext;

use Mezzio\Session\InitializePersistenceIdInterface;
use Mezzio\Session\Persistence\CacheHeadersGeneratorTrait;
use Mezzio\Session\Persistence\SessionCookieAwareTrait;
use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionPersistenceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function bin2hex;
use function filter_var;
use function ini_get;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function random_bytes;
use function session_destroy;
use function session_id;
use function session_start;
use function session_status;
use function session_write_close;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const PHP_SESSION_ACTIVE;

/**
 * Session persistence using ext-session.
 *
 * Adapts ext-session to work with PSR-7 by disabling its auto-cookie creation
 * (`use_cookies => false`), while simultaneously requiring cookies for session
 * handling (`use_only_cookies => true`). The implementation pulls cookies
 * manually from the request, and injects a `Set-Cookie` header into the
 * response.
 *
 * Session identifiers are generated using random_bytes (and casting to hex).
 * During persistence, if the session regeneration flag is true, a new session
 * identifier is created, and the session re-started.
 */
class PhpSessionPersistence implements InitializePersistenceIdInterface, SessionPersistenceInterface
{
    use CacheHeadersGeneratorTrait;
    use SessionCookieAwareTrait;

    /**
     * Use non locking mode during session initialization?
     */
    private bool $nonLocking;

    /**
     * Memorize session ini settings before starting the request.
     *
     * The cache_limiter setting is actually "stolen", as we will start the
     * session with a forced empty value in order to instruct the php engine to
     * skip sending the cache headers (this being php's default behaviour).
     * Those headers will be added programmatically to the response along with
     * the session set-cookie header when the session data is persisted.
     *
     * @param array $session
     */
    public function __construct(array $session = [])
    {
        $persistence = isset($session['persistence']) && is_array($session['persistence'])
            ? $session['persistence'] : [];
        $ext         = isset($persistence['ext']) && is_array($persistence['ext']) ? $persistence['ext'] : [];

        $this->nonLocking                 = ! empty($ext['non_locking']);
        $this->deleteCookieOnEmptySession = ! empty($ext['delete_cookie_on_empty_session']);

        // Get session cache ini settings
        $this->cacheLimiter = isset($session['cache_limiter']) && is_string($session['cache_limiter'])
            ? $session['cache_limiter']
            : ini_get('session.cache_limiter');
        $this->cacheExpire  = isset($session['cache_expire']) && is_int($session['cache_expire'])
            ? $session['cache_expire']
            : (int) ini_get('session.cache_expire');

        // Get session cookie ini settings
        $this->cookieName     = isset($session['name']) && is_string($session['name'])
            ? $session['name']
            : ini_get('session.name');
        $this->cookieLifetime = isset($session['cookie_lifetime']) && is_int($session['cookie_lifetime'])
            ? $session['cookie_lifetime']
            : (int) ini_get('session.cookie_lifetime');
        $this->cookiePath     = isset($session['cookie_path']) && is_string($session['cookie_path'])
            ? $session['cookie_path']
            : ini_get('session.cookie_path');
        $this->cookieDomain   = isset($session['cookie_domain']) && is_string($session['cookie_domain'])
            ? $session['cookie_domain']
            : ini_get('session.cookie_domain');
        $this->cookieSecure   = isset($session['cookie_secure']) && is_bool($session['cookie_secure'])
            ? $session['cookie_secure']
            : (bool) filter_var(
                ini_get('session.cookie_secure'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        $this->cookieHttpOnly = isset($session['cookie_httponly']) && is_bool($session['cookie_httponly'])
            ? $session['cookie_httponly']
            : (bool) filter_var(
                ini_get('session.cookie_httponly'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        $this->cookieSameSite = isset($session['cookie_samesite']) && is_string($session['cookie_samesite'])
            ? $session['cookie_samesite']
            : ini_get('session.cookie_samesite');
    }

    /**
     * @internal
     *
     * @return bool the non-locking mode used during initialization
     */
    public function isNonLocking(): bool
    {
        return $this->nonLocking;
    }

    public function initializeSessionFromRequest(ServerRequestInterface $request): SessionInterface
    {
        $sessionId = $this->getSessionCookieValueFromRequest($request);
        if ($sessionId) {
            $this->startSession($sessionId, [
                'read_and_close' => $this->nonLocking,
            ]);
        }
        return new Session($_SESSION ?? [], $sessionId);
    }

    public function persistSession(SessionInterface $session, ResponseInterface $response): ResponseInterface
    {
        $id = $session->getId();

        // Regenerate if:
        // - the session is marked as regenerated
        // - the id is empty, but the data has changed (new session)
        if (
            $session->isRegenerated()
            || ($id === '' && $session->hasChanged())
        ) {
            $id = $this->regenerateSession();
        } elseif ($this->nonLocking && $session->hasChanged()) {
            // we reopen the initial session only if there are changes to write
            $this->startSession($id);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = $session->toArray();
            session_write_close();
        }

        // If we do not have an identifier at this point, it means a new
        // session was created, but never written to. In that case, there's
        // no reason to provide a cookie back to the user.
        if ($id === '') {
            return $response;
        }

        // A session that did not change at all does not need to be sent to the browser
        if (! $session->hasChanged()) {
            return $response;
        }

        $response = $this->addSessionCookieHeaderToResponse($response, $id, $session);
        $response = $this->addCacheHeadersToResponse($response);

        return $response;
    }

    public function initializeId(SessionInterface $session): SessionInterface
    {
        $id = $session->getId();
        if ($id === '' || $session->isRegenerated()) {
            $session = new Session($session->toArray(), $this->generateSessionId());
        }

        session_id($session->getId());

        return $session;
    }

    /**
     * @param array $options Additional options to pass to `session_start()`.
     */
    private function startSession(string $id, array $options = []): void
    {
        session_id($id);
        session_start([
            'use_cookies'      => false,
            'use_only_cookies' => true,
            'cache_limiter'    => '',
        ] + $options);
    }

    /**
     * Regenerates the session safely.
     */
    private function regenerateSession(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $id = $this->generateSessionId();
        $this->startSession($id, [
            'use_strict_mode' => false,
        ]);
        return $id;
    }

    /**
     * Generate a session identifier.
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
