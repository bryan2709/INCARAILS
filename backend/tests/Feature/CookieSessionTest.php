<?php

namespace Tests\Feature;

use App\Http\Middleware\ProtectCookieRequests;
use App\Models\Role;
use App\Models\User;
use DateInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Mockery;
use Tests\TestCase;

class CookieSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->seed();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key'], 'session.secure' => null]);
        app(ClientRepository::class)->createPersonalAccessGrantClient('Cookie test', 'users');
        $this->person = User::create(['name' => 'Cookie user', 'email' => 'cookie@test.local', 'password' => 'password-demo-123', 'is_active' => true]);
        $this->person->roles()->attach(Role::where('code', 'readonly')->firstOrFail());
        $this->withCredentials();
        $middleware = Mockery::mock(ProtectCookieRequests::class, [$this->app, $this->app['encrypter']])->makePartial();
        $middleware->shouldAllowMockingProtectedMethods()->shouldReceive('runningUnitTests')->andReturn(false);
        $this->app->instance(ProtectCookieRequests::class, $middleware);
    }

    private function send(string $method, string $path, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Once::instance()->flush();
        $response = $this->json($method, $path, $data, $headers);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
                unset($this->defaultCookies[$cookie->getName()]);
            } else {
                $this->defaultCookies[$cookie->getName()] = $response->getCookie($cookie->getName())->getValue();
            }
        }

        return $response;
    }

    private function login(): TestResponse
    {
        $this->send('GET', '/api/auth/csrf-cookie')->assertOk();

        return $this->send('POST', '/api/auth/login', ['email' => $this->person->email, 'password' => 'password-demo-123'], $this->csrfHeaders())->assertOk();
    }

    private function csrfHeaders(): array
    {
        return ['X-CSRF-TOKEN' => $this->defaultCookies['XSRF-TOKEN']];
    }

    public function test_login_uses_an_encrypted_http_only_session_cookie_and_restores_via_passport(): void
    {
        $csrf = $this->send('GET', '/api/auth/csrf-cookie')->assertOk();
        $oldSession = $this->defaultCookies['incarail_session'];
        $response = $this->send('POST', '/api/auth/login', ['email' => $this->person->email, 'password' => 'password-demo-123'], $this->csrfHeaders())
            ->assertOk()->assertJsonPath('data.expires_in', 7200)->assertJsonMissingPath('data.access_token');
        $cookie = $response->getCookie('incarail_access_token', false);
        $this->assertSame(0, $cookie->getExpiresTime());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertFalse($cookie->isSecure());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertNotSame($this->defaultCookies['incarail_access_token'], $cookie->getValue());
        $this->assertNotSame($oldSession, $this->defaultCookies['incarail_session']);
        $this->assertSame(0, $csrf->getCookie('incarail_session')->getExpiresTime());
        $this->assertSame(0, $response->getCookie('XSRF-TOKEN')->getExpiresTime());
        $this->assertFalse($response->getCookie('XSRF-TOKEN')->isHttpOnly());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseHas('oauth_access_tokens', ['user_id' => $this->person->id, 'revoked' => false]);
        $this->send('GET', '/api/auth/me')->assertOk()->assertJsonPath('data.id', $this->person->id)->assertCookieMissing('incarail_access_token');
        $this->send('GET', '/api/team')->assertOk();
        Http::assertNothingSent();
    }

    public function test_login_requires_csrf_even_with_a_same_origin_header(): void
    {
        $this->send('POST', '/api/auth/login', ['email' => $this->person->email, 'password' => 'password-demo-123'], ['Sec-Fetch-Site' => 'same-origin'])
            ->assertStatus(419)->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH')->assertCookieMissing('incarail_access_token');
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_cookie_mutations_require_matching_csrf_and_accept_the_encrypted_axios_header(): void
    {
        $response = $this->login();
        $headers = ['X-XSRF-TOKEN' => $response->getCookie('XSRF-TOKEN', false)->getValue()];
        foreach ([[], ['Sec-Fetch-Site' => 'same-origin'], ['X-XSRF-TOKEN' => 'invalid']] as $invalid) {
            $this->send('POST', '/api/auth/logout', [], $invalid)->assertStatus(419)->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
            $this->assertDatabaseHas('oauth_access_tokens', ['revoked' => false]);
        }
        $this->send('POST', '/api/auth/logout', [], $headers)->assertOk()->assertCookieExpired('incarail_access_token');
        $this->assertDatabaseHas('oauth_access_tokens', ['revoked' => true]);
    }

    public function test_logout_revokes_the_token_and_rejects_a_replayed_cookie(): void
    {
        $this->login();
        $token = $this->defaultCookies['incarail_access_token'];
        $this->send('POST', '/api/auth/logout', [], $this->csrfHeaders())->assertOk()->assertCookieExpired('incarail_access_token');
        $this->assertArrayNotHasKey('incarail_access_token', $this->defaultCookies);
        $this->send('GET', '/api/auth/me')->assertUnauthorized();
        $this->defaultCookies['incarail_access_token'] = $token;
        $this->send('GET', '/api/auth/me')->assertUnauthorized()->assertCookieExpired('incarail_access_token');
    }

    public function test_tampered_token_is_rejected_and_the_cookie_is_cleared(): void
    {
        $this->login();
        $this->defaultCookies['incarail_access_token'] = 'invalid-jwt';
        $this->send('GET', '/api/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED')->assertCookieExpired('incarail_access_token');
    }

    public function test_tampered_encrypted_cookie_is_rejected(): void
    {
        $this->login();
        $this->unencryptedCookies['incarail_access_token'] = 'tampered-ciphertext';
        $this->send('GET', '/api/auth/me')->assertUnauthorized()->assertCookieExpired('incarail_access_token');
    }

    public function test_expired_passport_token_cannot_restore_the_cookie_session(): void
    {
        Passport::personalAccessTokensExpireIn(new DateInterval('PT0S'));
        $this->login();
        $this->send('GET', '/api/auth/me')->assertUnauthorized()->assertCookieExpired('incarail_access_token');
    }

    public function test_cookie_auth_preserves_acl_and_active_user_checks_and_allows_inactive_logout(): void
    {
        $this->login();
        $this->send('POST', '/api/team/8772/refresh', [], $this->csrfHeaders())->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseCount('imports', 0);
        $this->person->update(['is_active' => false]);
        $this->send('GET', '/api/auth/me')->assertForbidden();
        $this->send('POST', '/api/auth/logout', [], $this->csrfHeaders())->assertOk()->assertCookieExpired('incarail_access_token');
        $this->assertDatabaseHas('oauth_access_tokens', ['revoked' => true]);
    }

    public function test_https_sets_secure_on_the_authentication_cookie(): void
    {
        $this->send('GET', 'https://localhost/api/auth/csrf-cookie')->assertOk();
        $response = $this->send('POST', 'https://localhost/api/auth/login', ['email' => $this->person->email, 'password' => 'password-demo-123'], $this->csrfHeaders())->assertOk();
        $this->assertTrue($response->getCookie('incarail_access_token')->isSecure());
    }

    public function test_explicit_bearer_clients_remain_valid_without_cookie_csrf(): void
    {
        $token = $this->person->createToken('API test')->accessToken;
        $this->send('GET', '/api/auth/me', [], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->send('POST', '/api/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->send('GET', '/api/auth/me', [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_a_new_login_revokes_the_previous_cookie_token(): void
    {
        $this->login();
        $previous = $this->defaultCookies['incarail_access_token'];
        $this->login();
        $this->assertDatabaseCount('oauth_access_tokens', 2);
        $this->assertSame(1, Passport::token()->where('revoked', true)->count());
        $this->send('GET', '/api/auth/me')->assertOk();
        $this->defaultCookies['incarail_access_token'] = $previous;
        $this->send('GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_revoked_oauth_client_cannot_authenticate_with_a_cookie(): void
    {
        $this->login();
        Passport::client()->query()->update(['revoked' => true]);
        $this->send('GET', '/api/auth/me')->assertUnauthorized();
    }
}
