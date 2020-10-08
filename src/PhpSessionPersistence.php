<?php

/**
 * @see       https://github.com/mezzio/mezzio-session-ext for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-session-ext/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-session-ext/blob/master/LICENSE.md New BSD License
 */

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
     *
     * @var bool
     */
    private $nonLocking;

    /**
     * Memorize session ini settings before starting the request.
     *
     * The cache_limiter setting is actually "stolen", as we will start the
     * session with a forced empty value in order to instruct the php engine to
     * skip sending the cache headers (this being php's default behaviour).
     * Those headers will be added programmatically to the response along with
     * the session set-cookie header when the session data is persisted.
     *
     * @param bool $nonLocking use the non locking mode during initialization?
     * @param bool $deleteCookieOnEmptySession delete cookie from browser when session becomes empty?
     */
    public function __construct(bool $nonLocking = false, bool $deleteCookieOnEmptySession = false)
    {
        $this->nonLocking                 = $nonLocking;
        $this->deleteCookieOnEmptySession = $deleteCookieOnEmptySession;

        // Get session cache ini settings
        $this->cacheLimiter = ini_get('session.cache_limiter');
        $this->cacheExpire  = (int) ini_get('session.cache_expire');

        // Get session cookie ini settings
        $this->cookieName     = ini_get('session.name');
        $this->cookieLifetime = (int) ini_get('session.cookie_lifetime');
        $this->cookiePath     = ini_get('session.cookie_path');
        $this->cookieDomain   = ini_get('session.cookie_domain');
        $this->cookieSecure   = filter_var(
            ini_get('session.cookie_secure'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        $this->cookieHttpOnly = filter_var(
            ini_get('session.cookie_httponly'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        $this->cookieSameSite = ini_get('session.cookie_samesite');
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
