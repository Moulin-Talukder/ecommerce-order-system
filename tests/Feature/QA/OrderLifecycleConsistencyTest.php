<?php

namespace Tests\Feature\QA;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end order lifecycle through the real HTTP endpoints (storefront
 * checkout, then admin status, payment and refund actions), checking the
 * cross-module invariants after every step:
 *  - stock: product quantity = starting quantity + sum of its stock movements;
 *  - money: order total = subtotal - discount + delivery; subtotal = sum of lines;
 *  - ledger: amount_paid = completed payments - completed refunds, and
 *    payment_status is what the ledger implies;
 *  - rejected actions change nothing.
 */
class OrderLifecycleConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

    private User $superAdmin;

    private Product $panjabi;

    private Product $tee;

    /** @var array<int, int> product id => starting stock */
    private array $startingStock = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $category = Category::factory()->create();
        $this->panjabi = Product::factory()->for($category)->withStock(10)->create(['selling_price' => '1500.00', 'slug' => 'panjabi']);
        $this->tee = Product::factory()->for($category)->withStock(6)->discounted('450.00')->create(['selling_price' => '500.00', 'slug' => 'tee']);
        $this->startingStock = [$this->panjabi->id => 10, $this->tee->id => 6];
    }

    private function checkout(array $cart, string $token = self::TOKEN): Order
    {
        $this->withSession(['storefront.cart' => $cart, 'storefront.checkout_tokens' => [$token]])
            ->post(route('storefront.checkout.store'), [
                'checkout_token' => $token, 'name' => 'Test Customer', 'phone' => '01700000000',
                'address' => 'Test House 1', 'area' => 'Testarea', 'city' => 'Dhaka', 'delivery_zone' => 'inside_dhaka',
            ])->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }

    private function changeStatus(Order $order, OrderStatus $to): void
    {
        $this->actingAs($this->superAdmin)->patch(route('admin.orders.status', $order), ['status' => $to->value] + ($to->restoresStock() ? ['note' => 'QA: customer request'] : []))
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
    }

    /**
     * A refused action: either a validation error or an order-rule error flash.
     */
    private function assertRejected(TestResponse $response): void
    {
        $response->assertRedirect();
        $this->assertTrue(session()->has('errors') || session()->has('error'), 'The action was refused with a message.');
    }

    private function assertConsistent(Order $order): void
    {
        $order->refresh()->load('items');

        foreach ($this->startingStock as $productId => $start) {
            $this->assertSame(
                $start + (int) StockMovement::where('product_id', $productId)->sum('quantity_change'),
                Product::findOrFail($productId)->stock_quantity,
                "Stock of product {$productId} equals start + movements.",
            );
        }

        $lines = $order->items->reduce(fn (string $sum, $item) => bcadd($sum, $item->line_total, 2), '0.00');
        $this->assertSame($lines, $order->subtotal, 'Subtotal = sum of line totals.');
        $this->assertSame(bcadd(bcsub($order->subtotal, $order->discount_total, 2), $order->delivery_charge, 2), $order->total, 'Total = subtotal - discount + delivery.');

        $ledger = fn (PaymentType $type) => bcadd((string) Payment::where('order_id', $order->id)->where('type', $type)->where('status', PaymentTransactionStatus::Completed)->sum('amount'), '0', 2);
        $paid = $ledger(PaymentType::Payment);
        $refunded = $ledger(PaymentType::Refund);
        $this->assertSame(bcsub($paid, $refunded, 2), $order->amount_paid, 'amount_paid = payments - refunds.');
        $lastFailed = Payment::where('order_id', $order->id)->where('type', PaymentType::Payment)->latest('id')->value('status') === PaymentTransactionStatus::Failed;
        $this->assertSame(PaymentStatus::fromLedger($order->total, $paid, $refunded, $lastFailed), $order->payment_status, 'payment_status follows the ledger.');
    }

    public function test_a_full_order_lifecycle_keeps_stock_money_and_ledger_consistent(): void
    {
        $order = $this->checkout([$this->panjabi->id => 2, $this->tee->id => 3]);
        $lines = $order->items->keyBy('product_id');
        $this->assertSame(['3000.00', '1350.00'], [$lines[$this->panjabi->id]->line_total, $lines[$this->tee->id]->line_total], 'Line totals use the discounted price.');
        $this->assertSame(['4350.00', '60.00', '4410.00'], [$order->subtotal, $order->delivery_charge, $order->total], 'Server-calculated totals.');
        $this->assertSame([8, 3], [$this->panjabi->refresh()->stock_quantity, $this->tee->refresh()->stock_quantity]);
        $this->assertConsistent($order);

        foreach ([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered] as $status) {
            $this->changeStatus($order, $status);
            $this->assertConsistent($order);
        }

        $pay = fn (string $amount, string $status = 'completed') => $this->actingAs($this->superAdmin)
            ->post(route('admin.orders.payments.store', $order), ['amount' => $amount, 'method' => 'cod', 'status' => $status]);

        $pay('1000.00')->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::PartiallyPaid, $order->refresh()->payment_status);
        $this->assertConsistent($order);

        $pay('999.00', 'failed')->assertSessionHasNoErrors();
        $this->assertSame('1000.00', $order->refresh()->amount_paid, 'A failed attempt moves no money.');
        $this->assertConsistent($order);

        $this->assertRejected($pay('3410.01'));
        $this->assertSame('1000.00', $order->refresh()->amount_paid, 'Overpayment is rejected without side effects.');

        $pay('3410.00')->assertSessionHasNoErrors();
        $this->assertSame([PaymentStatus::Paid, '4410.00'], [$order->refresh()->payment_status, $order->amount_paid]);
        $this->assertConsistent($order);

        $refund = fn (string $amount) => $this->actingAs($this->superAdmin)
            ->post(route('admin.orders.refunds.store', $order), ['amount' => $amount, 'method' => 'cod', 'note' => 'Customer return']);

        $this->assertRejected($refund('4410.01'));
        $this->assertSame('4410.00', $order->refresh()->amount_paid, 'Over-refund is rejected without side effects.');
        $refund('1500.00')->assertSessionHasNoErrors();
        $this->assertSame([PaymentStatus::PartiallyRefunded, '2910.00'], [$order->refresh()->payment_status, $order->amount_paid]);
        $this->assertConsistent($order);

        $this->changeStatus($order, OrderStatus::Returned);
        $this->assertSame([10, 6], [$this->panjabi->refresh()->stock_quantity, $this->tee->refresh()->stock_quantity], 'Return restores all stock.');
        $this->assertConsistent($order);

        $this->assertRejected($this->actingAs($this->superAdmin)->patch(route('admin.orders.status', $order), ['status' => 'delivered']));
        $this->assertSame(OrderStatus::Returned, $order->refresh()->status, 'A final status cannot be left.');
        $this->assertSame([10, 6], [$this->panjabi->refresh()->stock_quantity, $this->tee->refresh()->stock_quantity], 'Stock is never restored twice.');
        $this->assertConsistent($order);
    }

    public function test_cancelling_restores_stock_once_and_rejected_transitions_change_nothing(): void
    {
        $order = $this->checkout([$this->panjabi->id => 4]);
        $this->assertSame(6, $this->panjabi->refresh()->stock_quantity);

        $this->assertRejected($this->actingAs($this->superAdmin)->patch(route('admin.orders.status', $order), ['status' => 'shipped']));
        $this->assertSame([OrderStatus::Pending, 6], [$order->refresh()->status, $this->panjabi->refresh()->stock_quantity], 'Pending cannot jump to shipped.');

        $this->changeStatus($order, OrderStatus::Cancelled);
        $this->assertSame(10, $this->panjabi->refresh()->stock_quantity);
        $this->assertConsistent($order);

        $this->assertRejected($this->actingAs($this->superAdmin)->post(route('admin.orders.payments.store', $order), ['amount' => '100.00', 'method' => 'cod', 'status' => 'completed']));
        $this->assertSame(0, Payment::count(), 'Cancelled orders take no new payments.');
        $this->assertConsistent($order);
    }

    public function test_two_orders_competing_for_the_last_units_never_oversell(): void
    {
        $this->tee->forceFill(['stock_quantity' => 3])->save();
        $this->startingStock[$this->tee->id] = 3;

        $first = $this->checkout([$this->tee->id => 2], 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->withSession(['storefront.cart' => [$this->tee->id => 2], 'storefront.checkout_tokens' => ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']])
            ->post(route('storefront.checkout.store'), [
                'checkout_token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'name' => 'Second Buyer', 'phone' => '01800000000',
                'address' => 'Test House 2', 'area' => 'Testarea', 'city' => 'Dhaka', 'delivery_zone' => 'inside_dhaka',
            ]);

        $this->assertSame(1, Order::count(), 'The second order is refused rather than overselling.');
        $this->assertSame(1, $this->tee->refresh()->stock_quantity);
        $this->assertConsistent($first);
    }
}
