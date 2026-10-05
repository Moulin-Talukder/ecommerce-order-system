<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordPayment;
use App\Actions\Orders\RecordRefund;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_order_lifecycle_appears_in_chronological_order(): void
    {
        $manager = User::factory()->manager()->create(['name' => 'Manager Mina']);
        $admin = User::factory()->admin()->create(['name' => 'Admin Arif']);
        $product = Product::factory()->withStock(5)->create(['selling_price' => '1000.00']);

        $this->travelTo(now()->setTime(10, 0));
        $order = app(CreateOrder::class)->handle([
            'customer_name' => 'Rahima', 'customer_phone' => '01712345678', 'shipping_address' => 'Dhaka',
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'delivery_charge' => '60',
            'payment_method' => PaymentMethod::CashOnDelivery->value, 'source' => OrderSource::Facebook->value,
        ], $manager);
        foreach ([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered] as $minutes => $status) {
            $this->travelTo(now()->setTime(10, $minutes + 1));
            app(ChangeOrderStatus::class)->handle($order, $status, $manager);
        }
        $this->travelTo(now()->setTime(11, 0));
        app(RecordPayment::class)->handle($order, '500.00', PaymentMethod::CashOnDelivery, PaymentTransactionStatus::Failed, $manager, note: 'Customer not home');
        $this->travelTo(now()->setTime(11, 30));
        app(RecordPayment::class)->handle($order, '1060.00', PaymentMethod::CashOnDelivery, PaymentTransactionStatus::Completed, $manager, 'SF-123');
        $this->travelTo(now()->setTime(12, 0));
        app(ChangeOrderStatus::class)->handle($order, OrderStatus::Returned, $manager, 'Wrong size');
        $this->travelTo(now()->setTime(12, 5));
        app(RecordRefund::class)->handle($order, '1060.00', PaymentMethod::BankTransfer, $admin, note: 'Full refund');

        $entries = app(OrderTimeline::class)->for($order->fresh());

        $this->assertSame([
            'Order placed (Facebook)',
            'Pending → Confirmed',
            'Confirmed → Processing',
            'Processing → Shipped',
            'Shipped → Delivered',
            'Failed payment attempt of ৳500.00 (Cash on Delivery)',
            'Payment of ৳1,060.00 received (Cash on Delivery)',
            'Delivered → Returned',
            'Refund of ৳1,060.00 (Bank Transfer)',
        ], $entries->pluck('title')->all());
        $this->assertSame(['created', 'status', 'status', 'status', 'status', 'payment_failed', 'payment', 'status', 'refund'], $entries->pluck('kind')->all());
        $this->assertSame('Manager Mina', $entries[0]->actor);
        $this->assertSame('Admin Arif', $entries[8]->actor);
        $this->assertSame('Customer not home', $entries[5]->detail);
        $this->assertSame('Ref SF-123', $entries[6]->detail);
        $this->assertSame('Wrong size', $entries[7]->detail);
        $this->assertSame(['blue', 'gray', 'gray', 'gray', 'green', 'yellow', 'green', 'red', 'red'], $entries->pluck('tone')->all());
    }

    public function test_entries_in_the_same_second_keep_a_stable_order(): void
    {
        $order = Order::factory()->create();
        $at = now()->startOfMinute();
        Payment::factory()->for($order)->completed()->create(['created_at' => $at, 'amount' => '10.00']);
        OrderStatusHistory::factory()->for($order)->transition(null, OrderStatus::Pending)->create(['created_at' => $at]);
        OrderStatusHistory::factory()->for($order)->transition(OrderStatus::Pending, OrderStatus::Confirmed)->create(['created_at' => $at]);

        $kinds = app(OrderTimeline::class)->for($order)->pluck('kind')->all();

        $this->assertSame(['created', 'status', 'payment'], $kinds, 'Status changes come before payments recorded in the same second, then by id.');
    }

    public function test_system_actions_and_deleted_users_show_as_system(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        OrderStatusHistory::factory()->for($order)->transition(null, OrderStatus::Pending)->create(['changed_by' => null]);
        OrderStatusHistory::factory()->for($order)->transition(OrderStatus::Pending, OrderStatus::Confirmed)->create(['changed_by' => $user->id]);
        $user->delete();

        $entries = app(OrderTimeline::class)->for($order);

        $this->assertSame([null, null], $entries->pluck('actor')->all());
    }

    public function test_an_order_without_history_has_an_empty_timeline(): void
    {
        $this->assertTrue(app(OrderTimeline::class)->for(Order::factory()->create())->isEmpty());
    }
}
