<?php

declare(strict_types=1);

namespace MezzioTest\Session\Ext;

use Dflydev\FigCookies\Cookie;
use Dflydev\FigCookies\FigRequestCookies;
use Dflydev\FigCookies\FigResponseCookies;
use Dflydev\FigCookies\SetCookie;
use Dflydev\FigCookies\SetCookies;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Session\Ext\PhpSessionPersistence;
use Mezzio\Session\Persistence\CacheHeadersGeneratorTrait;
use Mezzio\Session\Persistence\Http;
use Mezzio\Session\Session;
use Mezzio\Session\SessionCookiePersistenceInterface;
use Mezzio\Session\SessionIdentifierAwareInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionMethod;

use function filemtime;
use function filter_var;
use function getlastmod;
use function glob;
use function gmdate;
use function ini_get;
use function ini_set;
use function is_bool;
use function is_dir;
use function is_string;
use function mkdir;
use function session_id;
use function session_name;
use function session_save_path;
use function session_set_cookie_params;
use function session_start;
use function session_status;
use function session_write_close;
use function sprintf;
use function strtotime;
use function sys_get_temp_dir;
use function time;
use function unlink;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const PHP_SESSION_ACTIVE;
use const PHP_SESSION_NONE;

#[RunTestsInSeparateProcesses]
class PhpSessionPersistenceTest extends TestCase
{
    /**
     * Generic persistance instance to be used when custom ini-settings are not
     * applied. In that case instantiate a fresh instance after applying custom
     * ini-settings.
     */
    private PhpSessionPersistence $persistence;

    private array $originalSessionSettings;

    private string $sessionSavePath;

    protected function setUp(): void
    {
        $this->sessionSavePath = sys_get_temp_dir() . '/mezzio-session-ext';

        $this->originalSessionSettings = $this->applyCustomSessionOptions([
            'save_path' => $this->sessionSavePath,
        ]);

        // create a temp session save path
        if (! is_dir($this->sessionSavePath)) {
            mkdir($this->sessionSavePath);
        }

        $this->persistence = new PhpSessionPersistence();
    }

    protected function tearDown(): void
    {
        session_write_close();
        $this->restoreOriginalSessionIniSettings($this->originalSessionSettings);

        // remove old session test files if any
        $files = glob("{$this->sessionSavePath}/sess_*");
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }

    public function startSession(?string $id = null, array $options = []): void
    {
        $id ??= 'testing';
        session_id($id);
        session_start([
            'use_cookies'      => false,
            'use_only_cookies' => true,
        ] + $options);
    }

    /** @param array<string, mixed> $serverParams */
    private function createSessionCookieRequest(
        ?string $sessionId = null,
        ?string $sessionName = null,
        array $serverParams = []
    ): ServerRequestInterface {
        $request = FigRequestCookies::set(
            new ServerRequest($serverParams),
            Cookie::create(
                $sessionName ?? session_name(),
                $sessionId ?? 'testing'
            )
        );

        self::assertInstanceOf(ServerRequestInterface::class, $request);

        return $request;
    }

    /**
     * @param array $options Custom session options (without the "session" namespace)
     * @return array Return the original (and overwritten) namespaced ini settings
     */
    private function applyCustomSessionOptions(array $options): array
    {
        $ini = [];
        foreach ($options as $key => $value) {
            $iniKey       = "session.{$key}";
            $ini[$iniKey] = ini_get($iniKey);
            ini_set($iniKey, (string) (is_bool($value) ? (int) $value : $value));
        }

        return $ini;
    }

    /**
     * @param array $ini The original session namespaced ini settings
     */
    private function restoreOriginalSessionIniSettings(array $ini): void
    {
        foreach ($ini as $key => $value) {
            ini_set($key, $value);
        }
    }

    private function assertPersistedSessionsCount(int $expectedCount): void
    {
        $files = glob("{$this->sessionSavePath}/sess_*");
        $this->assertCount($expectedCount, $files);
    }

    public function testInitializeSessionFromRequestDoesNotStartPhpSessionIfNoSessionCookiePresent(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status());

