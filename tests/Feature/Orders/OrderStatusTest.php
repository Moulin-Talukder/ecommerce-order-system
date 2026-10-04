<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Events\OrderStatusChanged;
use App\Exceptions\Orders\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->manager()->create();
    }

    /**
     * Place a real order (stock deducted through StockService).
     *
     * @param  array<int, int>  $quantities  product id => quantity
     */
    private function placeOrder(array $quantities): Order
    {
        return app(CreateOrder::class)->handle([
            'customer_name' => 'Rahima', 'customer_phone' => '01712345678', 'shipping_address' => 'Dhaka',
            'items' => collect($quantities)->map(fn ($qty, $id) => ['product_id' => $id, 'quantity' => $qty])->values()->all(),
            'delivery_charge' => '60', 'payment_method' => PaymentMethod::CashOnDelivery->value, 'source' => OrderSource::Phone->value,
        ], $this->user);
    }

    private function change(Order $order, OrderStatus $to, ?string $note = null): Order
    {
        return app(ChangeOrderStatus::class)->handle($order, $to, $this->user, $note);
    }

    private function moveThrough(Order $order, OrderStatus ...$statuses): Order
    {
        foreach ($statuses as $status) {
            $this->change($order, $status);
        }

        return $order;
    }

    public function test_the_full_happy_path_is_recorded_in_history(): void
    {
        $order = Order::factory()->create();

        $this->moveThrough($order, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered);

        $this->assertSame(OrderStatus::Delivered, $order->refresh()->status);
        $history = $order->statusHistories()->get();
        $this->assertSame(
            [['pending', 'confirmed'], ['confirmed', 'processing'], ['processing', 'shipped'], ['shipped', 'delivered']],
            $history->map(fn ($row) => [$row->old_status->value, $row->new_status->value])->all(),
        );
        $this->assertTrue($history->every(fn ($row) => $row->changed_by === $this->user->id));
    }

    public function test_a_note_is_stored_with_the_transition(): void
    {
        $order = Order::factory()->create();

        $this->change($order, OrderStatus::Cancelled, 'Customer changed their mind');

        $this->assertSame('Customer changed their mind', $order->statusHistories()->latest('id')->value('note'));
    }

    public function test_invalid_jumps_are_rejected_without_side_effects(): void
    {
        $order = Order::factory()->create();

        foreach ([OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Returned, OrderStatus::Pending] as $target) {
            try {
                $this->change($order, $target);
                $this->fail("Pending → {$target->value} should be rejected.");
            } catch (InvalidOrderTransitionException $exception) {
                $this->assertStringContainsString('cannot move from Pending', $exception->getMessage());
            }
        }

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(0, $order->statusHistories()->count());
    }

    public function test_shipped_orders_cannot_be_cancelled(): void
    {
        $order = $this->moveThrough(Order::factory()->create(), OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped);

        $this->expectException(InvalidOrderTransitionException::class);

        $this->change($order, OrderStatus::Cancelled);
    }

    public function test_final_states_accept_no_further_transitions(): void
    {
        $cancelled = Order::factory()->status(OrderStatus::Cancelled)->create();
        $returned = Order::factory()->status(OrderStatus::Returned)->create();

        foreach ([$cancelled, $returned] as $order) {
            foreach (OrderStatus::cases() as $target) {
                try {
                    $this->change($order, $target);
                    $this->fail("{$order->status->value} → {$target->value} should be rejected.");
                } catch (InvalidOrderTransitionException) {
                }
            }
        }

        $this->assertSame(0, $cancelled->statusHistories()->count() + $returned->statusHistories()->count());
    }

    public function test_cancellation_restores_the_sold_stock(): void
    {
        $a = Product::factory()->withStock(10)->create();
        $b = Product::factory()->withStock(5)->create();
        $order = $this->placeOrder([$a->id => 3, $b->id => 5]);
        $this->assertSame([7, 0], [$a->refresh()->stock_quantity, $b->refresh()->stock_quantity]);

        $this->change($order, OrderStatus::Cancelled, 'Customer cancelled');

        $this->assertSame([10, 5], [$a->refresh()->stock_quantity, $b->refresh()->stock_quantity]);
        $restocks = $order->stockMovements()->where('type', StockMovementType::Cancellation)->orderBy('product_id')->get();
        $this->assertSame([[$a->id, 3, 10], [$b->id, 5, 5]], $restocks->map(fn ($m) => [$m->product_id, $m->quantity_change, $m->quantity_after])->all());
        $this->assertTrue($restocks->every(fn ($m) => $m->user_id === $this->user->id && $m->order_id === $order->id));
    }

    public function test_a_return_restores_the_sold_stock(): void
    {
        $product = Product::factory()->withStock(4)->create();
        $order = $this->placeOrder([$product->id => 2]);
        $this->moveThrough($order, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered);

        $this->change($order, OrderStatus::Returned, 'Wrong size');

        $this->assertSame(4, $product->refresh()->stock_quantity);
        $this->assertSame(1, $order->stockMovements()->where('type', StockMovementType::Return)->count());
    }

    public function test_repeating_a_cancellation_never_restores_stock_twice(): void
    {
        $product = Product::factory()->withStock(10)->create();
        $order = $this->placeOrder([$product->id => 4]);
        $this->change($order, OrderStatus::Cancelled, 'First');

        try {
            $this->change($order, OrderStatus::Cancelled, 'Retry');
            $this->fail('A repeated cancellation should be rejected.');
        } catch (InvalidOrderTransitionException) {
        }

        $this->assertSame(10, $product->refresh()->stock_quantity);
        $this->assertSame(1, $order->stockMovements()->where('type', StockMovementType::Cancellation)->count());
        $this->assertSame(2, $order->statusHistories()->count());
    }

    public function test_repeating_a_return_never_restores_stock_twice(): void
    {
        $product = Product::factory()->withStock(10)->create();
        $order = $this->placeOrder([$product->id => 4]);
        $this->moveThrough($order, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Returned);

        try {
            $this->change($order, OrderStatus::Returned);
        } catch (InvalidOrderTransitionException) {
        }

        $this->assertSame(10, $product->refresh()->stock_quantity);
        $this->assertSame(1, $order->stockMovements()->where('type', StockMovementType::Return)->count());
    }

    public function test_the_stock_guard_holds_even_if_an_order_is_forced_back_to_an_open_status(): void
    {
        // Simulates data repaired by hand: the ledger, not the status, decides whether stock was already restored.
        $product = Product::factory()->withStock(10)->create();
        $order = $this->placeOrder([$product->id => 4]);
        $this->change($order, OrderStatus::Cancelled, 'First');
        $order->forceFill(['status' => OrderStatus::Pending])->save();

        $this->change($order, OrderStatus::Cancelled, 'Second');

        $this->assertSame(10, $product->refresh()->stock_quantity);
        $this->assertSame(1, $order->stockMovements()->where('type', StockMovementType::Cancellation)->count());
    }

    public function test_cancellation_skips_products_that_no_longer_track_stock(): void
    {
        $tracked = Product::factory()->withStock(10)->create();
        $untracked = Product::factory()->withStock(10)->create();
        $order = $this->placeOrder([$tracked->id => 2, $untracked->id => 2]);
        $untracked->update(['manage_stock' => false]);

        $this->change($order, OrderStatus::Cancelled, 'Cancelled');

        $this->assertSame(10, $tracked->refresh()->stock_quantity);
        $this->assertSame(8, $untracked->refresh()->stock_quantity);
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Cancellation)->count());
    }

    public function test_non_restocking_transitions_do_not_touch_stock(): void
    {
        $product = Product::factory()->withStock(10)->create();
        $order = $this->placeOrder([$product->id => 3]);

        $this->moveThrough($order, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered);

        $this->assertSame(7, $product->refresh()->stock_quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_status_changed_events_fire_after_commit_with_from_and_to(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $order = Order::factory()->create();

        $this->change($order, OrderStatus::Confirmed);

        Event::assertDispatched(OrderStatusChanged::class, fn ($event) => $event->order->is($order)
            && $event->from === OrderStatus::Pending && $event->to === OrderStatus::Confirmed);
    }

    public function test_rejected_transitions_dispatch_no_event(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $order = Order::factory()->create();

        try {
            $this->change($order, OrderStatus::Delivered);
        } catch (InvalidOrderTransitionException) {
        }

        Event::assertNotDispatched(OrderStatusChanged::class);
    }
}
