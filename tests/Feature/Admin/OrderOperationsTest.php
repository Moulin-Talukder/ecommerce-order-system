<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\CourierShipment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 8: operational order screens (timeline, queues, filters, navigation).
 */
class OrderOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Manager): User
    {
        return User::factory()->role($role)->create();
    }

    // --- URLs ------------------------------------------------------------------------------

    public function test_order_urls_use_the_order_number_not_the_database_id(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-261004-ABCDEF']);
        $user = $this->user();

        $this->assertStringEndsWith('/admin/orders/ORD-261004-ABCDEF', route('admin.orders.show', $order));
        $this->actingAs($user)->get('/admin/orders/ORD-261004-ABCDEF')->assertOk()->assertSee('ORD-261004-ABCDEF');
        $this->actingAs($user)->get('/admin/orders/'.$order->id)->assertNotFound();
    }

    public function test_guests_cannot_open_an_order_by_number(): void
    {
        $order = Order::factory()->create();

        $this->get(route('admin.orders.show', $order))->assertRedirect(route('login'));
    }

    // --- Timeline & history ---------------------------------------------------------------

    public function test_the_details_page_shows_the_timeline_in_order_with_actors_and_reasons(): void
    {
        $order = Order::factory()->create();
        $mina = User::factory()->create(['name' => 'Mina Manager']);
        OrderStatusHistory::factory()->for($order)->transition(null, OrderStatus::Pending)->create(['created_at' => now()->subHours(3), 'changed_by' => $mina->id]);
        OrderStatusHistory::factory()->for($order)->transition(OrderStatus::Pending, OrderStatus::Confirmed)->create(['created_at' => now()->subHours(2)]);
        Payment::factory()->for($order)->completed()->create(['created_at' => now()->subHour(), 'amount' => '100.00', 'transaction_reference' => 'BKASH-9']);
        OrderStatusHistory::factory()->for($order)->transition(OrderStatus::Confirmed, OrderStatus::Cancelled)->create(['note' => 'Customer unreachable']);

        $this->actingAs($this->user())
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSeeInOrder(['Timeline', 'Order placed', 'Mina Manager', 'Pending → Confirmed', 'System', 'Payment of ৳100.00 received', 'Ref BKASH-9', 'Confirmed → Cancelled', 'Customer unreachable']);
    }

    public function test_payment_history_is_listed_with_failed_attempts_and_refunds(): void
    {
        $order = Order::factory()->create(['total' => '1060.00']);
        Payment::factory()->for($order)->create(['status' => 'failed', 'amount' => '1060.00']);
        Payment::factory()->for($order)->completed()->create(['amount' => '1060.00']);
        Payment::factory()->for($order)->refund()->completed()->create(['amount' => '60.00', 'note' => 'Late delivery']);

        $this->actingAs($this->user())
            ->get(route('admin.orders.show', $order))
            ->assertSee('Payment history')
            ->assertSee('Failed · ')
            ->assertSee('Refund · Cash on Delivery')
            ->assertSee('−৳60.00')
            ->assertSee('Late delivery');
    }

    public function test_an_order_without_payments_says_so(): void
    {
        $this->actingAs($this->user())
            ->get(route('admin.orders.show', Order::factory()->create()))
            ->assertSee('No payments recorded.');
    }

    // --- Status actions --------------------------------------------------------------------

    /**
     * @return array<string, array{OrderStatus, list<string>, list<string>}>
     */
    public static function statusActions(): array
    {
        $all = ['Confirm order', 'Start processing', 'Mark shipped', 'Mark delivered', 'Cancel order', 'Mark returned'];

        return [
            'pending' => [OrderStatus::Pending, ['Confirm order', 'Cancel order'], $all],
            'confirmed' => [OrderStatus::Confirmed, ['Start processing', 'Cancel order'], $all],
            'processing' => [OrderStatus::Processing, ['Mark shipped', 'Cancel order'], $all],
            'shipped' => [OrderStatus::Shipped, ['Mark delivered', 'Mark returned'], $all],
            'delivered' => [OrderStatus::Delivered, ['Mark returned'], $all],
            'cancelled' => [OrderStatus::Cancelled, [], $all],
            'returned' => [OrderStatus::Returned, [], $all],
        ];
    }

    /**
     * @param  list<string>  $visible
     * @param  list<string>  $all
     */
    #[DataProvider('statusActions')]
    public function test_only_valid_next_actions_are_offered(OrderStatus $status, array $visible, array $all): void
    {
        $order = Order::factory()->status($status)->create();

        $response = $this->actingAs($this->user())->get(route('admin.orders.show', $order))->assertOk();

        foreach ($all as $label) {
            in_array($label, $visible, true)
                ? $response->assertSee($label.'</button>', false)
                : $response->assertDontSee($label.'</button>', false);
        }

        if ($visible === []) {
            $response->assertSee('no further status changes are possible');
        }
    }

    public function test_cancel_and_return_actions_ask_for_a_reason(): void
    {
        $order = Order::factory()->status(OrderStatus::Shipped)->create();

        $this->actingAs($this->user())
            ->get(route('admin.orders.show', $order))
            ->assertSee('name="note" required', false)
            ->assertSee('placeholder="Reason (required)"', false);
    }

    public function test_invalid_transitions_are_refused_over_json_too(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->user())
            ->patchJson(route('admin.orders.status', $order), ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Order {$order->order_number} cannot move from Pending to Delivered.");

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    public function test_unknown_status_values_are_rejected(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->user())
            ->patchJson(route('admin.orders.status', $order), ['status' => 'teleported'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    /**
     * @return array<string, array{UserRole, bool, bool, bool}>
     */
    public static function roleActions(): array
    {
        // role => [sees status actions, sees record payment, sees refund]
        return [
            'staff' => [UserRole::Staff, false, false, false],
            'manager' => [UserRole::Manager, true, true, false],
            'admin' => [UserRole::Admin, true, true, true],
            'super admin' => [UserRole::SuperAdmin, true, true, true],
        ];
    }

    #[DataProvider('roleActions')]
    public function test_actions_shown_match_the_policy(UserRole $role, bool $statusActions, bool $recordPayment, bool $refund): void
    {
        $order = Order::factory()->create(['total' => '1060.00']);
        Payment::factory()->for($order)->completed()->create(['amount' => '500.00']);

        $response = $this->actingAs($this->user($role))->get(route('admin.orders.show', $order))->assertOk();

        $statusActions ? $response->assertSee('Update status') : $response->assertDontSee('Update status');
        $recordPayment ? $response->assertSee('Record payment</button>', false) : $response->assertDontSee('Record payment</button>', false);
        $refund ? $response->assertSee('Record refund') : $response->assertDontSee('Record refund');
    }

    // --- Queues & filters ----------------------------------------------------------------------

    public function test_queue_tabs_show_counts_and_filter_the_list(): void
    {
        $pending = Order::factory()->create(['order_number' => 'ORD-261004-PENDNG']);
        $confirmed = Order::factory()->status(OrderStatus::Confirmed)->create(['order_number' => 'ORD-261004-CONFRM']);
        $packed = Order::factory()->status(OrderStatus::Processing)->create(['order_number' => 'ORD-261004-PACKED']);
        $booked = Order::factory()->status(OrderStatus::Processing)->create(['order_number' => 'ORD-261004-BOOKED']);
        CourierShipment::factory()->for($booked)->create();
        $shipped = Order::factory()->status(OrderStatus::Shipped)->create(['order_number' => 'ORD-261004-SHIPPD']);
        $unpaid = Order::factory()->status(OrderStatus::Delivered)->create(['order_number' => 'ORD-261004-UNPAID']);
        $paid = Order::factory()->status(OrderStatus::Delivered)->create(['order_number' => 'ORD-261004-PAIDUP']);
        $paid->forceFill(['payment_status' => PaymentStatus::Paid])->save();
        $cancelled = Order::factory()->status(OrderStatus::Cancelled)->create(['order_number' => 'ORD-261004-CANCLD']);
        $user = $this->user(UserRole::Staff);

        $this->actingAs($user)->get(route('admin.orders.index'))
            ->assertViewHas('viewCounts', [
                'needs_confirmation' => 1, 'to_fulfil' => 3, 'ready_for_courier' => 1, 'in_transit' => 1,
                'awaiting_payment' => 1, 'completed' => 1, 'closed' => 1,
            ]);

        $expectations = [
            'needs_confirmation' => [$pending],
            'to_fulfil' => [$confirmed, $packed, $booked],
            'ready_for_courier' => [$packed],
            'in_transit' => [$shipped],
            'awaiting_payment' => [$unpaid],
            'completed' => [$paid],
            'closed' => [$cancelled],
        ];

        foreach ($expectations as $view => $expected) {
            $this->actingAs($user)->get(route('admin.orders.index', ['view' => $view]))
                ->assertViewHas('orders', fn ($orders) => $orders->pluck('order_number')->sort()->values()->all()
                    === collect($expected)->pluck('order_number')->sort()->values()->all());
        }
    }

    public function test_unknown_queue_names_are_ignored(): void
    {
        Order::factory()->count(2)->create();

        $this->actingAs($this->user())
            ->get(route('admin.orders.index', ['view' => 'drop table orders']))
            ->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 2);
    }

    public function test_courier_booking_filter(): void
    {
        $booked = Order::factory()->status(OrderStatus::Shipped)->create(['order_number' => 'ORD-261004-BOOKED']);
        CourierShipment::factory()->for($booked)->create();
        Order::factory()->create(['order_number' => 'ORD-261004-NOTBKD']);
        $user = $this->user();

        $this->actingAs($user)->get(route('admin.orders.index', ['courier' => 'booked']))->assertSee('ORD-261004-BOOKED')->assertDontSee('ORD-261004-NOTBKD');
        $this->actingAs($user)->get(route('admin.orders.index', ['courier' => 'not_booked']))->assertSee('ORD-261004-NOTBKD')->assertDontSee('ORD-261004-BOOKED');
    }

    public function test_payment_status_filter_for_partially_paid_orders(): void
    {
        $partial = Order::factory()->create(['order_number' => 'ORD-261004-PARTLY']);
        $partial->forceFill(['payment_status' => PaymentStatus::PartiallyPaid])->save();
        Order::factory()->create(['order_number' => 'ORD-261004-NOTPAY']);

        $this->actingAs($this->user())
            ->get(route('admin.orders.index', ['payment_status' => 'partially_paid']))
            ->assertSee('ORD-261004-PARTLY')
            ->assertDontSee('ORD-261004-NOTPAY');
    }

    public function test_order_number_search_is_a_case_insensitive_prefix_match(): void
    {
        Order::factory()->create(['order_number' => 'ORD-261004-ABCDEF']);
        Order::factory()->create(['order_number' => 'ORD-261005-XYZXYZ']);
        $user = $this->user();

        $this->actingAs($user)->get(route('admin.orders.index', ['search' => 'ord-261004']))->assertSee('ORD-261004-ABCDEF')->assertDontSee('ORD-261005-XYZXYZ');
        $this->actingAs($user)->get(route('admin.orders.index', ['search' => 'XYZXYZ']))->assertSee('ORD-261005-XYZXYZ')->assertDontSee('ORD-261004-ABCDEF');
        $this->actingAs($user)->get(route('admin.orders.index', ['search' => 'ORD-%']))->assertSee('No orders match your filters.');
    }

    public function test_date_filters_and_invalid_dates(): void
    {
        $yesterday = Order::factory()->create(['order_number' => 'ORD-261003-YESTER']);
        $yesterday->forceFill(['created_at' => now()->subDay()->setTime(23, 59)])->save();
        Order::factory()->create(['order_number' => 'ORD-261004-TODAYY']);
        $user = $this->user();
        $today = now()->format('Y-m-d');

        $this->actingAs($user)->get(route('admin.orders.index', ['from' => $today, 'to' => $today]))->assertSee('ORD-261004-TODAYY')->assertDontSee('ORD-261003-YESTER');
        $this->actingAs($user)->get(route('admin.orders.index', ['to' => now()->subDay()->format('Y-m-d')]))->assertSee('ORD-261003-YESTER')->assertDontSee('ORD-261004-TODAYY');
        $this->actingAs($user)->get(route('admin.orders.index', ['from' => '2026-13-45', 'to' => 'yesterday']))
            ->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 2);
    }

    public function test_pagination_links_keep_the_active_filters(): void
    {
        Order::factory()->count(30)->status(OrderStatus::Confirmed)->create();

        $this->actingAs($this->user())
            ->get(route('admin.orders.index', ['view' => 'to_fulfil']))
            ->assertSee('view=to_fulfil&amp;page=2', false);

        $this->actingAs($this->user())
            ->get(route('admin.orders.index', ['view' => 'to_fulfil', 'page' => 2]))
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 5);
    }

    public function test_empty_states(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('admin.orders.index'))->assertSee('No orders yet.');
        $this->actingAs($user)->get(route('admin.orders.index', ['view' => 'in_transit']))->assertSee('No orders match your filters.');
    }

    // --- Customer / product navigation ------------------------------------------------------------

    public function test_order_list_can_be_filtered_by_customer_without_leaking_other_customers(): void
    {
        $customer = Customer::factory()->create(['name' => 'Nasrin Sultana']);
        Order::factory()->for($customer)->count(2)->create();
        Order::factory()->create(['order_number' => 'ORD-261004-OTHERS']);

        $this->actingAs($this->user())
            ->get(route('admin.orders.index', ['customer' => $customer->id]))
            ->assertSee('Showing orders from')
            ->assertSee('Nasrin Sultana')
            ->assertDontSee('ORD-261004-OTHERS')
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 2);
    }

    public function test_the_order_page_links_to_the_customer_and_their_other_orders(): void
    {
        $customer = Customer::factory()->create();
        $earlier = Order::factory()->for($customer)->status(OrderStatus::Returned)->create(['order_number' => 'ORD-260901-EARLY1']);
        $current = Order::factory()->for($customer)->create(['order_number' => 'ORD-261004-CURRNT']);
        Order::factory()->create(['order_number' => 'ORD-261004-STRNGR']);

        $this->actingAs($this->user())
            ->get(route('admin.orders.show', $current))
            ->assertSee(route('admin.customers.show', $customer))
            ->assertSee(route('admin.orders.show', $earlier))
            ->assertSee(route('admin.orders.index', ['customer' => $customer->id]))
            ->assertDontSee('ORD-261004-STRNGR')
            ->assertSeeInOrder(['Customer history', 'Cancelled / returned', '1']);
    }

    public function test_a_first_time_customer_is_labelled(): void
    {
        $this->actingAs($this->user())
            ->get(route('admin.orders.show', Order::factory()->create()))
            ->assertSee("This is the customer's first order.", false);
    }

    public function test_the_order_page_links_items_to_their_products(): void
    {
        $item = OrderItem::factory()->create(['product_name' => 'Snapshot Name']);

        $this->actingAs($this->user())
            ->get(route('admin.orders.show', $item->order))
            ->assertSee(route('admin.products.show', $item->product))
            ->assertSee('Snapshot Name');
    }

    public function test_the_customer_page_links_into_the_filtered_order_list(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->user())
            ->get(route('admin.customers.show', $order->customer))
            ->assertSee(route('admin.orders.index', ['customer' => $order->customer_id]))
            ->assertSee(route('admin.orders.show', $order));
    }

    // --- Performance -------------------------------------------------------------------------------

    public function test_the_order_list_runs_a_constant_number_of_queries(): void
    {
        $user = $this->user();
        Order::factory()->count(3)->create();
        $few = $this->countQueries(fn () => $this->actingAs($user)->get(route('admin.orders.index'))->assertOk());

        Order::factory()->count(20)->create();
        $many = $this->countQueries(fn () => $this->actingAs($user)->get(route('admin.orders.index'))->assertOk());

        $this->assertSame($few, $many);
    }

    public function test_the_order_page_runs_a_constant_number_of_queries(): void
    {
        $user = $this->user();
        $small = Order::factory()->create();
        OrderItem::factory()->for($small)->create();
        Payment::factory()->for($small)->completed()->create(['amount' => '1.00']);
        $smallCount = $this->countQueries(fn () => $this->actingAs($user)->get(route('admin.orders.show', $small))->assertOk());

        $large = Order::factory()->create(['total' => '9999.00']);
        OrderItem::factory()->for($large)->count(6)->create();
        Payment::factory()->for($large)->completed()->count(5)->create(['amount' => '1.00']);
        OrderStatusHistory::factory()->for($large)->count(5)->create();
        Order::factory()->for($large->customer)->count(4)->create();
        $largeCount = $this->countQueries(fn () => $this->actingAs($user)->get(route('admin.orders.show', $large))->assertOk());

        $this->assertSame($smallCount, $largeCount);
    }

    private function countQueries(\Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
