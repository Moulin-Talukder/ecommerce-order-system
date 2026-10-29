<?php

namespace Tests\Feature\Tracking;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Tracking\Consent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * The Meta Pixel is configured only inside the GTM container (no Meta code in
 * the application). These tests pin the application side of that contract:
 *  - Meta tags are gated in GTM on ad_storage AND ad_user_data, which only the
 *    Marketing choice grants; ad_personalization is never granted;
 *  - page flags tell GTM to block Meta on pages whose raw URL holds search
 *    text or personal-looking values, and those pages send only their origin
 *    as referrer;
 *  - the existing ecommerce events carry what the Meta tags read, and no
 *    personal data.
 * They cannot prove delivery to Meta; that needs staging verification.
 */
class MetaPixelReadinessTest extends TestCase
{
    use InteractsWithDataLayer, RefreshDatabase;

    private const TOKEN = 'mmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmm';

    private Product $panjabi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGtm();
        $men = Category::factory()->create(['name' => 'Men', 'slug' => 'men']);
        $this->panjabi = Product::factory()->for($men)->withStock(10)->create([
            'name' => 'Cotton Panjabi', 'slug' => 'cotton-panjabi', 'sku' => 'PANJ-1',
            'brand_id' => Brand::factory()->create(['name' => 'Aarong'])->id, 'selling_price' => '1500.00',
        ]);
    }

    private function storedConsent(bool $analytics, bool $marketing): string
    {
        return json_encode(['v' => config('storefront.consent.version'), 'a' => $analytics, 'm' => $marketing, 't' => now()->getTimestamp()]);
    }

    /**
     * Whether the documented GTM consent check for Meta tags (ad_storage and
     * ad_user_data both granted) would let a Meta tag fire on this page.
     */
    private function metaTagsMayFire(TestResponse $response): bool
    {
        $consent = $this->googleConsentDefaults($response);

        return ($consent['ad_storage'] ?? null) === 'granted' && ($consent['ad_user_data'] ?? null) === 'granted';
    }

    /**
     * The documented GTM mapping from a dataLayer ecommerce event to Meta
     * parameters (mirrors the "Meta - content_ids / contents / num_items"
     * Custom JavaScript variables).
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function metaParameters(array $event): array
    {
        $items = $event['ecommerce']['items'];

        return [
            'content_ids' => array_column($items, 'item_id'),
            'contents' => array_map(fn (array $item) => ['id' => $item['item_id'], 'quantity' => $item['quantity'] ?? 1], $items),
            'content_type' => 'product',
            'num_items' => array_sum(array_map(fn (array $item) => $item['quantity'] ?? 1, $items)),
            'value' => $event['ecommerce']['value'],
            'currency' => $event['ecommerce']['currency'],
        ];
    }

    // --- No Meta code in the application -----------------------------------------------------------

    public function test_the_application_never_loads_the_meta_pixel_itself(): void
    {
        $order = Order::factory()->create();
        $pages = ['/', '/?q=test', '/categories/men', '/products/cotton-panjabi', '/cart', '/privacy', '/privacy/settings', route('storefront.thank-you', $order->order_number)];

        foreach (['GTM-TEST123', ''] as $containerId) {
            config(['services.gtm.container_id' => $containerId]);

            foreach ([null, $this->storedConsent(true, true)] as $consent) {
                $request = $this->withSession(['storefront.placed_orders' => [$order->order_number]]);
                $request = $consent ? $request->withCookie(Consent::COOKIE, $consent) : $request;

                foreach ($pages as $url) {
                    $html = (string) $request->get($url)->assertOk()->getContent();

                    foreach (['connect.facebook.net', 'fbevents.js', 'fbq(', '_fbq', 'facebook.com/tr'] as $metaCode) {
                        $this->assertStringNotContainsString($metaCode, $html, "{$url} must not load Meta directly.");
                    }
                }
            }
        }

        $this->assertArrayNotHasKey('meta', config('services'), 'The Pixel ID lives in GTM, not in application config.');
    }

    public function test_admin_and_login_pages_carry_no_meta_or_consent_code(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([$this->get(route('login')), $this->actingAs($admin)->get(route('admin.dashboard'))] as $page) {
            $page->assertOk();
            $this->assertNull($this->googleConsentDefaults($page));
            $this->assertStringNotContainsString('fbq(', (string) $page->getContent());
        }
    }

    // --- Consent gate ---------------------------------------------------------------------------------

    /**
     * @return array<string, array{\Closure(self): ?string, bool}>
     */
    public static function consentStates(): array
    {
        return [
            'no choice' => [fn (self $test) => null, false],
            'malformed cookie' => [fn (self $test) => 'not json', false],
            'outdated version' => [fn (self $test) => json_encode(['v' => 0, 'a' => true, 'm' => true, 't' => time()]), false],
            'expired' => [fn (self $test) => json_encode(['v' => 1, 'a' => true, 'm' => true, 't' => now()->subDays(181)->getTimestamp()]), false],
            'reject all' => [fn (self $test) => $test->storedConsent(false, false), false],
            'analytics only' => [fn (self $test) => $test->storedConsent(true, false), false],
            'marketing only' => [fn (self $test) => $test->storedConsent(false, true), true],
            'both' => [fn (self $test) => $test->storedConsent(true, true), true],
        ];
    }

    #[DataProvider('consentStates')]
    public function test_meta_tags_may_fire_only_with_marketing_consent(\Closure $cookie, bool $metaAllowed): void
    {
        $value = $cookie($this);
        $response = ($value === null ? $this : $this->withCookie(Consent::COOKIE, $value))->get('/products/cotton-panjabi')->assertOk();
        $consent = $this->googleConsentDefaults($response);

        $this->assertSame($metaAllowed, $this->metaTagsMayFire($response));
        $this->assertSame('denied', $consent['ad_personalization'], 'Ad personalization is never granted.');
        $this->assertSame($metaAllowed ? 'false' : 'true', preg_match("/ads_data_redaction', (true|false)/", (string) $response->getContent(), $m) ? $m[1] : null);
    }

    public function test_withdrawing_marketing_blocks_meta_on_the_next_page_and_clears_its_cookies(): void
    {
        $before = $this->withCookie(Consent::COOKIE, $this->storedConsent(true, true))->get('/');
        $this->assertTrue($this->metaTagsMayFire($before));

        $withdrawal = $this->withCookie(Consent::COOKIE, $this->storedConsent(true, true))
            ->withUnencryptedCookies(['_fbp' => 'fb.1.123', '_fbc' => 'fb.1.456', '_ga' => 'GA1.1.1.2'])
            ->post(route('storefront.privacy.consent'), ['decision' => 'save', 'analytics' => '1']);

        $cleared = collect($withdrawal->headers->getCookies())->filter(fn (Cookie $cookie) => $cookie->isCleared())->map->getName()->unique()->sort()->values()->all();
        $this->assertSame(['_fbc', '_fbp'], $cleared, 'Meta cookies are cleared; Analytics (still allowed) is kept.');

        $after = $this->withCookie(Consent::COOKIE, $withdrawal->getCookie(Consent::COOKIE)->getValue())->get('/');
        $this->assertFalse($this->metaTagsMayFire($after));
        $this->assertSame('granted', $this->googleConsentDefaults($after)['analytics_storage'], 'GA4 consent is unaffected.');
    }

    // --- Pages Meta must not see --------------------------------------------------------------------------

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function rawUrls(): array
    {
        return [
            'plain product page' => ['/products/cotton-panjabi', false, false],
            'campaign link' => ['/?utm_source=facebook&utm_campaign=eid&fbclid=IwAR2017123456789Xy', false, false],
            'pagination' => ['/?page=2', false, false],
            'search text' => ['/?q=panjabi', true, false],
            'email in a campaign value' => ['/?utm_content=rahima%40example.com', false, true],
            'mobile inside text' => ['/?utm_content=call+01712345678', false, true],
            'international mobile' => ['/?ref=%2B8801712345678', false, true],
            'email as a parameter name' => ['/?rahima%40example.com', false, true],
            'email as a name with a value' => ['/?rahima%40example.com=1', false, true],
            'both' => ['/?q=x&ref=01712345678', true, true],
        ];
    }

    #[DataProvider('rawUrls')]
    public function test_pages_whose_raw_url_meta_would_read_are_flagged_and_protected(string $path, bool $searchText, bool $personalData): void
    {
        foreach (['GTM-TEST123', ''] as $containerId) {
            config(['services.gtm.container_id' => $containerId]);

            $response = $this->get($path)->assertOk();

            $this->assertSame($searchText || $personalData ? 'origin' : 'strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'), "{$path} with GTM [{$containerId}]");

            if ($containerId !== '') {
                $context = $this->pushedPageContext($response);
                $this->assertSame([$searchText, $personalData], [$context['page_has_search_text'], $context['page_has_personal_data_in_url']], $path);
                $this->assertDoesNotMatchRegularExpression('/rahima|call|=(%2B)?(88)?01712345678/i', json_encode($context), 'The flags and cleaned URL never carry the data.');
            }
        }
    }

    // --- Ecommerce events the Meta tags read ---------------------------------------------------------------

    public function test_the_existing_ecommerce_events_supply_the_meta_parameters_without_personal_data(): void
    {
        $viewItem = $this->eventsNamed($this->dataLayerEvents($this->get('/products/cotton-panjabi')), 'view_item')[0];
        $this->assertSame(['content_ids' => ['PANJ-1'], 'contents' => [['id' => 'PANJ-1', 'quantity' => 1]], 'content_type' => 'product', 'num_items' => 1, 'value' => 1500, 'currency' => 'BDT'], $this->metaParameters($viewItem));

        $this->post(route('storefront.cart.add'), ['product' => 'cotton-panjabi', 'quantity' => 2, 'return_to' => '/cart']);
        $addToCart = $this->eventsNamed($this->dataLayerEvents($this->get('/cart')), 'add_to_cart')[0];
        $this->assertSame([['PANJ-1'], 2, 3000], [$this->metaParameters($addToCart)['content_ids'], $this->metaParameters($addToCart)['num_items'], $this->metaParameters($addToCart)['value']]);

        $checkout = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.checkout'))), 'begin_checkout')[0];
        $this->assertSame(2, $this->metaParameters($checkout)['num_items']);

        $this->withSession(['storefront.checkout_tokens' => [self::TOKEN]])->post(route('storefront.checkout.store'), [
            'checkout_token' => self::TOKEN, 'name' => 'Rahima Akter', 'phone' => '01712345678', 'email' => 'rahima@example.com',
            'address' => 'House 12, Road 5', 'area' => 'Mirpur', 'city' => 'Dhaka', 'delivery_zone' => 'inside_dhaka', 'note' => 'Gate code 4455',
        ])->assertRedirect();
        $order = Order::query()->sole();

        $first = $this->dataLayerEvents($this->get(route('storefront.thank-you', $order->order_number)));
        $purchase = $this->eventsNamed($first, 'purchase')[0];
        $this->assertSame($order->order_number, $purchase['ecommerce']['transaction_id'], 'Used as the Meta eventID for future deduplication.');
        $this->assertSame(['content_ids' => ['PANJ-1'], 'num_items' => 2, 'value' => 3000, 'currency' => 'BDT'], array_intersect_key($this->metaParameters($purchase), array_flip(['content_ids', 'num_items', 'value', 'currency'])));
        $this->assertSame([], $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.thank-you', $order->order_number))), 'purchase'), 'A refresh does not repeat the purchase.');

        $json = json_encode([$viewItem, $addToCart, $checkout, $purchase]);
        foreach (['Rahima', '01712345678', 'rahima@example.com', 'House 12', 'Mirpur', 'Gate code'] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }
    }

    // --- Disclosure ------------------------------------------------------------------------------------------

    public function test_the_marketing_choice_and_notice_disclose_the_meta_pixel(): void
    {
        $this->get('/')->assertSee('Lets the Meta Pixel measure visits and purchases from our Facebook and Instagram ads. Off unless you turn it on.')
            ->assertDontSee('id="consent-banner-marketing" type="checkbox" name="marketing" value="1" class="mt-1" checked', false);

        $this->get(route('storefront.privacy'))
            ->assertSee('Meta Platforms (Meta Pixel) receives data only if you allow Marketing')
            ->assertSee('not search results, and not pages whose web address contains personal details')
            ->assertSee('to be confirmed by legal review')
            ->assertSee('Meta: _fbp, _fbc')
            ->assertSee('It does not delete data Google or Meta already received');
    }
}
