<?php

namespace Tests\Feature\Storefront;

use App\Actions\Orders\CreateOrder;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Events\OrderPlaced;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Orders\OrderException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Storefront\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // 40 chars, like real tokens

    private Product $panjabi;

    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::factory()->create();
        $this->panjabi = Product::factory()->for($category)->withStock(10)->create(['name' => 'Cotton Panjabi', 'sku' => 'PANJ-1', 'selling_price' => '1500.00']);
        $this->tee = Product::factory()->for($category)->withStock(10)->create(['name' => 'Plain Tee', 'sku' => 'TEE-1', 'selling_price' => '500.00', 'discount_price' => '450.00']);
    }

    /**
     * @param  array<int, int>  $cart  product id => quantity
     */
    private function withCart(array $cart, array $tokens = [self::TOKEN]): static
    {
        return $this->withSession(['storefront.cart' => $cart, 'storefront.checkout_tokens' => $tokens]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'checkout_token' => self::TOKEN,
            'name' => 'Rahima Akter',
            'phone' => '01712345678',
            'alternate_phone' => '',
            'email' => '',
            'address' => 'House 12, Road 5, Block C',
            'area' => 'Mirpur',
            'city' => 'Dhaka',
            'delivery_zone' => 'inside_dhaka',
            'note' => 'Call before coming',
            'website' => '',
        ], $overrides);
    }

    private function placeOrder(array $overrides = [])
    {
        return $this->withCart([$this->panjabi->id => 2, $this->tee->id => 1])
            ->post(route('storefront.checkout.store'), $this->details($overrides));
    }

    // --- Checkout page ------------------------------------------------------------------------

    public function test_the_checkout_page_shows_server_calculated_totals_for_each_zone(): void
    {
        $this->withCart([$this->panjabi->id => 2, $this->tee->id => 1])
            ->get(route('storefront.checkout'))
            ->assertOk()
            ->assertSee('Cotton Panjabi × 2')
            ->assertSee('Inside Dhaka')
            ->assertSee('Outside Dhaka')
            ->assertSeeInOrder(['Subtotal', '৳3,450.00', 'Delivery (Inside Dhaka)', '৳60.00', 'Total', '৳3,510.00'])
            ->assertSeeInOrder(['Delivery (Outside Dhaka)', '৳120.00', 'Total', '৳3,570.00'])
            ->assertSee('Cash on Delivery')
            ->assertSee('name="checkout_token"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('PANJ-1');
    }

    public function test_an_empty_cart_cannot_check_out(): void
    {
        $this->get(route('storefront.checkout'))
            ->assertRedirect(route('storefront.cart'))
            ->assertSessionHas('error', 'Your cart is empty. Add a product before checking out.');

        $this->withCart([])->post(route('storefront.checkout.store'), $this->details())
            ->assertRedirect(route('storefront.cart'))
            ->assertSessionHas('error', 'Your cart is empty. Add a product before checking out.');

        $this->assertSame(0, Order::count());
    }

    // --- Successful order -------------------------------------------------------------------------

    public function test_a_guest_places_a_cash_on_delivery_website_order(): void
    {
        $response = $this->placeOrder();

        $order = Order::sole();
        $response->assertRedirect(route('storefront.thank-you', $order->order_number));

        $this->assertSame(OrderSource::Website, $order->source);
        $this->assertSame(PaymentMethod::CashOnDelivery, $order->payment_method);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('0.00', $order->amount_paid);
        $this->assertSame(['3450.00', '0.00', '60.00', '3510.00'], [$order->subtotal, $order->discount_total, $order->delivery_charge, $order->total]);
        $this->assertSame(['Rahima Akter', '01712345678', 'House 12, Road 5, Block C', 'Mirpur', 'Dhaka', 'Call before coming'],
            [$order->customer_name, $order->customer_phone, $order->shipping_address, $order->shipping_area, $order->shipping_city, $order->customer_note]);
        $this->assertNull($order->created_by);
        $this->assertNull($order->admin_note);

        $this->assertSame(2, $order->items()->count());
        $this->assertSame('50.00', $order->items()->where('sku', 'TEE-1')->value('discount_amount'));
        $this->assertSame([8, 9], [$this->panjabi->refresh()->stock_quantity, $this->tee->refresh()->stock_quantity]);
        $this->assertSame(2, StockMovement::where('order_id', $order->id)->where('type', StockMovementType::Sale)->count());
        $this->assertSame('Order placed', $order->statusHistories()->sole()->note);
        $this->assertSame(0, app(Cart::class)->count(), 'The cart is emptied.');
    }

    public function test_outside_dhaka_uses_the_configured_charge(): void
    {
        $this->placeOrder(['delivery_zone' => 'outside_dhaka']);

        $this->assertSame(['120.00', '3570.00'], [Order::sole()->delivery_charge, Order::sole()->total]);
    }

    public function test_the_order_goes_through_the_existing_create_order_action(): void
    {
        Event::fake([OrderPlaced::class]);

        $this->placeOrder();

        Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) => $event->order->source === OrderSource::Website);
    }

    public function test_submitted_prices_charges_items_source_and_payment_are_ignored(): void
    {
        $this->placeOrder([
            'items' => [['product_id' => $this->tee->id, 'quantity' => 99]],
            'unit_price' => '1.00', 'subtotal' => '1.00', 'total' => '1.00', 'discount_total' => '3000.00',
            'delivery_charge' => '0.00', 'source' => 'admin', 'payment_method' => 'bank_transfer',
            'payment_status' => 'paid', 'status' => 'delivered', 'created_by' => 1,
        ]);

        $order = Order::sole();
        $this->assertSame(['3450.00', '0.00', '60.00', '3510.00'], [$order->subtotal, $order->discount_total, $order->delivery_charge, $order->total]);
        $this->assertSame(OrderSource::Website, $order->source);
        $this->assertSame(PaymentMethod::CashOnDelivery, $order->payment_method);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(1, $order->items()->where('sku', 'TEE-1')->value('quantity'));
    }

    public function test_phones_are_normalized_and_an_existing_customer_is_reused_without_being_overwritten(): void
    {
        $customer = Customer::factory()->create(['phone' => '01712345678', 'name' => 'Saved Name', 'address' => 'Saved Address']);

        $this->placeOrder(['phone' => '+880 1712-345678', 'alternate_phone' => '8801812345678', 'email' => 'Rahima@Example.COM']);

        $order = Order::sole();
        $this->assertSame(['01712345678', '01812345678', 'rahima@example.com'], [$order->customer_phone, $order->customer_alternate_phone, $order->customer_email]);
        $this->assertTrue($order->customer->is($customer));
        $this->assertSame(1, Customer::count());
        $this->assertSame(['Saved Name', 'Saved Address'], [$customer->refresh()->name, $customer->address]);
    }

    public function test_a_new_phone_creates_one_customer(): void
    {
        $this->placeOrder(['phone' => '01999999999']);

        $this->assertSame('01999999999', Customer::sole()->phone);
    }

    // --- Validation -------------------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidDetails(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 151)], 'name'],
            'missing phone' => [['phone' => ''], 'phone'],
            'invalid phone' => [['phone' => '01212345678'], 'phone'],
            'foreign phone' => [['phone' => '+14155552671'], 'phone'],
            'invalid alternate phone' => [['alternate_phone' => '12345'], 'alternate_phone'],
            'alternate equals phone' => [['alternate_phone' => '+8801712345678'], 'alternate_phone'],
            'invalid email' => [['email' => 'rahima@'], 'email'],
            'missing address' => [['address' => ''], 'address'],
            'address too long' => [['address' => str_repeat('a', 501)], 'address'],
            'missing area' => [['area' => ''], 'area'],
            'missing city' => [['city' => ''], 'city'],
            'missing zone' => [['delivery_zone' => ''], 'delivery_zone'],
            'unknown zone' => [['delivery_zone' => 'free_delivery'], 'delivery_zone'],
            'note too long' => [['note' => str_repeat('n', 501)], 'note'],
            'missing token' => [['checkout_token' => ''], 'checkout_token'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidDetails')]
    public function test_invalid_details_are_rejected(array $overrides, string $field): void
    {
        $this->placeOrder($overrides)->assertSessionHasErrors($field);

        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->panjabi->refresh()->stock_quantity);
    }

    // --- Business rules ---------------------------------------------------------------------------

    public function test_blocked_customers_get_a_neutral_message_and_no_order(): void
    {
        Customer::factory()->blocked()->create(['phone' => '01712345678']);

        $this->placeOrder()
            ->assertRedirect(route('storefront.checkout'))
            ->assertSessionHas('error', "We couldn't place this order. Please contact us for assistance.");

        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->panjabi->refresh()->stock_quantity);
        $this->assertNotSame(0, app(Cart::class)->count(), 'The cart is kept.');
    }

    public function test_a_cart_that_changed_since_it_was_viewed_is_sent_back_for_review(): void
    {
        $this->panjabi->forceFill(['stock_quantity' => 1])->save();

        $this->placeOrder()
            ->assertRedirect(route('storefront.cart'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Only 1 of “Cotton Panjabi” can be ordered right now'));

        $this->assertSame(0, Order::count());
    }

    public function test_a_product_that_became_unavailable_blocks_the_order(): void
    {
        $this->tee->update(['status' => 'archived']);

        $this->placeOrder()->assertRedirect(route('storefront.cart'))->assertSessionHas('error');

        $this->assertSame(0, Order::count());
    }

    public function test_a_product_whose_category_was_hidden_blocks_the_order(): void
    {
        $this->panjabi->category->update(['is_active' => false]);

        $this->placeOrder()->assertRedirect(route('storefront.cart'));

        $this->assertSame(0, Order::count());
    }

    public function test_stock_that_runs_out_during_placement_is_reported_without_internal_details(): void
    {
        $this->mock(CreateOrder::class)->shouldReceive('handle')->andThrow(InsufficientStockException::for($this->panjabi, 2));

        $this->placeOrder()
            ->assertRedirect(route('storefront.cart'))
            ->assertSessionHas('error', 'Some items sold out while you were checking out. Your cart has been updated; please review it and try again.');
    }

    public function test_other_order_rule_failures_show_the_neutral_message(): void
    {
        $this->mock(CreateOrder::class)->shouldReceive('handle')->andThrow(OrderException::productNotOrderable('Secret internal reason'));

        $this->placeOrder()->assertSessionHas('error', "We couldn't place this order. Please contact us for assistance.");
    }

    // --- Duplicate submissions ----------------------------------------------------------------------

    public function test_submitting_the_same_token_twice_creates_one_order(): void
    {
        $first = $this->placeOrder();
        $order = Order::sole();

        // The browser resubmits the same form (double-click / retry); the cart is even refilled.
        $second = $this->withCart([$this->panjabi->id => 2], [self::TOKEN])->post(route('storefront.checkout.store'), $this->details());

        $first->assertRedirect(route('storefront.thank-you', $order->order_number));
        $second->assertRedirect(route('storefront.thank-you', $order->order_number));
        $this->assertSame(1, Order::count());
        $this->assertSame(8, $this->panjabi->refresh()->stock_quantity);
    }

    public function test_a_new_token_allows_a_genuine_second_order(): void
    {
        $this->placeOrder();

        $this->withCart([$this->tee->id => 1], [self::TOKEN, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'])
            ->post(route('storefront.checkout.store'), $this->details(['checkout_token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']));

        $this->assertSame(2, Order::count());
    }

    public function test_tokens_not_issued_to_this_session_are_refused(): void
    {
        $this->withCart([$this->panjabi->id => 1], ['cccccccccccccccccccccccccccccccccccccccc'])
            ->post(route('storefront.checkout.store'), $this->details())
            ->assertRedirect(route('storefront.checkout'))
            ->assertSessionHas('error', 'Your checkout session expired. Please review your order and try again.');

        $this->assertSame(0, Order::count());
    }

    public function test_the_checkout_page_issues_a_fresh_token_each_time(): void
    {
        $first = $this->withCart([$this->panjabi->id => 1], [])->get(route('storefront.checkout'))->getContent();
        $second = $this->get(route('storefront.checkout'))->getContent();

        preg_match('/name="checkout_token" value="([^"]+)"/', $first, $a);
        preg_match('/name="checkout_token" value="([^"]+)"/', $second, $b);
        $this->assertSame(40, strlen($a[1]));
        $this->assertNotSame($a[1], $b[1]);
    }

    // --- Abuse protection -------------------------------------------------------------------------

    public function test_the_honeypot_rejects_bots_without_creating_an_order(): void
    {
        $this->placeOrder(['website' => 'http://spam.example'])
            ->assertRedirect(route('storefront.checkout'))
            ->assertSessionHas('error', "We couldn't place this order. Please contact us for assistance.");

        $this->assertSame(0, Order::count());
    }

    public function test_checkout_is_rate_limited_per_ip(): void
    {
        config(['storefront.checkout_attempts_per_minute_per_ip' => 2]);

        $this->placeOrder(['name' => '']);
        $this->placeOrder(['name' => '']);
        $this->placeOrder()
            ->assertRedirect(route('storefront.checkout'))
            ->assertSessionHas('error', 'Too many checkout attempts. Please wait a few minutes and try again.');

        $this->assertSame(0, Order::count());
    }

    public function test_checkout_is_rate_limited_per_phone_across_ips(): void
    {
        config(['storefront.checkout_attempts_per_hour_per_phone' => 2]);

        foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->placeOrder(['name' => '']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->placeOrder(['phone' => '+8801712345678'])
            ->assertSessionHas('error', 'Too many checkout attempts. Please wait a few minutes and try again.');

        $this->assertSame(0, Order::count());
    }

    public function test_state_changing_storefront_routes_use_the_web_group_with_csrf(): void
    {
        foreach (['storefront.checkout.store', 'storefront.cart.add'] as $name) {
            $this->assertContains('web', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }
}