        $request = new ServerRequest();
        $session = $this->persistence->initializeSessionFromRequest($request);

        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame('', session_id());
        $this->assertInstanceOf(Session::class, $session);
        $this->assertFalse(isset($_SESSION));
    }

    public function testInitializeSessionFromRequestUsesSessionCookieFromRequest(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status());

        $request = $this->createSessionCookieRequest('use-this-id');
        $session = $this->persistence->initializeSessionFromRequest($request);

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertInstanceOf(Session::class, $session);
        $this->assertTrue(isset($_SESSION));
        $this->assertSame($_SESSION, $session->toArray());
        $this->assertSame('use-this-id', session_id());
    }

    public function testPersistSessionStartsPhpSessionEvenIfNoSessionCookiePresentButSessionChanged(): void
    {
        // request without session-cookie
        $request = new ServerRequest();

        // first request of original session cookie
        $session = $this->persistence->initializeSessionFromRequest($request);
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        // no php session here
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertFalse(isset($_SESSION));

        // alter session
        $session->set('foo', 'bar');

        $response         = new Response();
        $returnedResponse = $this->persistence->persistSession($session, $response);

        // check that php-session was started and $session data persisted into it
        $this->assertTrue(isset($_SESSION));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/i', session_id());
        $this->assertSame($session->toArray(), $_SESSION);

        // check the returned response
        $this->assertNotSame($response, $returnedResponse);
        $setCookie = FigResponseCookies::get($returnedResponse, session_name());
        $this->assertInstanceOf(SetCookie::class, $setCookie);
        $this->assertNotEquals('', $setCookie->getValue());
        $this->assertSame(session_id(), $setCookie->getValue());
    }

    public function testPersistSessionGeneratesCookieWithNewSessionIdIfSessionWasRegenerated(): void
    {
        $sessionName = 'REGENERATEDSESSID';
        $ini         = $this->applyCustomSessionOptions([
            'name' => $sessionName,
        ]);

        // we must create a new instance to have the correct cookieName from the
        // previously customized ini-settings
        $persistence = new PhpSessionPersistence();

        $request = $this->createSessionCookieRequest('original-id', $sessionName);

        // Emulate a session-middleware process() excution:
        //
        // 1. init from request with original session cookie
        $session = $persistence->initializeSessionFromRequest($request);
        // 2. emulate session regeneration in inner middleware (user code)
        $session = $session->regenerate();
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        // 3. emulate returning a response from inner middleware (user code)
        $response = new Response();
        // 3. persist session
        $returnedResponse = $persistence->persistSession($session, $response);

        // ...finally make some assertions about the returned response

        // assert that the response is an altered clone
        $this->assertNotSame($response, $returnedResponse);
        // assert that the response has the session-cookie header
        $setCookie = FigResponseCookies::get($returnedResponse, $sessionName);
        $this->assertInstanceOf(SetCookie::class, $setCookie);
        // assert that the response set-cookie value differes from the original id
        $this->assertNotSame('original-id', $setCookie->getValue());
        // the session was restarted with the regenerated value, assert that the
        // last session-id matches the response set-cookie value
        $this->assertSame(session_id(), $setCookie->getValue());
        // we did not alter the session-data, assert that the data loaded by
        // php-ext matches the session data
        $this->assertSame($session->toArray(), $_SESSION);

        $this->restoreOriginalSessionIniSettings($ini);
    }

    /**
     * If Session COOKIE is present, persistSession() method must return Response with Set-Cookie header
     */
    public function testPersistSessionReturnsResponseWithSetCookieHeaderIfSessionCookiePresent(): void
    {
        $request = $this->createSessionCookieRequest('use-this-id');
        $session = $this->persistence->initializeSessionFromRequest($request);
        $session->set('foo', __METHOD__);
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        $response         = new Response();
        $returnedResponse = $this->persistence->persistSession($session, $response);
        $this->assertNotSame($response, $returnedResponse);

        $setCookie = FigResponseCookies::get($returnedResponse, session_name());
        $this->assertInstanceOf(SetCookie::class, $setCookie);
        $this->assertSame(session_id(), $setCookie->getValue());
        $this->assertSame(ini_get('session.cookie_path'), $setCookie->getPath());

        // @see https://github.com/zendframework/zend-expressive-session-ext/pull/31
        $iniDomain    = ini_get('session.cookie_domain');
        $expectDomain = $iniDomain !== false && $iniDomain !== '' ? $iniDomain : null;
        $this->assertSame($expectDomain, $setCookie->getDomain());
        $this->assertSame((bool) ini_get('session.cookie_secure'), $setCookie->getSecure());
        $this->assertSame((bool) ini_get('session.cookie_httponly'), $setCookie->getHttpOnly());
    }

    /**
     * If Session COOKIE is not present, persistSession() method must return the original Response
     */
    public function testPersistSessionReturnsOriginalResponseIfNoSessionCookiePresent(): void
    {
        $this->startSession();
        $session  = new Session([]);
        $response = new Response();

        $returnedResponse = $this->persistence->persistSession($session, $response);
        $this->assertSame($response, $returnedResponse);
    }

    public function testPersistSessionIfSessionHasContents(): void
    {
        $this->startSession();
        $session = new Session(['foo' => 'bar']);
        $this->persistence->persistSession($session, new Response());
        $this->assertSame($session->toArray(), $_SESSION);
    }

    public function testPersistSessionReturnsExpectedResponseWithCacheHeadersIfCacheLimiterIsNocache(): void
    {
        $ini = $this->applyCustomSessionOptions([
            'cache_limiter' => 'nocache',
        ]);

        $persistence = new PhpSessionPersistence();

        $request = $this->createSessionCookieRequest();
        $session = $persistence->initializeSessionFromRequest($request);
        $session->set('foo', __METHOD__);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response = $persistence->persistSession($session, new Response());

        // expected values
        $expires = Http::CACHE_PAST_DATE;
        $control = 'no-store, no-cache, must-revalidate';
        $pragma  = 'no-cache';

        $this->assertSame($expires, $response->getHeaderLine('Expires'));
        $this->assertSame($control, $response->getHeaderLine('Cache-Control'));
        $this->assertSame($pragma, $response->getHeaderLine('Pragma'));

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testPersistSessionReturnsExpectedResponseWithCacheHeadersIfCacheLimiterIsPublic(): void
    {
        $expire = 111;
        $maxAge = 60 * $expire;

        $ini = $this->applyCustomSessionOptions([
            'cache_limiter' => 'public',
            'cache_expire'  => $expire,
        ]);

        $persistence = new PhpSessionPersistence();

        $request = $this->createSessionCookieRequest();
        $session = $persistence->initializeSessionFromRequest($request);
        $session->set('foo', __METHOD__);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response = $persistence->persistSession($session, new Response());

        // expected expire min timestamp value
        $expiresMin = time() + $maxAge;
        // expected expire max timestamp value
        $expiresMax = time() + $maxAge;

        // expected cache-control value
        $control = sprintf('public, max-age=%d', $maxAge);
        // actual expire timestamp value
        $expires = $response->getHeaderLine('Expires');
        $expires = strtotime($expires);

        $this->assertGreaterThanOrEqual($expiresMin, $expires);
        $this->assertLessThanOrEqual($expiresMax, $expires);
        $this->assertSame($control, $response->getHeaderLine('Cache-Control'));

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testPersistSessionReturnsExpectedResponseWithCacheHeadersIfCacheLimiterIsPrivate(): void
    {
        $expire = 222;
        $maxAge = 60 * $expire;

        $ini = $this->applyCustomSessionOptions([
            'cache_limiter' => 'private',
            'cache_expire'  => $expire,
        ]);

        $persistence = new PhpSessionPersistence();

        $request = $this->createSessionCookieRequest();
        $session = $persistence->initializeSessionFromRequest($request);
        $session->set('foo', __METHOD__);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response = $persistence->persistSession($session, new Response());

        // expected values
        $expires = Http::CACHE_PAST_DATE;
        $control = sprintf('private, max-age=%d', $maxAge);

        $this->assertSame($expires, $response->getHeaderLine('Expires'));
        $this->assertSame($control, $response->getHeaderLine('Cache-Control'));

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testPersistSessionReturnsExpectedResponseWithCacheHeadersIfCacheLimiterIsPrivateNoExpire(): void
    {
        $expire = 333;
        $maxAge = 60 * $expire;

        $ini = $this->applyCustomSessionOptions([
            'cache_limiter' => 'private_no_expire',
            'cache_expire'  => $expire,
        ]);

        $persistence = new PhpSessionPersistence();

        $request = $this->createSessionCookieRequest();
        $session = $persistence->initializeSessionFromRequest($request);
        $session->set('foo', __METHOD__);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response = $persistence->persistSession($session, new Response());

        $control = sprintf('private, max-age=%d', $maxAge);

        $this->assertFalse($response->hasHeader('Expires'));
        $this->assertSame('', $response->getHeaderLine('Expires'));
        $this->assertSame($control, $response->getHeaderLine('Cache-Control'));

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testPersistSessionReturnsExpectedResponseWithoutAddedHeadersIfAlreadyHasAny(): void
    {
        $ini = $this->applyCustomSessionOptions([
            'cache_limiter' => 'nocache',
        ]);

        $response = new Response('php://memory', 200, [
            'Last-Modified' => gmdate(Http::DATE_FORMAT),
        ]);

        $persistence = new PhpSessionPersistence();

        $request = $this->createSessionCookieRequest();
        $session = $persistence->initializeSessionFromRequest($request);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response = $persistence->persistSession($session, $response);

        $this->assertFalse($response->hasHeader('Pragma'));
        $this->assertFalse($response->hasHeader('Expires'));
        $this->assertFalse($response->hasHeader('Cache-Control'));

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testPersistSessionInjectsExpectedLastModifiedHeader(): void
    {
        $ini = $this->applyCustomSessionOptions([
            'cache_limiter' => 'public',
        ]);

        $persistence = new PhpSessionPersistence();

        $request = $this->createSessionCookieRequest();
        $session = $persistence->initializeSessionFromRequest($request);
        $session->set('foo', __METHOD__);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response = $persistence->persistSession($session, new Response());

        $lastModified       = $this->getExpectedLastModified();
        $expectedHeaderLine = $lastModified === false ? '' : $lastModified;

        $this->assertSame($expectedHeaderLine, $response->getHeaderLine('Last-Modified'));
        if ($lastModified === false) {
            $this->assertFalse($response->hasHeader('Last-Modified'));
        }

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testPersistSessionReturnsExpectedResponseWithoutAddedCacheHeadersIfEmptyCacheLimiter(): void
    {
        $ini = $this->applyCustomSessionOptions([
            'cache_limiter' => '',
        ]);

        $persistence = new PhpSessionPersistence();

        $request = $this->createSessionCookieRequest();
        $session = $persistence->initializeSessionFromRequest($request);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response = $persistence->persistSession($session, new Response());

        $this->assertFalse($response->hasHeader('Pragma'));
        $this->assertFalse($response->hasHeader('Expires'));
        $this->assertFalse($response->hasHeader('Cache-Control'));

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testPersistSessionReturnsExpectedResponseWithoutAddedCacheHeadersIfUnsupportedCacheLimiter(): void
    {
        $ini = $this->applyCustomSessionOptions([
            'cache_limiter' => 'unsupported',
        ]);

        $persistence = new PhpSessionPersistence();

        $request = $this->createSessionCookieRequest();
        $session = $persistence->initializeSessionFromRequest($request);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response = $persistence->persistSession($session, new Response());

        $this->assertFalse($response->hasHeader('Pragma'));
        $this->assertFalse($response->hasHeader('Expires'));
        $this->assertFalse($response->hasHeader('Cache-Control'));

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testCookiesNotSetWithoutRegenerate(): void
    {
        $persistence = new PhpSessionPersistence();
        $request     = new ServerRequest();
        $session     = $persistence->initializeSessionFromRequest($request);
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        $response = new Response();
        $response = $persistence->persistSession($session, $response);

        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testCookiesSetWithoutRegenerate(): void
    {
        $persistence = new PhpSessionPersistence();
        $request     = new ServerRequest();
        $session     = $persistence->initializeSessionFromRequest($request);
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        $session->set('foo', 'bar');

        $response = new Response();
        $response = $persistence->persistSession($session, $response);

        $this->assertNotEmpty($response->getHeaderLine('Set-Cookie'));
    }

    public static function sameSitePossibleValues(): array
    {
        return [
            ['Strict'],
            ['Lax'],
            ['None'],
            [null],
            [''],
        ];
    }

    #[DataProvider('sameSitePossibleValues')]
    public function testCookieHasSameSite(?string $sameSite): void
    {
        $ini = $this->applyCustomSessionOptions([
            'cookie_samesite' => $sameSite,
        ]);

        $persistence = new PhpSessionPersistence();
        $request     = new ServerRequest();
        $session     = $persistence->initializeSessionFromRequest($request);
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        $session->set('foo', 'bar');

        $response = new Response();
        $response = $persistence->persistSession($session, $response);

        $cookie = $response->getHeaderLine('Set-Cookie');

        if (is_string($sameSite) && $sameSite !== '') {
            self::assertStringContainsStringIgnoringCase('SameSite=' . $sameSite, $cookie);
        } else {
            self::assertStringNotContainsStringIgnoringCase('SameSite=', $cookie);
        }

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testCookiesSetWithDefaultLifetime(): void
    {
        $persistence = new PhpSessionPersistence();
        $request     = new ServerRequest();
        $session     = $persistence->initializeSessionFromRequest($request);
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        $session->set('foo', 'bar');

        $response = $persistence->persistSession($session, new Response());

        $setCookie = FigResponseCookies::get($response, session_name());

        $this->assertNotEmpty($response->getHeaderLine('Set-Cookie'));
        $this->assertInstanceOf(SetCookie::class, $setCookie);
        $this->assertSame(0, $setCookie->getExpires());
    }

    public function testCookiesSetWithCustomLifetime(): void
    {
        $lifetime = 300;

        $ini = $this->applyCustomSessionOptions([
            'cookie_lifetime' => $lifetime,
        ]);

        $persistence = new PhpSessionPersistence();
        $request     = new ServerRequest();
        $session     = $persistence->initializeSessionFromRequest($request);
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        $session->set('foo', 'bar');

        $expiresMin = time() + $lifetime;
        $response   = $persistence->persistSession($session, new Response());
        $expiresMax = time() + $lifetime;

        $setCookie = FigResponseCookies::get($response, session_name());
        $this->assertInstanceOf(SetCookie::class, $setCookie);

        $expires = $setCookie->getExpires();

        $this->assertGreaterThanOrEqual($expiresMin, $expires);
        $this->assertLessThanOrEqual($expiresMax, $expires);

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testAllowsSessionToSpecifyLifetime(): void
    {
        $originalLifetime = ini_get('session.cookie_lifetime');

        $persistence = new PhpSessionPersistence();
        $request     = new ServerRequest();
        $session     = $persistence->initializeSessionFromRequest($request);
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        $lifetime = 300;
        $session->persistSessionFor($lifetime);

        $expiresMin = time() + $lifetime;
        $response   = $persistence->persistSession($session, new Response());
        $expiresMax = time() + $lifetime;

        $setCookie = FigResponseCookies::get($response, session_name());
        $this->assertInstanceOf(SetCookie::class, $setCookie);

        $expires = $setCookie->getExpires();

        $this->assertGreaterThanOrEqual($expiresMin, $expires);
        $this->assertLessThanOrEqual($expiresMax, $expires);

        // reset lifetime
        session_set_cookie_params($originalLifetime);
    }

    public function testAllowsSessionToOverrideDefaultLifetime(): void
    {
        $ini = $this->applyCustomSessionOptions([
            'cookie_lifetime' => 600,
        ]);

        $persistence = new PhpSessionPersistence();
        $request     = new ServerRequest();
        $session     = $persistence->initializeSessionFromRequest($request);
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        $lifetime = 300;
        $session->persistSessionFor($lifetime);

        $expiresMin = time() + $lifetime;
        $response   = $persistence->persistSession($session, new Response());
        $expiresMax = time() + $lifetime;

        $setCookie = FigResponseCookies::get($response, session_name());
        $this->assertInstanceOf(SetCookie::class, $setCookie);

        $expires = $setCookie->getExpires();

        $this->assertGreaterThanOrEqual($expiresMin, $expires);
        $this->assertLessThanOrEqual($expiresMax, $expires);

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testSavedSessionLifetimeOverridesDefaultLifetime(): void
    {
        $ini      = $this->applyCustomSessionOptions([
            'cookie_lifetime' => 600,
        ]);
        $lifetime = 300;

        $persistence = new PhpSessionPersistence();
        $session     = new Session([
            SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY => $lifetime,
            'foo'                                                   => 'bar',
        ], 'abcdef123456');
        $session->set('foo', __METHOD__);

        $expiresMin = time() + $lifetime;
        $response   = $persistence->persistSession($session, new Response());
        $expiresMax = time() + $lifetime;

        $setCookie = FigResponseCookies::get($response, session_name());
        $this->assertInstanceOf(SetCookie::class, $setCookie);

        $expires = $setCookie->getExpires();

        $this->assertGreaterThanOrEqual($expiresMin, $expires);
        $this->assertLessThanOrEqual($expiresMax, $expires);

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testStartSessionDoesNotOverrideRequiredSettings(): void
    {
        $persistence = new PhpSessionPersistence();

        $method = new ReflectionMethod($persistence, 'startSession');

        // try to override required settings
        $method->invokeArgs($persistence, [
            'my-session-id',
            [
                'use_cookies'      => true, // FALSE is required
                'use_only_cookies' => false, // TRUE is required
                'cache_limiter'    => 'nocache', // '' is required
            ],
        ]);

        $filter = FILTER_VALIDATE_BOOLEAN;
        $flags  = FILTER_NULL_ON_FAILURE;

        $sessionUseCookies     = filter_var(ini_get('session.use_cookies'), $filter, $flags);
        $sessionUseOnlyCookies = filter_var(ini_get('session.use_only_cookies'), $filter, $flags);
        $sessionCacheLimiter   = ini_get('session.cache_limiter');

        $this->assertFalse($sessionUseCookies);
        $this->assertTrue($sessionUseOnlyCookies);
        $this->assertSame('', $sessionCacheLimiter);
    }

    public function testNoMultipleEmptySessionFilesAreCreatedIfNoSessionCookiePresent(): void
    {
        $sessionName = 'NOSESSIONCOOKIESESSID';
        $ini         = $this->applyCustomSessionOptions([
            'name' => $sessionName,
        ]);

        $persistence = new PhpSessionPersistence();

        // initial sessioncookie-less request
        $request = new ServerRequest();

        for ($i = 0; $i < 3; ++$i) {
            $session = $persistence->initializeSessionFromRequest($request);

            $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
            $response = $persistence->persistSession($session, new Response());

            // new request: start w/o session cookie
            $request = new ServerRequest();

            // Add the latest response session cookie value to the new request, if any
            $setCookies = SetCookies::fromResponse($response);
            if ($setCookies->has($sessionName)) {
                $setCookie = $setCookies->get($sessionName);
            }
            if (isset($setCookie)) {
                $cookie  = new Cookie($sessionName, $setCookie->getValue());
                $request = FigRequestCookies::set($request, $cookie);
            }
        }

        $this->assertPersistedSessionsCount(0);

        $this->restoreOriginalSessionIniSettings($ini);
    }

    public function testOnlyOneSessionFileIsCreatedIfNoSessionCookiePresentInFirstRequestButSessionDataChanged(): void
    {
        $sessionName = 'NOSESSIONCOOKIESESSID';
        $ini         = $this->applyCustomSessionOptions([
            'name' => $sessionName,
        ]);

        $persistence = new PhpSessionPersistence();

        // initial sessioncookie-less request
        $request = new ServerRequest();

        for ($i = 0; $i < 3; ++$i) {
            $session = $persistence->initializeSessionFromRequest($request);
            $session->set('foo' . $i, 'bar' . $i);

            $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
            $response = $persistence->persistSession($session, new Response());

            // new request: start w/o session cookie
            $request = new ServerRequest();

            // Add the latest response session cookie value to the new request, if any
            $setCookies = SetCookies::fromResponse($response);
            if ($setCookies->has($sessionName)) {
                $setCookie = $setCookies->get($sessionName);
            }
            if (isset($setCookie)) {
                $cookie  = new Cookie($sessionName, $setCookie->getValue());
                $request = FigRequestCookies::set($request, $cookie);
            }
        }

        $this->assertPersistedSessionsCount(1);

        $this->restoreOriginalSessionIniSettings($ini);
    }

    #[DataProvider('cookieSettingsProvider')]
    public function testThatSetCookieCorrectlyInterpretsIniSettings(
        string|int|bool $secureIni,
        string|int|bool $httpOnlyIni,
        bool $expectedSecure,
        bool $expectedHttpOnly
    ): void {
        $ini = $this->applyCustomSessionOptions([
            'name'            => 'SETCOOKIESESSIONID',
            'cookie_secure'   => $secureIni,
            'cookie_httponly' => $httpOnlyIni,
        ]);

        $persistence = new PhpSessionPersistence();

        $createSessionCookieForResponse = new ReflectionMethod($persistence, 'createSessionCookieForResponse');

        $setCookie = $createSessionCookieForResponse->invokeArgs(
            $persistence,
            ['set-cookie-test-value']
        );

        $this->assertSame($expectedSecure, $setCookie->getSecure());
        $this->assertSame($expectedHttpOnly, $setCookie->getHttpOnly());

        $this->restoreOriginalSessionIniSettings($ini);
    }

    /**
     * @psalm-return array<string, array{0: bool|int|string, 1: bool|int|string, 2: bool, 3: bool}>
     */
    public static function cookieSettingsProvider(): array
    {
        // @codingStandardsIgnoreStart
        // phpcs:disable
        return [
            // Each case has:
            // - session.cookie_secure INI flag value
            // - session.cookie_httponly INI flag value
            // - expected value for session.cookie_secure after registration
            // - expected value for session.cookie_httponly after registration
            'boolean-false-false' => [false, false, false, false],
            'int-zero-false'      => [    0,     0, false, false],
            'string-zero-false'   => [  '0',   '0', false, false],
            'string-empty-false'  => [   '',    '', false, false],
            'string-off-false'    => ['off', 'off', false, false],
            'string-Off-false'    => ['Off', 'Off', false, false],
            'boolean-true-true'   => [ true,  true,  true,  true],
            'int-one-true'        => [    1,     1,  true,  true],
            'string-one-true'     => [   '1',  '1',  true,  true],
            'string-on-true'      => [  'on',  'on', true,  true],
            'string-On-true'      => [  'On',  'On', true,  true],
        ];
        // phpcs:enable
        // @codingStandardsIgnoreEnd
    }

    public function testHeadersAreNotSentIfReloadedSessionDidNotChange(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status());

        $request = $this->createSessionCookieRequest('reloaded-session');
        $session = $this->persistence->initializeSessionFromRequest($request);

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertInstanceOf(Session::class, $session);
        $this->assertTrue(isset($_SESSION));
        $this->assertSame($_SESSION, $session->toArray());
        $this->assertSame('reloaded-session', session_id());

        $response         = new Response();
        $returnedResponse = $this->persistence->persistSession($session, $response);

        $this->assertSame($returnedResponse, $response, 'returned response should have no cookie and no cache headers');
        $this->assertEmpty($response->getHeaders());
    }

    public function testNonLockingSessionIsClosedAfterInitialize(): void
    {
        $session     = [
            'persistence' => [
                'ext' => [
                    'non_locking' => true,
                ],
            ],
        ];
        $persistence = PhpSessionPersistence::fromConfigArray($session);

        $request = $this->createSessionCookieRequest('non-locking-session-id');
        $session = $persistence->initializeSessionFromRequest($request);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $persistence->persistSession($session, new Response());
    }

    public function testSessionIsOpenAfterInitializeWithFalseNonLockingSetting(): void
    {
        $session     = [
            'persistence' => [
                'ext' => [
                    'non_locking' => false,
                ],
            ],
        ];
        $persistence = PhpSessionPersistence::fromConfigArray($session);

        $request = $this->createSessionCookieRequest('locking-session-id');
        $session = $persistence->initializeSessionFromRequest($request);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $persistence->persistSession($session, new Response());
    }

    public function testNonLockingSessionDataIsPersisted(): void
    {
        $sid = 'non-locking-session-id';

        $name  = 'non-locking-foo';
        $value = 'non-locking-bar';

        $session     = [
            'persistence' => [
                'ext' => [
                    'non_locking' => true,
                ],
            ],
        ];
        $persistence = PhpSessionPersistence::fromConfigArray($session);

        $request = $this->createSessionCookieRequest($sid);
        $session = $persistence->initializeSessionFromRequest($request);
        $session->set($name, $value);

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $persistence->persistSession($session, new Response());

        $_SESSION = null;

        // reopens the session file and check the contents
        session_id($sid);
        session_start();
        $this->assertArrayHasKey($name, $_SESSION);
        $this->assertSame($value, $_SESSION[$name]);
        session_write_close();
    }

    public function testNonLockingRegeneratedSessionIsPersisted(): void
    {
        $sid = 'non-locking-session-id';

        $name  = 'regenerated-non-locking-foo';
        $value = 'regenerated-non-locking-bar';

        $session     = [
            'persistence' => [
                'ext' => [
                    'non_locking' => true,
                ],
            ],
        ];
        $persistence = PhpSessionPersistence::fromConfigArray($session);

        $request = $this->createSessionCookieRequest($sid);
        $session = $persistence->initializeSessionFromRequest($request);
        $session->set($name, $value);
        $session = $session->regenerate();

        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response = $persistence->persistSession($session, new Response());

        // get the regenerated session id from the response session cookie
        $setCookie     = FigResponseCookies::get($response, session_name());
        $regeneratedId = $setCookie->getValue();

        $_SESSION = null;

        // reopens the session file and check the contents
        session_id($regeneratedId);
        session_start();
        //phpcs:ignore SlevomatCodingStandard.Commenting.InlineDocCommentDeclaration.MissingVariable
        /**
         * Psalm doesn't know what $_SESSION is at this point
         *
         * @var array<string, mixed> $_SESSION
         */
        $this->assertArrayHasKey($name, $_SESSION);
        $this->assertSame($value, $_SESSION[$name]);
        session_write_close();
    }

    public function testInitializeIdReturnsSessionWithId(): void
    {
        $persistence = new PhpSessionPersistence();
        $session     = new Session(['foo' => 'bar']);
        $actual      = $persistence->initializeId($session);

        $this->assertNotSame($session, $actual);
        $this->assertNotEmpty($actual->getId());
        $this->assertSame(session_id(), $actual->getId());
        $this->assertSame(['foo' => 'bar'], $actual->toArray());
    }

    public function testInitializeIdRegeneratesSessionId(): void
    {
        $persistence = new PhpSessionPersistence();
        $session     = new Session(['foo' => 'bar'], 'original-id');
        $session     = $session->regenerate();
        $actual      = $persistence->initializeId($session);

        $this->assertNotEmpty($actual->getId());
        $this->assertNotSame('original-id', $actual->getId());
        $this->assertFalse($actual->isRegenerated());
    }

    public function testRegenerateWhenSessionAlreadyActiveDestroyExistingSessionFirst(): void
    {
        session_start();

        $path = session_save_path() !== false
            ? session_save_path()
            : sys_get_temp_dir();

        $_SESSION['test'] = 'value';
        $fileSession      = $path . '/sess_' . session_id();

        $this->assertFileExists($fileSession);

        $persistence = new PhpSessionPersistence();
        $session     = new Session(['foo' => 'bar']);
        $session     = $session->regenerate();
        $this->assertInstanceOf(SessionIdentifierAwareInterface::class, $session);

        $persistence->persistSession($session, new Response());

        $this->assertFileDoesNotExist($fileSession);
    }

    public function testCookieIsDeletedFromBrowserIfSessionBecomesEmpty(): void
    {
        $session     = [
            'persistence' => [
                'ext' => [
                    'non_locking'                    => false,
                    'delete_cookie_on_empty_session' => true,
                ],
            ],
        ];
        $persistence = PhpSessionPersistence::fromConfigArray($session);
        $session     = new Session(['foo' => 'bar']);
        $session->clear();
        $response = $persistence->persistSession($session, new Response());

        $cookie   = $response->getHeaderLine('Set-Cookie');
        $expected = 'Expires=Thu, 01 Jan 1970 00:00:01 GMT';
        $this->assertStringContainsString($expected, $cookie, 'cookie should bet set to expire in the past');
    }

    public function testInitializeIdReturnsSessionUnaltered(): void
    {
        $persistence = new PhpSessionPersistence();
        $session     = new Session(['foo' => 'bar'], 'original-id');
        $actual      = $persistence->initializeId($session);

        $this->assertSame($session, $actual);
    }

    /**
     * @return string|false
     */
    private function getExpectedLastModified()
    {
        $lastmod = getlastmod();
        if ($lastmod === false) {
            $rc        = new ReflectionClass(CacheHeadersGeneratorTrait::class);
            $classFile = $rc->getFileName();
            $lastmod   = filemtime($classFile);
        }

        return $lastmod !== false ? gmdate(Http::DATE_FORMAT, $lastmod) : false;
    }
}
