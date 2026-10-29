<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Tracking\Consent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 16 hardening: baseline security headers, configurable trusted
 * proxies / HSTS, login throttling per IP, login timing, and session
 * handling on login and logout.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function assertBaselineHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    // --- Security headers --------------------------------------------------------------------------

    public function test_storefront_login_and_admin_responses_carry_the_baseline_headers(): void
    {
        foreach ([$this->get('/'), $this->get(route('login')), $this->get('/cart'), $this->get('/privacy')] as $response) {
            $this->assertBaselineHeaders($response->assertOk());
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        $admin = $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))->assertOk();
        $this->assertBaselineHeaders($admin);

        $this->assertBaselineHeaders($this->get(route('admin.orders.index')));
        $this->assertBaselineHeaders($this->postJson(route('webhooks.steadfast'), []));
    }

    public function test_redirects_and_errors_are_covered_too(): void
    {
        auth()->logout();

        $this->assertBaselineHeaders($this->get(route('admin.dashboard'))->assertRedirect(route('login')));
        $this->assertBaselineHeaders($this->get('/products/does-not-exist')->assertNotFound());
    }

    public function test_search_pages_keep_their_stricter_referrer_policy(): void
    {
        $this->get('/?q=01712345678')->assertOk()->assertHeader('Referrer-Policy', 'origin');
    }

    public function test_the_csp_only_restricts_framing_so_livewire_vite_and_gtm_keep_working(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertSame("frame-ancestors 'self'", $csp);
        foreach (['script-src', 'default-src', 'connect-src', 'style-src'] as $directive) {
            $this->assertStringNotContainsString($directive, $csp);
        }
    }

    /**
     * @return array<string, array{int, string, bool}>
     */
    public static function hstsCases(): array
    {
        return [
            'off by default, https' => [0, 'https://localhost/', false],
            'enabled, https' => [31536000, 'https://localhost/', true],
            'enabled, plain http' => [31536000, 'http://localhost/', false],
        ];
    }

    #[DataProvider('hstsCases')]
    public function test_hsts_is_sent_only_when_enabled_and_over_https(int $maxAge, string $url, bool $expected): void
    {
        config(['security.hsts_max_age' => $maxAge]);

        $response = $this->get($url)->assertOk();

        $expected
            ? $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            : $response->assertHeaderMissing('Strict-Transport-Security');
    }

    // --- Trusted proxies -----------------------------------------------------------------------------

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function proxyCases(): array
    {
        return [
            'no proxy trusted (default)' => [null, false],
            'any proxy trusted' => ['*', true],
            'this proxy listed' => ['10.0.0.5, 192.168.1.0/24', true],
            'another proxy listed' => ['10.9.9.9', false],
        ];
    }

    #[DataProvider('proxyCases')]
    public function test_forwarded_https_is_only_believed_from_trusted_proxies(?string $trusted, bool $secure): void
    {
        config(['security.trusted_proxies' => $trusted, 'security.hsts_max_age' => 600]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->post('http://localhost/privacy/consent', ['decision' => 'reject']);

        $this->assertSame($secure, $response->getCookie(Consent::COOKIE, decrypt: false)->isSecure(), 'Cookies follow the real scheme.');
        $secure ? $response->assertHeader('Strict-Transport-Security') : $response->assertHeaderMissing('Strict-Transport-Security');
    }

    // --- Login ----------------------------------------------------------------------------------------

    public function test_one_ip_cannot_try_many_accounts_quickly(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->post(route('login'), ['email' => "user{$attempt}@example.test", 'password' => 'wrong-password'])->assertRedirect();
        }

        $this->post(route('login'), ['email' => 'user21@example.test', 'password' => 'wrong-password'])->assertTooManyRequests();

        RateLimiter::clear('login-ip:127.0.0.1');
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])
            ->post(route('login'), ['email' => 'user22@example.test', 'password' => 'wrong-password'])
            ->assertRedirect();
    }

    public function test_unknown_emails_still_run_a_password_hash_check(): void
    {
        Hash::spy();

        $this->post(route('login'), ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');

        Hash::shouldHaveReceived('check')->once();
        $this->assertGuest();
    }

    public function test_correct_and_incorrect_logins_still_behave_as_before(): void
    {
        $user = User::factory()->admin()->create(['password' => 'Correct-Horse-42']);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'Correct-Horse-42'])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    // --- Sessions -------------------------------------------------------------------------------------

    public function test_the_session_id_changes_on_login_and_the_session_is_invalidated_on_logout(): void
    {
        $user = User::factory()->admin()->create(['password' => 'Correct-Horse-42']);

        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'Correct-Horse-42']);
        $afterLogin = session()->getId();
        $this->assertNotSame($before, $afterLogin, 'Session fixation: the id is regenerated on login.');

        session()->put('marker', 'secret');
        $this->post(route('logout'))->assertRedirect();

        $this->assertGuest();
        $this->assertNotSame($afterLogin, session()->getId(), 'The session id changes on logout.');
        $this->assertNull(session('marker'), 'Session data is discarded on logout.');
    }

    public function test_logout_cannot_be_triggered_by_a_get_request(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/logout')->assertStatus(405);
        $this->assertAuthenticated();
    }

    public function test_session_cookies_are_http_only_and_same_site_lax(): void
    {
        $cookie = $this->get(route('login'))->getCookie(config('session.cookie'), decrypt: false);

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }
}
