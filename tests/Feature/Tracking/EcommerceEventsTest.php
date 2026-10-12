<?php

namespace Tests\Feature\Tracking;

use App\Actions\Orders\CreateOrder;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Storefront\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EcommerceEventsTest extends TestCase
{
    use InteractsWithDataLayer, RefreshDatabase;

    private const TOKEN = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private Product $panjabi;

    private Product $saree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGtm();
        $men = Category::factory()->create(['name' => 'Men', 'slug' => 'men']);
        $panjabiCategory = Category::factory()->childOf($men)->create(['name' => 'Panjabi', 'slug' => 'panjabi']);
        $brand = Brand::factory()->create(['name' => 'Aarong']);
        $this->panjabi = Product::factory()->for($panjabiCategory)->withStock(10)->create([
            'name' => 'Cotton Panjabi', 'slug' => 'cotton-panjabi', 'sku' => 'PANJ-1', 'brand_id' => $brand->id,
            'selling_price' => '1500.00', 'purchase_price' => '901.23', 'is_featured' => true,
        ]);
        $this->saree = Product::factory()->for($men)->withStock(3)->discounted('2500.00')->create([
            'name' => 'Silk Saree', 'slug' => 'silk-saree', 'sku' => 'SAREE-1', 'selling_price' => '3000.00',
        ]);
    }

    private function cart(): Cart
    {
        return app(Cart::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutDetails(array $overrides = []): array
    {
        return array_merge([
            'checkout_token' => self::TOKEN, 'name' => 'Rahima Akter', 'phone' => '01712345678',
            'alternate_phone' => '01812345678', 'email' => 'rahima@example.com',
            'address' => 'House 12, Road 5, Block C', 'area' => 'Mirpur', 'city' => 'Dhaka',
            'delivery_zone' => 'inside_dhaka', 'note' => 'Secret gate code 4455',
        ], $overrides);
    }

    private function placeOrder(array $cart = [], array $overrides = [], string $token = self::TOKEN): ?Order
    {
        $this->withSession([
            'storefront.cart' => $cart ?: [$this->panjabi->id => 2, $this->saree->id => 1],
            'storefront.checkout_tokens' => [$token],
        ])->post(route('storefront.checkout.store'), $this->checkoutDetails(['checkout_token' => $token] + $overrides));

        return Order::latest('id')->first();
    }

    // --- view_item / view_item_list ---------------------------------------------------------------

    public function test_view_item_on_the_product_page(): void
    {
        $events = $this->dataLayerEvents($this->get(route('storefront.product', 'silk-saree')));

        $this->assertSame([[
            'event' => 'view_item',
            'ecommerce' => [
                'currency' => 'BDT',
                'value' => 2500,
                'items' => [[
                    'item_id' => 'SAREE-1',
                    'item_name' => 'Silk Saree',
                    'item_category' => 'Men',
                    'price' => 2500,
                    'discount' => 500,
                    'quantity' => 1,
                ]],
            ],
        ]], $events);
    }

    public function test_view_item_includes_brand_and_the_category_path(): void
    {
        $item = $this->dataLayerEvents($this->get(route('storefront.product', 'cotton-panjabi')))[0]['ecommerce']['items'][0];

        $this->assertSame(['PANJ-1', 'Cotton Panjabi', 'Aarong', 'Men', 'Panjabi', 1500], [
            $item['item_id'], $item['item_name'], $item['item_brand'], $item['item_category'], $item['item_category2'], $item['price'],
        ]);
        $this->assertArrayNotHasKey('discount', $item);
    }

    public function test_view_item_list_on_catalog_category_and_search(): void
    {
        $home = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.home'))), 'view_item_list')[0]['ecommerce'];
        $this->assertSame(['all_products', 'All products', 'BDT'], [$home['item_list_id'], $home['item_list_name'], $home['currency']]);
        $this->assertSame([['PANJ-1', 0, 1500], ['SAREE-1', 1, 2500]], array_map(fn ($item) => [$item['item_id'], $item['index'], $item['price']], $home['items']));
        $this->assertSame('all_products', $home['items'][0]['item_list_id']);

        $category = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.category', 'panjabi'))), 'view_item_list')[0]['ecommerce'];
        $this->assertSame(['category_panjabi', 'Panjabi', ['PANJ-1']], [$category['item_list_id'], $category['item_list_name'], array_column($category['items'], 'item_id')]);

        $search = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.home', ['q' => 'silk']))), 'view_item_list')[0]['ecommerce'];
        $this->assertSame(['search_results', ['SAREE-1']], [$search['item_list_id'], array_column($search['items'], 'item_id')]);
    }

    public function test_list_positions_continue_across_pages(): void
    {
        config(['storefront.per_page' => 1]);

        $items = $this->dataLayerEvents($this->get(route('storefront.home', ['page' => 2])))[0]['ecommerce']['items'];

        $this->assertSame([['SAREE-1', 1]], array_map(fn ($item) => [$item['item_id'], $item['index']], $items));
    }

    public function test_empty_lists_push_no_event(): void
    {
        $this->assertSame([], $this->dataLayerEvents($this->get(route('storefront.home', ['q' => 'nothing-matches']))));
    }

    public function test_product_text_is_encoded_safely(): void
    {
        $this->panjabi->update(['name' => '</script><script>alert(1)</script> "Quote" & \'Apos\'']);

        $response = $this->get(route('storefront.product', 'cotton-panjabi'));

        $this->assertStringNotContainsString('</script><script>alert(1)', $response->getContent());
        $this->assertSame('</script><script>alert(1)</script> "Quote" & \'Apos\'', $this->dataLayerEvents($response)[0]['ecommerce']['items'][0]['item_name']);
    }

    // --- add_to_cart / remove_from_cart ----------------------------------------------------------

    public function test_the_card_form_flashes_add_to_cart_to_the_next_page_once(): void
    {
        $this->from(route('storefront.home'))->post(route('storefront.cart.add'), ['product' => 'silk-saree', 'quantity' => 2]);

        $next = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.home'))), 'add_to_cart');
        $this->assertSame([[
            'event' => 'add_to_cart',
            'ecommerce' => ['currency' => 'BDT', 'value' => 5000, 'items' => [[
                'item_id' => 'SAREE-1', 'item_name' => 'Silk Saree', 'item_category' => 'Men', 'price' => 2500, 'discount' => 500, 'quantity' => 2,
            ]]],
        ]], $next);

        $this->assertSame([], $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.home'))), 'add_to_cart'), 'Shown only once.');
    }

    public function test_add_to_cart_reports_the_accepted_quantity_not_the_requested_one(): void
    {
        $this->post(route('storefront.cart.add'), ['product' => 'silk-saree', 'quantity' => 5]);

        $event = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.home'))), 'add_to_cart')[0];
        $this->assertSame([3, 7500], [$event['ecommerce']['items'][0]['quantity'], $event['ecommerce']['value']], 'Stock is 3.');
    }

    public function test_rejected_additions_produce_no_event(): void
    {
        $this->panjabi->forceFill(['stock_quantity' => 0])->save();

        $this->post(route('storefront.cart.add'), ['product' => 'cotton-panjabi']);
        $this->post(route('storefront.cart.add'), ['product' => 'does-not-exist']);

        $this->assertSame([], $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.home'))), 'add_to_cart'));
    }

    public function test_the_livewire_add_button_dispatches_add_to_cart_only_after_success(): void
    {
        Livewire::test('storefront.add-to-cart', ['productId' => $this->saree->id, 'maxQuantity' => 3])
            ->call('increment')
            ->assertNotDispatched('datalayer-push')
            ->call('add')
            ->assertDispatched('datalayer-push', fn ($name, $params) => $params['events'][0]['event'] === 'add_to_cart'
                && $params['events'][0]['ecommerce']['items'][0]['quantity'] === 2
                && $params['events'][0]['ecommerce']['value'] === 5000);
    }

    public function test_the_livewire_add_button_dispatches_nothing_when_rejected(): void
    {
        $this->cart()->add($this->saree, 3);

        Livewire::test('storefront.add-to-cart', ['productId' => $this->saree->id, 'maxQuantity' => 3])
            ->call('add')
            ->assertSet('error', 'You already have the maximum quantity of “Silk Saree” in your cart.')
            ->assertNotDispatched('datalayer-push');
    }

    public function test_cart_page_changes_send_matching_events(): void
    {
        $this->cart()->add($this->panjabi, 2);
        $this->cart()->add($this->saree, 1);
        $page = Livewire::test('pages::storefront.cart')->assertNotDispatched('datalayer-push');

        $page->call('increment', $this->panjabi->id)
            ->assertDispatched('datalayer-push', fn ($name, $params) => $params['events'][0]['event'] === 'add_to_cart'
                && $params['events'][0]['ecommerce']['items'][0]['quantity'] === 1);

        $page->call('decrement', $this->panjabi->id)
            ->assertDispatched('datalayer-push', fn ($name, $params) => $params['events'][0]['event'] === 'remove_from_cart'
                && $params['events'][0]['ecommerce']['items'][0]['quantity'] === 1
                && $params['events'][0]['ecommerce']['value'] === 1500);

        $page->call('remove', $this->panjabi->id)
            ->assertDispatched('datalayer-push', fn ($name, $params) => $params['events'][0]['event'] === 'remove_from_cart'
                && $params['events'][0]['ecommerce']['items'][0]['quantity'] === 2
                && $params['events'][0]['ecommerce']['value'] === 3000);
    }

    public function test_clearing_the_cart_removes_every_line(): void
    {
        $this->cart()->add($this->panjabi, 2);
        $this->cart()->add($this->saree, 1);

        Livewire::test('pages::storefront.cart')->call('clear')
            ->assertDispatched('datalayer-push', fn ($name, $params) => count($params['events']) === 2
                && collect($params['events'])->every(fn ($event) => $event['event'] === 'remove_from_cart'));
    }

    public function test_no_change_means_no_event(): void
    {
        $this->cart()->add($this->saree, 3);

        Livewire::test('pages::storefront.cart')
            ->call('increment', $this->saree->id)
            ->assertNotDispatched('datalayer-push')
            ->call('remove', 999999)
            ->assertNotDispatched('datalayer-push');
    }

    public function test_no_livewire_tracking_when_gtm_is_disabled(): void
    {
        config(['services.gtm.container_id' => '']);
        $this->cart()->add($this->panjabi, 1);

        Livewire::test('pages::storefront.cart')->call('increment', $this->panjabi->id)->assertNotDispatched('datalayer-push');
        Livewire::test('storefront.add-to-cart', ['productId' => $this->saree->id, 'maxQuantity' => 3])->call('add')->assertNotDispatched('datalayer-push');
    }

    // --- begin_checkout ---------------------------------------------------------------------------

    public function test_begin_checkout_once_per_cart_content(): void
    {
        $this->cart()->add($this->panjabi, 2);

        $first = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.checkout'))), 'begin_checkout');
        $this->assertSame([['event' => 'begin_checkout', 'ecommerce' => ['currency' => 'BDT', 'value' => 3000, 'items' => [[
            'item_id' => 'PANJ-1', 'item_name' => 'Cotton Panjabi', 'item_brand' => 'Aarong', 'item_category' => 'Men', 'item_category2' => 'Panjabi', 'price' => 1500, 'quantity' => 2,
        ]]]]], $first);

        $this->assertSame([], $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.checkout'))), 'begin_checkout'), 'A reload does not repeat it.');

        $this->cart()->add($this->saree, 1);
        $this->assertCount(1, $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.checkout'))), 'begin_checkout'), 'A changed cart starts a new checkout.');
    }

    public function test_an_empty_cart_does_not_begin_checkout(): void
    {
        $this->get(route('storefront.checkout'))->assertRedirect(route('storefront.cart'));

        $this->assertSame([], $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.cart'))), 'begin_checkout'));
    }

    // --- purchase -----------------------------------------------------------------------------------

    public function test_purchase_is_built_from_the_saved_order(): void
    {
        $order = $this->placeOrder();

        $events = $this->dataLayerEvents($this->get(route('storefront.thank-you', $order->order_number)));

        $this->assertSame([[
            'event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => $order->order_number,
                'currency' => 'BDT',
                'value' => 5500,
                'shipping' => 60,
                'items' => [
                    ['item_id' => 'PANJ-1', 'item_name' => 'Cotton Panjabi', 'price' => 1500, 'quantity' => 2],
                    ['item_id' => 'SAREE-1', 'item_name' => 'Silk Saree', 'price' => 2500, 'discount' => 500, 'quantity' => 1],
                ],
            ],
        ]], $this->eventsNamed($events, 'purchase'));
    }

    public function test_purchase_uses_snapshots_even_if_the_product_changes_later(): void
    {
        $order = $this->placeOrder();
        $this->panjabi->update(['name' => 'Renamed', 'sku' => 'NEW-SKU', 'selling_price' => '9999.00']);

        $items = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.thank-you', $order->order_number))), 'purchase')[0]['ecommerce']['items'];

        $this->assertSame(['PANJ-1', 'Cotton Panjabi', 1500], [$items[0]['item_id'], $items[0]['item_name'], $items[0]['price']]);
    }

    public function test_refreshing_the_confirmation_does_not_resend_the_purchase(): void
    {
        $order = $this->placeOrder();

        $first = $this->get(route('storefront.thank-you', $order->order_number));
        $second = $this->get(route('storefront.thank-you', $order->order_number));
        $third = $this->get(route('storefront.thank-you', $order->order_number));

        $this->assertCount(1, $this->eventsNamed($this->dataLayerEvents($first), 'purchase'));
        $this->assertCount(0, $this->eventsNamed($this->dataLayerEvents($second), 'purchase'));
        $this->assertCount(0, $this->eventsNamed($this->dataLayerEvents($third), 'purchase'));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
    }

    public function test_a_resubmitted_checkout_does_not_resend_the_purchase(): void
    {
        $order = $this->placeOrder();
        $this->get(route('storefront.thank-you', $order->order_number));

        // Same form submitted again (double-click / retry) → same order, no new purchase event.
        $this->placeOrder();
        $again = $this->get(route('storefront.thank-you', $order->order_number));

        $this->assertSame(1, Order::count());
        $this->assertCount(0, $this->eventsNamed($this->dataLayerEvents($again), 'purchase'));
    }

    public function test_separate_orders_have_separate_transaction_ids(): void
    {
        $first = $this->placeOrder();
        $firstEvent = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.thank-you', $first->order_number))), 'purchase')[0];

        $second = $this->placeOrder([$this->panjabi->id => 1], [], 'ffffffffffffffffffffffffffffffffffffffff');
        $secondEvent = $this->eventsNamed($this->dataLayerEvents($this->get(route('storefront.thank-you', $second->order_number))), 'purchase')[0];

        $this->assertNotSame($first->order_number, $second->order_number);
        $this->assertSame([$first->order_number, $second->order_number], [$firstEvent['ecommerce']['transaction_id'], $secondEvent['ecommerce']['transaction_id']]);
    }

    public function test_failed_checkouts_never_produce_a_purchase(): void
    {
        Customer::factory()->blocked()->create(['phone' => '01999999999']);

        $this->placeOrder([], ['name' => '']);                       // validation failure
        $this->placeOrder([], ['phone' => '01999999999']);           // blocked customer
        $this->placeOrder([], ['website' => 'http://spam']);         // honeypot

        $this->mock(CreateOrder::class)->shouldReceive('handle')->andThrow(InsufficientStockException::for($this->panjabi, 2));
        $this->placeOrder();                                          // rolled back inside CreateOrder

        $this->assertSame(0, Order::count());
        foreach ([route('storefront.checkout'), route('storefront.cart'), route('storefront.home')] as $url) {
            $this->assertSame([], $this->eventsNamed($this->dataLayerEvents($this->get($url)), 'purchase'));
        }
    }

    public function test_no_purchase_event_when_gtm_is_disabled(): void
    {
        config(['services.gtm.container_id' => '']);
        $order = $this->placeOrder();

        $html = $this->get(route('storefront.thank-you', $order->order_number))->assertOk()->getContent();

        $this->assertStringNotContainsString('dataLayer', $html);
        $this->assertStringNotContainsString('purchase', $html);
    }

    // --- Privacy -------------------------------------------------------------------------------------

    public function test_no_event_contains_personal_or_internal_data(): void
    {
        $this->post(route('storefront.cart.add'), ['product' => 'cotton-panjabi']);
        $responses = [
            $this->get(route('storefront.home')),
            $this->get(route('storefront.category', 'panjabi')),
            $this->get(route('storefront.product', 'cotton-panjabi')),
            $this->get(route('storefront.checkout')),
        ];
        $order = $this->placeOrder();
        $responses[] = $this->get(route('storefront.thank-you', $order->order_number));

        $events = collect($responses)->flatMap(fn ($response) => $this->dataLayerEvents($response))->all();
        $this->assertNotEmpty($this->eventsNamed($events, 'purchase'));
        $json = json_encode($events);

        foreach (['01712345678', '01812345678', '1712345678', 'rahima@example.com', 'House 12', 'Block C', 'Mirpur', 'Secret gate code',
            '901.23', 'Rahima', 'customer', 'phone', 'email', 'address', 'stock', 'purchase_price', 'cost', 'note'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $json, "dataLayer must not contain [{$forbidden}].");
        }

        $allowedEcommerceKeys = ['currency', 'value', 'items', 'item_list_id', 'item_list_name', 'transaction_id', 'shipping'];
        $allowedItemKeys = ['item_id', 'item_name', 'item_brand', 'item_category', 'item_category2', 'item_category3', 'item_category4', 'item_category5', 'price', 'discount', 'quantity', 'index', 'item_list_id', 'item_list_name'];

        foreach ($events as $event) {
            $this->assertSame(['event', 'ecommerce'], array_keys($event));
            $this->assertSame([], array_diff(array_keys($event['ecommerce']), $allowedEcommerceKeys), $event['event']);
            foreach ($event['ecommerce']['items'] as $item) {
                $this->assertSame([], array_diff(array_keys($item), $allowedItemKeys), $event['event']);
            }
        }
    }
}
