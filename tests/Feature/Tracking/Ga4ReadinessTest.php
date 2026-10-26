<?php

namespace Tests\Feature\Tracking;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * GA4 is configured inside the GTM container, never in the application.
 * These tests pin the contract the GTM GA4 tags rely on: no direct Google
 * Analytics code, the six existing dataLayer event names, and payloads that
 * follow Google's GA4 ecommerce definitions (value = sum of price x quantity,
 * price = discounted unit price, discount = per-unit discount, shipping and
 * tax excluded from value).
 */
class Ga4ReadinessTest extends TestCase
{
    use InteractsWithDataLayer, RefreshDatabase;

    private const TOKEN = 'gggggggggggggggggggggggggggggggggggggggg';

    /** The Custom Event trigger names configured in GTM. */
    private const GA4_EVENTS = ['view_item_list', 'view_item', 'add_to_cart', 'remove_from_cart', 'begin_checkout', 'purchase'];

    private Product $panjabi;

    private Product $saree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGtm();
        $men = Category::factory()->create(['name' => 'Men', 'slug' => 'men']);
        $panjabiCategory = Category::factory()->childOf($men)->create(['name' => 'Panjabi', 'slug' => 'panjabi']);
        $this->panjabi = Product::factory()->for($panjabiCategory)->withStock(10)->create([
            'name' => 'Cotton Panjabi', 'slug' => 'cotton-panjabi', 'sku' => 'PANJ-1',
            'brand_id' => Brand::factory()->create(['name' => 'Aarong'])->id, 'selling_price' => '1500.00',
        ]);
        $this->saree = Product::factory()->for($men)->withStock(5)->discounted('2499.50')->create([
            'name' => 'Silk Saree', 'slug' => 'silk-saree', 'sku' => 'SAREE-1', 'selling_price' => '3000.00',
        ]);
    }

    private function checkout(array $overrides = []): TestResponse
    {
        return $this->withSession(['storefront.checkout_tokens' => [self::TOKEN]])->post(route('storefront.checkout.store'), array_merge([
            'checkout_token' => self::TOKEN, 'name' => 'Rahima Akter', 'phone' => '01712345678', 'email' => 'rahima@example.com',
            'address' => 'House 12, Road 5', 'area' => 'Mirpur', 'city' => 'Dhaka', 'delivery_zone' => 'outside_dhaka',
        ], $overrides));
    }

    // --- No direct Google Analytics -------------------------------------------------------------

    public function test_the_storefront_never_loads_google_analytics_directly(): void
    {
        $order = Order::factory()->create();
        $this->withSession(['storefront.cart' => [$this->panjabi->id => 1], 'storefront.placed_orders' => [$order->order_number]]);
        $pages = [route('storefront.home'), route('storefront.category', 'panjabi'), route('storefront.product', 'cotton-panjabi'),
            route('storefront.cart'), route('storefront.checkout'), route('storefront.thank-you', $order->order_number)];

        foreach (['GTM-TEST123', ''] as $containerId) {
            config(['services.gtm.container_id' => $containerId]);

            foreach ($pages as $url) {
                $html = $this->get($url)->assertOk()->getContent();

                foreach (['gtag/js', 'google-analytics.com', 'analytics.js', 'measurement_id', "gtag('js'", "gtag('config'", "gtag('event'"] as $directCode) {
                    $this->assertStringNotContainsString($directCode, $html, "{$url} must not load GA4 directly.");
                }
                // The only gtag() calls are the queued consent-mode commands that GTM reads.
                preg_match_all("/gtag\\('([a-z_]+)'/", $html, $commands);
                $this->assertSame($containerId === '' ? [] : ['consent', 'set'], $commands[1], "{$url}: only consent commands are queued.");
                $this->assertDoesNotMatchRegularExpression('/\bG-[A-Z0-9]{6,}\b/', $html, 'No Measurement ID in the page.');
                $this->assertSame($containerId === '' ? 0 : 1, substr_count($html, 'googletagmanager.com/gtm.js'), 'GTM is the only Google script.');
            }
        }

        $this->assertArrayNotHasKey('ga4', config('services'), 'The Measurement ID lives in GTM, not in application config.');
    }

    // --- Payload contract -----------------------------------------------------------------------

    public function test_a_shopping_journey_emits_one_ga4_event_per_action_in_the_ga4_ecommerce_format(): void
    {
        $events = [
            ...$this->dataLayerEvents($this->get(route('storefront.home'))),
            ...$this->dataLayerEvents($this->get(route('storefront.product', 'silk-saree'))),
        ];
        $this->post(route('storefront.cart.add'), ['product' => 'cotton-panjabi', 'quantity' => 3]);
        $events = [...$events, ...$this->dataLayerEvents($this->get(route('storefront.cart')))];

        Livewire::test('storefront.add-to-cart', ['productId' => $this->saree->id, 'maxQuantity' => 5])
            ->call('increment')
            ->call('add')
            ->assertDispatched('datalayer-push', function (string $name, array $params) use (&$events): bool {
                array_push($events, ...$params['events']);

                return true;
            });
        Livewire::test('pages::storefront.cart')
            ->call('decrement', $this->panjabi->id)
            ->assertDispatched('datalayer-push', function (string $name, array $params) use (&$events): bool {
                array_push($events, ...$params['events']);

                return true;
            });

        $events = [...$events, ...$this->dataLayerEvents($this->get(route('storefront.checkout')))];
        $orderNumber = $this->placeOrderAndGetNumber();
        $events = [...$events, ...$this->dataLayerEvents($this->get(route('storefront.thank-you', $orderNumber)))];

        // One event per confirmed action: two additions (card form, then Livewire button), one of everything else.
        $this->assertSame(['view_item_list', 'view_item', 'add_to_cart', 'add_to_cart', 'remove_from_cart', 'begin_checkout', 'purchase'], array_column($events, 'event'));
        $this->assertSame(self::GA4_EVENTS, array_values(array_unique(array_column($events, 'event'))), 'Only the six GTM trigger names.');

        foreach ($events as $event) {
            $this->assertGa4Event($event);
        }
    }

    public function test_purchase_reconciles_with_the_saved_order(): void
    {
        $this->withSession(['storefront.cart' => [$this->panjabi->id => 2, $this->saree->id => 2]]);
        $order = Order::query()->where('order_number', $this->placeOrderAndGetNumber())->firstOrFail();

        $purchase = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.thank-you', $order->order_number))), 'purchase')[0]['ecommerce'];

        $this->assertSame($order->order_number, $purchase['transaction_id']);
        $this->assertNotSame('', $purchase['transaction_id'], 'GA4 merges all purchases with an empty transaction_id.');
        $this->assertSame(['BDT', 7999, 120], [$purchase['currency'], $purchase['value'], $purchase['shipping']]);
        $this->assertSame((float) $order->total, (float) bcadd((string) $purchase['value'], (string) $purchase['shipping'], 2), 'value + shipping = order total.');
        $this->assertSame([1500, 2499.5], array_column($purchase['items'], 'price'), 'Discounted unit prices from the order snapshots.');
        $this->assertSame([500.5], array_column($purchase['items'], 'discount'), 'Per-unit discount.');
        $this->assertArrayNotHasKey('tax', $purchase, 'No tax is stored, so none is sent.');
    }

    // --- page_location ----------------------------------------------------------------------------

    public function test_urls_collected_as_page_location_carry_no_customer_details(): void
    {
        $this->withSession(['storefront.cart' => [$this->panjabi->id => 1]]);

        $this->assertMatchesRegularExpression('/<form method="POST" action="'.preg_quote(route('storefront.checkout.store'), '/').'"/', $this->get(route('storefront.checkout'))->getContent(), 'Details are posted, never put in the URL.');

        $failed = $this->checkout(['name' => '']);
        $this->assertSame(route('storefront.checkout'), $failed->headers->get('Location'));

        $placed = $this->withSession(['storefront.cart' => [$this->panjabi->id => 1]])->checkout();
        $this->assertSame(route('storefront.thank-you', Order::query()->value('order_number')), $placed->headers->get('Location'));
        $this->assertStringNotContainsString('?', $placed->headers->get('Location'));
    }

    private function placeOrderAndGetNumber(): string
    {
        $this->checkout()->assertRedirect();

        return Order::query()->latest('id')->value('order_number');
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function assertGa4Event(array $event): void
    {
        $name = $event['event'];
        $ecommerce = $event['ecommerce'];

        $this->assertSame('BDT', $ecommerce['currency'], $name);
        $this->assertIsList($ecommerce['items'], $name);
        $this->assertNotEmpty($ecommerce['items'], $name);
        $this->assertLessThanOrEqual(200, count($ecommerce['items']), 'GA4 accepts at most 200 items.');

        $sum = '0';

        foreach ($ecommerce['items'] as $item) {
            $this->assertIsString($item['item_id'], $name);
            $this->assertIsString($item['item_name'], $name);
            $this->assertTrue(is_int($item['price']) || is_float($item['price']), "{$name}: price is a number.");
            $this->assertGreaterThanOrEqual(0, $item['price'], $name);

            if (isset($item['discount'])) {
                $this->assertGreaterThan(0, $item['discount'], $name);
            }

            if ($name === 'view_item_list') {
                $this->assertIsInt($item['index']);
                $this->assertArrayNotHasKey('quantity', $item, 'A list impression is not a quantity.');

                continue;
            }

            $this->assertIsInt($item['quantity'], $name);
            $this->assertGreaterThanOrEqual(1, $item['quantity'], $name);
            $sum = bcadd($sum, bcmul((string) $item['price'], (string) $item['quantity'], 2), 2);
        }

        if ($name === 'view_item_list') {
            $this->assertIsString($ecommerce['item_list_id']);
            $this->assertIsString($ecommerce['item_list_name']);
            $this->assertSame(range(0, count($ecommerce['items']) - 1), array_column($ecommerce['items'], 'index'));

            return;
        }

        $this->assertSame((float) $sum, (float) $ecommerce['value'], "{$name}: value = sum of price x quantity (no shipping or tax).");
    }
}
