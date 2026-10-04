<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->withStock(10)->create(['name' => 'Cotton Panjabi', 'sku' => 'PANJ-1', 'selling_price' => '1500.00']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Rahima Akter',
            'customer_phone' => '+880 1712-345678',
            'customer_alternate_phone' => '',
            'customer_email' => 'Rahima@Example.com',
            'shipping_address' => 'House 12, Road 5',
            'shipping_area' => 'Mirpur',
            'shipping_city' => 'Dhaka',
            'shipping_postal_code' => '1216',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'delivery_charge' => '60.00',
            'discount_total' => '0',
            'payment_method' => 'cod',
            'source' => 'phone',
            'customer_note' => '',
            'admin_note' => '',
        ], $overrides);
    }

    private function user(UserRole $role = UserRole::Manager): User
    {
        return User::factory()->role($role)->create();
    }

    // --- Creating through HTTP --------------------------------------------------------

    public function test_a_manager_can_place_an_order(): void
    {
        $response = $this->actingAs($this->user())->post(route('admin.orders.store'), $this->payload());

        $order = Order::sole();
        $response->assertRedirect(route('admin.orders.show', $order))->assertSessionHas('success', "Order {$order->order_number} placed.");
        $this->assertSame('3060.00', $order->total);
        $this->assertSame('01712345678', $order->customer_phone);
        $this->assertSame('rahima@example.com', $order->customer_email);
        $this->assertSame(OrderSource::Phone, $order->source);
        $this->assertSame(8, $this->product->refresh()->stock_quantity);
    }

    public function test_client_submitted_prices_totals_status_payment_and_stock_are_ignored(): void
    {
        $this->actingAs($this->user())->post(route('admin.orders.store'), $this->payload([
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '1.00', 'line_total' => '1.00', 'discount_amount' => '1499.00', 'unit_cost' => '0']],
            'subtotal' => '1.00', 'total' => '1.00', 'amount_paid' => '1560.00', 'payment_status' => 'paid',
            'status' => 'delivered', 'order_number' => 'HACKED-1', 'customer_id' => 999, 'created_by' => 999, 'currency' => 'USD',
            'stock_quantity' => 9999,
        ]))->assertSessionHasNoErrors();

        $order = Order::sole();
        $item = $order->items()->sole();
        $this->assertSame(['1500.00', '0.00', '1500.00'], [$item->unit_price, $item->discount_amount, $item->line_total]);
        $this->assertSame('1500.00', $order->subtotal);
        $this->assertSame('1560.00', $order->total);
        $this->assertSame('0.00', $order->amount_paid);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNotSame('HACKED-1', $order->order_number);
        $this->assertSame('BDT', $order->currency);
        $this->assertNotSame(999, $order->customer_id);
        $this->assertSame(9, $this->product->refresh()->stock_quantity);
    }

    public function test_empty_item_rows_from_the_form_are_ignored(): void
    {
        $this->actingAs($this->user())->post(route('admin.orders.store'), $this->payload([
            'items' => [['product_id' => '', 'quantity' => ''], ['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => '', 'quantity' => '']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Order::sole()->items()->count());
    }

    /**
     * @return array<string, array{\Closure(self): array<string, mixed>, string}>
     */
    public static function invalidOrders(): array
    {
        return [
            'missing phone' => [fn () => ['customer_phone' => ''], 'customer_phone'],
            'invalid phone' => [fn () => ['customer_phone' => '01212345678'], 'customer_phone'],
            'missing name' => [fn () => ['customer_name' => ''], 'customer_name'],
            'missing address' => [fn () => ['shipping_address' => ''], 'shipping_address'],
            'invalid email' => [fn () => ['customer_email' => 'nope@'], 'customer_email'],
            'no items' => [fn () => ['items' => []], 'items'],
            'unknown product' => [fn () => ['items' => [['product_id' => 999999, 'quantity' => 1]]], 'items.0.product_id'],
            'zero quantity' => [fn ($test) => ['items' => [['product_id' => $test->product->id, 'quantity' => 0]]], 'items.0.quantity'],
            'negative quantity' => [fn ($test) => ['items' => [['product_id' => $test->product->id, 'quantity' => -1]]], 'items.0.quantity'],
            'fractional quantity' => [fn ($test) => ['items' => [['product_id' => $test->product->id, 'quantity' => 1.5]]], 'items.0.quantity'],
            'missing product in a row' => [fn () => ['items' => [['product_id' => '', 'quantity' => 2]]], 'items.0.product_id'],
            'duplicate product' => [fn ($test) => ['items' => [['product_id' => $test->product->id, 'quantity' => 1], ['product_id' => $test->product->id, 'quantity' => 1]]], 'items.1.product_id'],
            'more than in stock' => [fn ($test) => ['items' => [['product_id' => $test->product->id, 'quantity' => 11]]], 'items.0.quantity'],
            'negative delivery charge' => [fn () => ['delivery_charge' => '-1'], 'delivery_charge'],
            'delivery charge with 3 decimals' => [fn () => ['delivery_charge' => '60.001'], 'delivery_charge'],
            'non-numeric discount' => [fn () => ['discount_total' => 'ten'], 'discount_total'],
            'negative discount' => [fn () => ['discount_total' => '-5'], 'discount_total'],
            'invalid payment method' => [fn () => ['payment_method' => 'crypto'], 'payment_method'],
            'invalid source' => [fn () => ['source' => 'tiktok'], 'source'],
        ];
    }

    #[DataProvider('invalidOrders')]
    public function test_invalid_orders_are_rejected(\Closure $overrides, string $field): void
    {
        $this->actingAs($this->user())
            ->post(route('admin.orders.store'), $this->payload($overrides($this)))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->product->refresh()->stock_quantity);
    }

    public function test_archived_products_are_rejected_with_a_field_error(): void
    {
        $archived = Product::factory()->archived()->withStock(5)->create(['name' => 'Old Kurta']);

        $this->actingAs($this->user())
            ->post(route('admin.orders.store'), $this->payload(['items' => [['product_id' => $archived->id, 'quantity' => 1]]]))
            ->assertSessionHasErrors(['items.0.product_id' => '“Old Kurta” is not available for sale (Archived).']);

        $this->assertSame(0, Order::count());
    }

    public function test_blocked_customers_are_rejected_with_a_field_error(): void
    {
        Customer::factory()->blocked()->create(['phone' => '01712345678', 'name' => 'Fake Orderer']);

        $this->actingAs($this->user())
            ->post(route('admin.orders.store'), $this->payload())
            ->assertSessionHasErrors(['customer_phone' => 'Fake Orderer is blocked and cannot place orders.']);

        $this->assertSame(0, Order::count());
    }

    public function test_a_discount_above_the_subtotal_is_reported_and_nothing_is_created(): void
    {
        $this->actingAs($this->user())
            ->from(route('admin.orders.create'))
            ->post(route('admin.orders.store'), $this->payload(['discount_total' => '5000.00']))
            ->assertRedirect(route('admin.orders.create'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'cannot exceed the items subtotal'));

        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->product->refresh()->stock_quantity);
    }

    public function test_the_create_form_lists_active_products_and_can_prefill_a_customer(): void
    {
        Product::factory()->archived()->create(['name' => 'Archived Thing']);
        $customer = Customer::factory()->create(['name' => 'Nasrin Sultana', 'phone' => '01812345678']);

        $this->actingAs($this->user())
            ->get(route('admin.orders.create', ['customer' => $customer->id]))
            ->assertOk()
            ->assertSee('Cotton Panjabi (PANJ-1)')
            ->assertDontSee('Archived Thing')
            ->assertSee('value="01812345678"', false)
            ->assertSee('Nasrin Sultana');
    }

    // --- Listing & viewing ---------------------------------------------------------------

    public function test_the_list_shows_orders_with_search_and_filters(): void
    {
        $match = Order::factory()->status(OrderStatus::Confirmed)->create(['order_number' => 'ORD-261004-AAAAAA', 'customer_name' => 'Karim', 'customer_phone' => '01911111111', 'source' => OrderSource::Facebook]);
        Order::factory()->create(['order_number' => 'ORD-261004-BBBBBB', 'customer_name' => 'Salma', 'customer_phone' => '01922222222']);
        $user = $this->user(UserRole::Staff);

        $this->actingAs($user)->get(route('admin.orders.index'))->assertOk()->assertSee('ORD-261004-AAAAAA')->assertSee('ORD-261004-BBBBBB');
        $this->actingAs($user)->get(route('admin.orders.index', ['search' => 'AAAAAA']))->assertSee('ORD-261004-AAAAAA')->assertDontSee('ORD-261004-BBBBBB');
        $this->actingAs($user)->get(route('admin.orders.index', ['search' => '+880 1911-111111']))->assertSee('ORD-261004-AAAAAA')->assertDontSee('ORD-261004-BBBBBB');
        $this->actingAs($user)->get(route('admin.orders.index', ['search' => 'salma']))->assertSee('ORD-261004-BBBBBB')->assertDontSee('ORD-261004-AAAAAA');
        $this->actingAs($user)->get(route('admin.orders.index', ['status' => 'confirmed']))->assertSee('ORD-261004-AAAAAA')->assertDontSee('ORD-261004-BBBBBB');
        $this->actingAs($user)->get(route('admin.orders.index', ['source' => 'facebook']))->assertSee('ORD-261004-AAAAAA')->assertDontSee('ORD-261004-BBBBBB');
        $this->actingAs($user)->get(route('admin.orders.index', ['payment_status' => 'paid']))->assertSee('No orders match your filters.');
        $this->assertTrue($match->exists);
    }

    public function test_the_date_filter(): void
    {
        $old = Order::factory()->create(['order_number' => 'ORD-260901-OLDONE']);
        $old->forceFill(['created_at' => now()->subMonth()])->save();
        Order::factory()->create(['order_number' => 'ORD-261004-NEWONE']);

        $this->actingAs($this->user())
            ->get(route('admin.orders.index', ['from' => now()->subDay()->format('Y-m-d'), 'to' => now()->format('Y-m-d')]))
            ->assertSee('ORD-261004-NEWONE')
            ->assertDontSee('ORD-260901-OLDONE');
    }

    public function test_the_list_is_paginated(): void
    {
        Order::factory()->count(30)->create();

        $this->actingAs($this->user())
            ->get(route('admin.orders.index', ['page' => 2]))
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 30 && $orders->count() === 5);
    }

    public function test_the_details_page_shows_everything_about_the_order(): void
    {
        $this->actingAs($manager = $this->user())->post(route('admin.orders.store'), $this->payload([
            'discount_total' => '100.00', 'customer_note' => 'Leave at gate', 'admin_note' => 'Repeat buyer',
        ]));
        $order = Order::sole();

        $this->actingAs($manager)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Rahima Akter')
            ->assertSee('01712345678')
            ->assertSee('House 12, Road 5')
            ->assertSee('Cotton Panjabi')
            ->assertSee('PANJ-1')
            ->assertSeeInOrder(['Subtotal', '৳3,000.00', 'Order discount', '৳100.00', 'Delivery charge', '৳60.00', 'Total', '৳2,960.00'])
            ->assertSee('Leave at gate')
            ->assertSee('Repeat buyer')
            ->assertSee('Pending')
            ->assertSee('Confirm order')
            ->assertSee('Cancel order')
            ->assertSee('Record payment')
            ->assertSee('Order placed')
            ->assertSee('Sale · Cotton Panjabi');
    }

    // --- Status / payments over HTTP ------------------------------------------------------

    public function test_status_can_be_changed_and_invalid_transitions_are_reported(): void
    {
        $order = Order::factory()->create();
        $manager = $this->user();

        $this->actingAs($manager)
            ->patch(route('admin.orders.status', $order), ['status' => 'confirmed'])
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('success', "Order {$order->order_number} is now Confirmed.");

        $this->actingAs($manager)
            ->from(route('admin.orders.show', $order))
            ->patch(route('admin.orders.status', $order), ['status' => 'delivered'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'cannot move from Confirmed to Delivered'));

        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
    }

    public function test_cancelling_requires_a_reason_and_restores_stock(): void
    {
        $this->actingAs($manager = $this->user())->post(route('admin.orders.store'), $this->payload());
        $order = Order::sole();

        $this->actingAs($manager)->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])->assertSessionHasErrorsIn('status', 'note');
        $this->assertSame(8, $this->product->refresh()->stock_quantity);

        $this->actingAs($manager)->patch(route('admin.orders.status', $order), ['status' => 'cancelled', 'note' => 'Customer request']);
        $this->assertSame(10, $this->product->refresh()->stock_quantity);

        $this->actingAs($manager)->patch(route('admin.orders.status', $order), ['status' => 'cancelled', 'note' => 'Again']);
        $this->assertSame(10, $this->product->refresh()->stock_quantity);
        $this->assertSame(1, StockMovement::where('type', 'cancellation')->count());
    }

    public function test_payments_and_refunds_over_http(): void
    {
        $order = Order::factory()->create(['total' => '1060.00']);

        $this->actingAs($this->user())
            ->post(route('admin.orders.payments.store', $order), ['amount' => '1060.00', 'method' => 'cod', 'status' => 'completed', 'transaction_reference' => 'RCPT-77'])
            ->assertSessionHas('success', 'Payment of ৳1,060.00 recorded.');
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);

        $this->actingAs($this->user(UserRole::Admin))
            ->post(route('admin.orders.refunds.store', $order), ['amount' => '200.00', 'method' => 'bank_transfer', 'note' => 'Late delivery'])
            ->assertSessionHas('success', 'Refund of ৳200.00 recorded.');
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->refresh()->payment_status);
        $this->assertSame('860.00', $order->amount_paid);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayments(): array
    {
        return [
            'zero' => [['amount' => '0', 'method' => 'cod', 'status' => 'completed'], 'amount'],
            'negative' => [['amount' => '-10', 'method' => 'cod', 'status' => 'completed'], 'amount'],
            'three decimals' => [['amount' => '10.001', 'method' => 'cod', 'status' => 'completed'], 'amount'],
            'pending status is gateway-only' => [['amount' => '10', 'method' => 'cod', 'status' => 'pending'], 'status'],
            'unknown method' => [['amount' => '10', 'method' => 'cheque', 'status' => 'completed'], 'method'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidPayments')]
    public function test_invalid_payments_are_rejected(array $input, string $field): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->user())->post(route('admin.orders.payments.store', $order), $input)->assertSessionHasErrorsIn('payment', $field);

        $this->assertSame(0, Payment::count());
    }

    public function test_each_order_form_reports_errors_in_its_own_panel(): void
    {
        $order = Order::factory()->create(['total' => '1060.00']);
        $manager = $this->user();

        $this->actingAs($manager)->from(route('admin.orders.show', $order))
            ->patch(route('admin.orders.status', $order), ['status' => 'cancelled']);

        $html = $this->actingAs($manager)->get(route('admin.orders.show', $order))->getContent();
        $this->assertSame(1, substr_count($html, 'Give a reason when cancelling or returning an order.'));
    }

    public function test_an_overpayment_is_reported(): void
    {
        $order = Order::factory()->create(['total' => '1060.00']);

        $this->actingAs($this->user())
            ->post(route('admin.orders.payments.store', $order), ['amount' => '2000.00', 'method' => 'cod', 'status' => 'completed'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'more than the amount due'));

        $this->assertSame(0, Payment::count());
    }

    // --- Editing ----------------------------------------------------------------------------

    public function test_delivery_details_can_be_corrected_before_shipping(): void
    {
        $order = Order::factory()->create(['total' => '1060.00']);

        $this->actingAs($this->user())->put(route('admin.orders.update', $order), [
            'customer_name' => 'Rahima (corrected)', 'customer_phone' => '8801712000000', 'shipping_address' => 'New address',
            'shipping_city' => 'Gazipur', 'admin_note' => 'Address fixed by phone', 'total' => '1.00', 'status' => 'delivered',
        ])->assertRedirect(route('admin.orders.show', $order));

        $order->refresh();
        $this->assertSame('Rahima (corrected)', $order->customer_name);
        $this->assertSame('01712000000', $order->customer_phone);
        $this->assertSame('New address', $order->shipping_address);
        $this->assertSame('Address fixed by phone', $order->admin_note);
        $this->assertSame('1060.00', $order->total);
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    public function test_shipped_orders_only_allow_the_internal_note_to_change(): void
    {
        $order = Order::factory()->status(OrderStatus::Shipped)->create(['shipping_address' => 'Original']);

        $this->actingAs($this->user())->get(route('admin.orders.edit', $order))->assertSee('delivery details are locked');

        $this->actingAs($this->user())->put(route('admin.orders.update', $order), [
            'shipping_address' => 'Changed after shipping', 'admin_note' => 'Courier delayed',
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('Original', $order->shipping_address);
        $this->assertSame('Courier delayed', $order->admin_note);
    }

    public function test_order_items_cannot_be_edited(): void
    {
        $item = OrderItem::factory()->create(['quantity' => 2]);

        $this->actingAs($this->user())->put(route('admin.orders.update', $item->order), [
            'customer_name' => 'X', 'customer_phone' => '01712345678', 'shipping_address' => 'Y',
            'items' => [['product_id' => $item->product_id, 'quantity' => 99]],
        ]);

        $this->assertSame(2, $item->refresh()->quantity);
    }

    // --- Authorization -----------------------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $order = Order::factory()->create();

        $this->get(route('admin.orders.index'))->assertRedirect(route('login'));
        $this->get(route('admin.orders.show', $order))->assertRedirect(route('login'));
        $this->post(route('admin.orders.store'), $this->payload())->assertRedirect(route('login'));
        $this->patch(route('admin.orders.status', $order), ['status' => 'confirmed'])->assertRedirect(route('login'));
        $this->post(route('admin.orders.payments.store', $order), ['amount' => '10', 'method' => 'cod', 'status' => 'completed'])->assertRedirect(route('login'));

        $this->assertSame(1, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_staff_can_view_orders_but_every_mutation_is_forbidden(): void
    {
        $order = Order::factory()->create(['total' => '1060.00']);
        Payment::factory()->for($order)->completed()->create(['amount' => '500.00']);
        $staff = $this->user(UserRole::Staff);

        $this->actingAs($staff)->get(route('admin.orders.index'))->assertOk()->assertDontSee('New order');
        $this->actingAs($staff)->get(route('admin.orders.show', $order))->assertOk()
            ->assertDontSee('Confirm order')->assertDontSee('Record payment')->assertDontSee('Record refund')->assertDontSee('Edit details');

        $this->actingAs($staff)->get(route('admin.orders.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.orders.store'), $this->payload())->assertForbidden();
        $this->actingAs($staff)->get(route('admin.orders.edit', $order))->assertForbidden();
        $this->actingAs($staff)->put(route('admin.orders.update', $order), ['admin_note' => 'x'])->assertForbidden();
        $this->actingAs($staff)->patch(route('admin.orders.status', $order), ['status' => 'confirmed'])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.orders.payments.store', $order), ['amount' => '10', 'method' => 'cod', 'status' => 'completed'])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.orders.refunds.store', $order), ['amount' => '10', 'method' => 'cod', 'note' => 'x'])->assertForbidden();

        $this->assertSame(1, Order::count());
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(1, Payment::count());
    }

    public function test_managers_can_operate_orders_but_not_refund(): void
    {
        $order = Order::factory()->create(['total' => '1060.00']);
        Payment::factory()->for($order)->completed()->create(['amount' => '500.00']);
        $manager = $this->user(UserRole::Manager);

        $this->actingAs($manager)->get(route('admin.orders.show', $order))->assertSee('Confirm order')->assertSee('Record payment')->assertDontSee('Record refund');
        $this->actingAs($manager)->post(route('admin.orders.refunds.store', $order), ['amount' => '10', 'method' => 'cod', 'note' => 'x'])->assertForbidden();
        $this->actingAs($manager)->patch(route('admin.orders.status', $order), ['status' => 'confirmed'])->assertRedirect();

        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function refundRoles(): array
    {
        return ['admin' => [UserRole::Admin], 'super admin' => [UserRole::SuperAdmin]];
    }

    #[DataProvider('refundRoles')]
    public function test_admins_and_super_admins_can_do_everything_including_refunds(UserRole $role): void
    {
        $user = $this->user($role);

        $this->actingAs($user)->post(route('admin.orders.store'), $this->payload())->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->actingAs($user)->patch(route('admin.orders.status', $order), ['status' => 'confirmed'])->assertRedirect();
        $this->actingAs($user)->post(route('admin.orders.payments.store', $order), ['amount' => '500.00', 'method' => 'cod', 'status' => 'completed'])->assertSessionHas('success');
        $this->actingAs($user)->post(route('admin.orders.refunds.store', $order), ['amount' => '100.00', 'method' => 'cod', 'note' => 'Goodwill'])->assertSessionHas('success');
        $this->actingAs($user)->get(route('admin.orders.show', $order))->assertSee('Record refund');

        $this->assertSame('400.00', $order->refresh()->amount_paid);
    }

    public function test_orders_cannot_be_deleted_by_anyone(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->user(UserRole::SuperAdmin))->delete('/admin/orders/'.$order->id)->assertMethodNotAllowed();

        $this->assertModelExists($order);
    }
}
