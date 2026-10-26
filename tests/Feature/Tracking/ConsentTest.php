<?php

namespace Tests\Feature\Tracking;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Tracking\Consent;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Storefront tracking consent: Analytics and Marketing are denied until the
 * shopper explicitly opts in; the choice lives only in an encrypted,
 * versioned cookie, and the GTM consent defaults follow it.
 */
class ConsentTest extends TestCase
{
    use InteractsWithDataLayer, RefreshDatabase;

    private const DENIED = ['analytics_storage' => 'denied', 'ad_storage' => 'denied', 'ad_user_data' => 'denied', 'ad_personalization' => 'denied'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGtm();
        $category = Category::factory()->create(['slug' => 'panjabi']);
        Product::factory()->for($category)->withStock(5)->create(['name' => 'Cotton Panjabi', 'slug' => 'cotton-panjabi']);
    }

    /**
     * @return array<string, string>
     */
    private function consentDefaults(TestResponse $response): array
    {
        preg_match("/gtag\\('consent', 'default', JSON\\.parse\\('([^']*)'\\)\\);/", (string) $response->getContent(), $match);
        $this->assertNotEmpty($match, 'Consent defaults are rendered.');

        return json_decode(json_decode('"'.$match[1].'"'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function adsDataRedaction(TestResponse $response): ?string
    {
        preg_match("/gtag\\('set', 'ads_data_redaction', (true|false)\\);/", (string) $response->getContent(), $match);

        return $match[1] ?? null;
    }

    private function storedValue(bool $analytics, bool $marketing, ?int $version = null, ?int $decidedAt = null): string
    {
        return json_encode(['v' => $version ?? config('storefront.consent.version'), 'a' => $analytics, 'm' => $marketing, 't' => $decidedAt ?? now()->getTimestamp()]);
    }

    /**
     * @return array{v: int, a: bool, m: bool, t: int}
     */
    private function savedConsent(TestResponse $response): array
    {
        $cookie = $response->getCookie(Consent::COOKIE);
        $this->assertNotNull($cookie, 'A consent cookie was set.');

        return json_decode($cookie->getValue(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function bannerShown(TestResponse $response): bool
    {
        return str_contains((string) $response->getContent(), 'aria-labelledby="consent-banner-title"');
    }

    // --- Defaults ---------------------------------------------------------------------------------

    public function test_without_a_choice_everything_is_denied_before_the_container_loads(): void
    {
        $response = $this->get(route('storefront.home'))->assertOk();
        $html = (string) $response->getContent();

        $this->assertSame(self::DENIED, $this->consentDefaults($response));
        $this->assertSame('true', $this->adsDataRedaction($response));
        $this->assertLessThan(strpos($html, 'page_location'), strpos($html, "gtag('consent', 'default'"), 'Consent defaults precede the page context.');
        $this->assertLessThan(strpos($html, 'gtm.js'), strpos($html, "gtag('consent', 'default'"), 'Consent defaults precede the container.');
        $this->assertTrue($this->bannerShown($response));
        $this->assertStringNotContainsString(' checked', strstr($html, 'aria-labelledby="consent-banner-title"'), 'No optional category is pre-ticked.');
    }

    /**
     * @return array<string, array{bool, bool, array<string, string>, string}>
     */
    public static function storedChoices(): array
    {
        return [
            'analytics only' => [true, false, ['analytics_storage' => 'granted', 'ad_storage' => 'denied', 'ad_user_data' => 'denied', 'ad_personalization' => 'denied'], 'true'],
            'marketing only' => [false, true, ['analytics_storage' => 'denied', 'ad_storage' => 'granted', 'ad_user_data' => 'granted', 'ad_personalization' => 'denied'], 'false'],
            'both' => [true, true, ['analytics_storage' => 'granted', 'ad_storage' => 'granted', 'ad_user_data' => 'granted', 'ad_personalization' => 'denied'], 'false'],
            'neither (rejected)' => [false, false, self::DENIED, 'true'],
        ];
    }

    #[DataProvider('storedChoices')]
    public function test_gtm_consent_defaults_follow_the_stored_choice(bool $analytics, bool $marketing, array $expected, string $redaction): void
    {
        $response = $this->withCookie(Consent::COOKIE, $this->storedValue($analytics, $marketing))->get(route('storefront.home'))->assertOk();

        $this->assertSame($expected, $this->consentDefaults($response));
        $this->assertSame($redaction, $this->adsDataRedaction($response));
        $this->assertFalse($this->bannerShown($response), 'A valid choice hides the banner.');
    }

    /**
     * @return array<string, array{\Closure(): string, bool}>
     */
    public static function invalidCookies(): array
    {
        return [
            'not JSON' => [fn () => 'yes please', true],
            'empty object' => [fn () => '{}', true],
            'missing key' => [fn () => json_encode(['v' => 1, 'a' => true, 't' => time()]), true],
            'extra key' => [fn () => json_encode(['v' => 1, 'a' => true, 'm' => true, 't' => time(), 'x' => 1]), true],
            'string booleans' => [fn () => json_encode(['v' => 1, 'a' => 'true', 'm' => 'true', 't' => time()]), true],
            'numeric booleans' => [fn () => json_encode(['v' => 1, 'a' => 1, 'm' => 1, 't' => time()]), true],
            'outdated version' => [fn () => json_encode(['v' => 0, 'a' => true, 'm' => true, 't' => time()]), true],
            'unknown future version' => [fn () => json_encode(['v' => 2, 'a' => true, 'm' => true, 't' => time()]), true],
            'expired' => [fn () => json_encode(['v' => 1, 'a' => true, 'm' => true, 't' => now()->subDays(181)->getTimestamp()]), true],
            'decided in the future' => [fn () => json_encode(['v' => 1, 'a' => true, 'm' => true, 't' => now()->addDay()->getTimestamp()]), true],
            'oversized' => [fn () => str_repeat('a', 500), true],
            'valid JSON but not encrypted' => [fn () => json_encode(['v' => 1, 'a' => true, 'm' => true, 't' => time()]), false],
        ];
    }

    #[DataProvider('invalidCookies')]
    public function test_missing_malformed_expired_or_outdated_cookies_are_never_permission(\Closure $value, bool $encrypted): void
    {
        $request = $encrypted ? $this->withCookie(Consent::COOKIE, $value()) : $this->withUnencryptedCookie(Consent::COOKIE, $value());
        $response = $request->get(route('storefront.home'))->assertOk();

        $this->assertSame(self::DENIED, $this->consentDefaults($response));
        $this->assertTrue($this->bannerShown($response), 'The shopper is asked again.');
    }

    public function test_raising_the_consent_version_asks_again(): void
    {
        $granted = $this->storedValue(true, true);
        $this->assertSame('granted', $this->consentDefaults($this->withCookie(Consent::COOKIE, $granted)->get('/'))['analytics_storage']);

        config(['storefront.consent.version' => 2]);
        $response = $this->withCookie(Consent::COOKIE, $granted)->get('/');

        $this->assertSame(self::DENIED, $this->consentDefaults($response));
        $this->assertTrue($this->bannerShown($response));
        $this->assertSame(2, $this->savedConsent($this->post(route('storefront.privacy.consent'), ['decision' => 'reject']))['v']);
    }

    // --- Saving a choice ----------------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, string>, bool, bool}>
     */
    public static function decisions(): array
    {
        return [
            'accept all' => [['decision' => 'accept'], true, true],
            'reject all' => [['decision' => 'reject'], false, false],
            'accept ignores unticked boxes' => [['decision' => 'accept', 'analytics' => '1'], true, true],
            'reject ignores ticked boxes' => [['decision' => 'reject', 'analytics' => '1', 'marketing' => '1'], false, false],
            'save analytics only' => [['decision' => 'save', 'analytics' => '1'], true, false],
            'save marketing only' => [['decision' => 'save', 'marketing' => '1'], false, true],
            'save both' => [['decision' => 'save', 'analytics' => '1', 'marketing' => '1'], true, true],
            'save nothing ticked' => [['decision' => 'save'], false, false],
        ];
    }

    #[DataProvider('decisions')]
    public function test_choices_are_saved_in_the_cookie(array $form, bool $analytics, bool $marketing): void
    {
        $response = $this->post(route('storefront.privacy.consent'), $form + ['return_to' => '/products/cotton-panjabi'])
            ->assertRedirect(url('/products/cotton-panjabi'))
            ->assertSessionHas('success', 'Your privacy choices were saved.');

        $saved = $this->savedConsent($response);
        $this->assertSame([config('storefront.consent.version'), $analytics, $marketing], [$saved['v'], $saved['a'], $saved['m']]);
        $this->assertEqualsWithDelta(now()->getTimestamp(), $saved['t'], 5);

        $next = $this->withCookie(Consent::COOKIE, json_encode($saved))->get('/');
        $this->assertSame($analytics ? 'granted' : 'denied', $this->consentDefaults($next)['analytics_storage']);
        $this->assertSame($marketing ? 'granted' : 'denied', $this->consentDefaults($next)['ad_storage']);
        $this->assertSame('denied', $this->consentDefaults($next)['ad_personalization'], 'Ad personalization is never granted.');
    }

    public function test_the_cookie_is_encrypted_http_only_same_site_lax_and_expires_with_the_lifetime(): void
    {
        $response = $this->post(route('storefront.privacy.consent'), ['decision' => 'accept']);

        /** @var Cookie $raw */
        $raw = $response->getCookie(Consent::COOKIE, decrypt: false);
        $this->assertStringNotContainsString('"a"', $raw->getValue(), 'Stored encrypted.');
        $this->assertTrue($raw->isHttpOnly());
        $this->assertSame('lax', $raw->getSameSite());
        $this->assertSame('/', $raw->getPath());
        $this->assertFalse($raw->isSecure(), 'Plain-HTTP development keeps working.');
        $this->assertEqualsWithDelta(now()->addDays(180)->getTimestamp(), $raw->getExpiresTime(), 60);

        $secure = $this->post('https://localhost/privacy/consent', ['decision' => 'accept'])->getCookie(Consent::COOKIE, decrypt: false);
        $this->assertTrue($secure->isSecure(), 'Secure over HTTPS.');

        config(['session.secure' => true, 'storefront.consent.lifetime_days' => 30]);
        $configured = $this->post(route('storefront.privacy.consent'), ['decision' => 'accept'])->getCookie(Consent::COOKIE, decrypt: false);
        $this->assertTrue($configured->isSecure(), 'SESSION_SECURE_COOKIE is respected.');
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $configured->getExpiresTime(), 60);
    }

    // --- Withdrawal ----------------------------------------------------------------------------------

    public function test_withdrawing_expires_the_tracking_cookies_in_this_browser(): void
    {
        $response = $this->withCookie(Consent::COOKIE, $this->storedValue(true, true))
            ->withUnencryptedCookies(['_ga' => 'GA1.1.123.456', '_ga_ABC123' => 'GS1.1.x', '_fbp' => 'fb.1.123', '_fbc' => 'fb.1.456', 'other_cookie' => 'keep'])
            ->post('http://www.shop.test/privacy/consent', ['decision' => 'reject']);

        $cleared = collect($response->headers->getCookies())->filter(fn (Cookie $cookie) => $cookie->isCleared());
        $this->assertEqualsCanonicalizing(['_ga', '_ga_ABC123', '_fbp', '_fbc'], $cleared->map->getName()->unique()->values()->all());
        $this->assertEqualsCanonicalizing([null, '.www.shop.test', '.shop.test'], $cleared->where(fn (Cookie $cookie) => $cookie->getName() === '_ga')->map->getDomain()->values()->all());
        $this->assertSame(['v' => 1, 'a' => false, 'm' => false], array_slice($this->savedConsent($response), 0, 3));
    }

    public function test_withdrawing_one_category_keeps_the_other(): void
    {
        $response = $this->withCookie(Consent::COOKIE, $this->storedValue(true, true))
            ->withUnencryptedCookies(['_ga' => 'GA1.1.123.456', '_fbp' => 'fb.1.123'])
            ->post(route('storefront.privacy.consent'), ['decision' => 'save', 'analytics' => '1']);

        $cleared = collect($response->headers->getCookies())->filter(fn (Cookie $cookie) => $cookie->isCleared())->map->getName()->unique()->values()->all();
        $this->assertSame(['_fbp'], $cleared);
    }

    public function test_the_settings_page_shows_the_current_choice_and_explains_withdrawal(): void
    {
        $this->get(route('storefront.privacy.settings'))->assertOk()
            ->assertSee('You have not made a choice yet, so analytics and marketing are')
            ->assertDontSee('aria-labelledby="consent-banner-title"', false);

        $this->withCookie(Consent::COOKIE, $this->storedValue(true, false))->get(route('storefront.privacy.settings'))->assertOk()
            ->assertSeeInOrder(['Analytics:', 'on', 'Marketing:', 'off'])
            ->assertSee('id="consent-settings-analytics" type="checkbox" name="analytics" value="1" class="mt-1" checked', false)
            ->assertSee('Removing cookies does not delete information that Google or Meta have already received');
    }

    // --- Validation, CSRF and return paths -------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSubmissions(): array
    {
        return [
            'no decision' => [[], 'decision'],
            'unknown decision' => [['decision' => 'grant'], 'decision'],
            'decision as array' => [['decision' => ['accept']], 'decision'],
            'analytics not "1"' => [['decision' => 'save', 'analytics' => 'yes'], 'analytics'],
            'marketing as array' => [['decision' => 'save', 'marketing' => ['1']], 'marketing'],
        ];
    }

    #[DataProvider('invalidSubmissions')]
    public function test_invalid_submissions_store_nothing(array $form, string $invalidField): void
    {
        $this->post(route('storefront.privacy.consent'), $form + ['return_to' => '/?q=panjabi'])
            ->assertRedirect(url('/?q=panjabi'))
            ->assertCookieMissing(Consent::COOKIE)
            ->assertSessionHasErrors($invalidField, errorBag: 'consent')
            ->assertSessionHas('error');
    }

    public function test_the_consent_endpoint_is_csrf_protected(): void
    {
        $route = Route::getRoutes()->getByName('storefront.privacy.consent');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class, app(Kernel::class)->getMiddlewareGroups()['web']);
        $this->assertStringContainsString('name="_token"', (string) $this->get(route('storefront.privacy.settings'))->getContent());

        $middleware = new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $request = Request::create('/privacy/consent', 'POST', ['decision' => 'accept']);
        $request->setLaravelSession(app('session.store'));

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response('stored'));
    }

    public function test_safe_return_paths_are_followed(): void
    {
        foreach (['/?q=Rahima+01712345678&page=2', '/categories/panjabi', '/privacy/settings'] as $path) {
            $this->post(route('storefront.privacy.consent'), ['decision' => 'reject', 'return_to' => $path], ['Referer' => url('/').'/'])
                ->assertRedirect(url($path));
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unsafeReturnPaths(): array
    {
        return [
            'absolute URL' => ['https://evil.example/phish'],
            'protocol-relative' => ['//evil.example/phish'],
            'backslash trick' => ['/\\evil.example'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,hi'],
            'relative without slash' => ['evil.example'],
            'inner whitespace' => ['/cart evil'],
            'array' => [['/cart']],
            'oversized' => ['/?q='.str_repeat('a', 2050)],
        ];
    }

    #[DataProvider('unsafeReturnPaths')]
    public function test_unsafe_return_paths_fall_back_to_the_previous_page(mixed $returnTo): void
    {
        $this->post(route('storefront.privacy.consent'), ['decision' => 'reject', 'return_to' => $returnTo], ['Referer' => route('storefront.category', 'panjabi')])
            ->assertRedirect(route('storefront.category', 'panjabi'));
    }

    // --- Where the banner appears ----------------------------------------------------------------------

    public function test_no_banner_or_consent_script_when_gtm_is_disabled(): void
    {
        config(['services.gtm.container_id' => '']);

        $response = $this->get(route('storefront.home'))->assertOk();

        $this->assertFalse($this->bannerShown($response));
        $this->assertStringNotContainsString('gtag(', (string) $response->getContent());
        $response->assertSee('Privacy settings / <span lang="bn">গোপনীয়তা সেটিংস</span>', false);
        $this->get(route('storefront.privacy.settings'))->assertOk()->assertSee('Analytics and marketing tools are not active on this site at the moment.');
        $this->post(route('storefront.privacy.consent'), ['decision' => 'accept'])->assertCookie(Consent::COOKIE);
    }

    public function test_the_banner_appears_on_storefront_pages_and_never_on_admin_or_login_pages(): void
    {
        foreach ([route('storefront.home'), route('storefront.category', 'panjabi'), route('storefront.product', 'cotton-panjabi'), route('storefront.cart'), route('storefront.privacy')] as $url) {
            $this->assertTrue($this->bannerShown($this->get($url)->assertOk()), $url);
        }

        $admin = User::factory()->admin()->create();
        $login = $this->get(route('login'))->assertOk();
        $dashboard = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        foreach ([$login, $dashboard] as $page) {
            $this->assertFalse($this->bannerShown($page));
            $this->assertStringNotContainsString('gtag(', (string) $page->getContent());
            $this->assertStringNotContainsString('Privacy settings', (string) $page->getContent());
        }
    }

    public function test_the_banner_is_compact_with_equal_accept_and_reject_and_folded_categories(): void
    {
        $html = (string) $this->get(route('storefront.home'))->getContent();
        $banner = strstr(strstr($html, 'aria-labelledby="consent-banner-title"'), '</section>', true);
        $beforeDetails = strstr($banner, '<details', true);
        $details = strstr(strstr($banner, '<details'), '</details>', true);

        preg_match('/<button type="submit" name="decision" value="reject" class="([^"]+)">Reject all<\/button>/', $beforeDetails, $reject);
        preg_match('/<button type="submit" name="decision" value="accept" class="([^"]+)">Accept all<\/button>/', $beforeDetails, $accept);
        $this->assertNotEmpty($reject, 'Reject all is visible without opening anything.');
        $this->assertSame($reject[1] ?? null, $accept[1] ?? 'missing', 'Accept and Reject look the same.');

        $this->assertStringContainsString('<summary', $details);
        $this->assertStringContainsString('name="analytics"', $details);
        $this->assertStringContainsString('name="marketing"', $details);
        $this->assertStringContainsString('value="save"', $details, 'Saving individual choices lives with the choices.');
        $this->assertStringNotContainsString('<details', (string) $this->get(route('storefront.privacy.settings'))->getContent(), 'The settings page shows every choice openly.');
    }

    // --- Search privacy is preserved -----------------------------------------------------------------------

    public function test_search_text_stays_out_of_tracking_with_the_banner_and_after_consent(): void
    {
        foreach (['' => null, 'granted' => $this->storedValue(true, true)] as $label => $cookie) {
            $request = $cookie ? $this->withCookie(Consent::COOKIE, $cookie) : $this;
            $response = $request->get('/?q=Rahima+01712345678')->assertOk()->assertHeader('Referrer-Policy', 'origin');
            $html = (string) $response->getContent();

            $headScript = strstr(strstr($html, 'window.dataLayer = window.dataLayer || [];'), '</script>', true);
            $this->assertStringNotContainsString('01712345678', $headScript, "Consent and page-context script [{$label}].");
            $this->assertStringNotContainsString('01712345678', json_encode($this->dataLayerEvents($response)));
            $this->assertStringContainsString('Search results', strstr(strstr($html, '<title>'), '</title>', true));
            $this->assertStringContainsString('"page_has_search_text":true', json_decode('"'.preg_replace('/.*window\.dataLayer\.push\(JSON\.parse\(\'([^\']*)\'\)\);<\/script>.*/s', '$1', $html).'"'));
        }

        $this->post(route('storefront.privacy.consent'), ['decision' => 'reject', 'return_to' => '/?q=Rahima+01712345678'], ['Referer' => url('/').'/'])
            ->assertRedirect(url('/?q=Rahima+01712345678'));
    }

    // --- Privacy notice --------------------------------------------------------------------------------------

    public function test_the_privacy_notice_is_a_marked_draft_with_placeholders_until_details_are_configured(): void
    {
        $this->get(route('storefront.privacy'))->assertOk()
            ->assertSee('Draft — this notice is pending legal review')
            ->assertSee('[Business name — to be provided]')
            ->assertSee('[Privacy contact — to be provided]')
            ->assertSee('গোপনীয়তা নীতি')
            ->assertSee('Steadfast Courier')
            ->assertSee('Google (Google Analytics)')
            ->assertSee('up to 180 days');

        config(['storefront.privacy' => ['business_name' => 'Example Shop Ltd', 'business_address' => 'Dhaka', 'contact' => 'privacy@example.test']]);
        $this->get(route('storefront.privacy'))->assertOk()
            ->assertSee('Example Shop Ltd')
            ->assertSee('privacy@example.test')
            ->assertDontSee('[Business name — to be provided]');
    }
}
