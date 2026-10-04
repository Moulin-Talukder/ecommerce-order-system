<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CreateOrder;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Enums\StockStatus;
use App\Events\OrderPlaced;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Orders\OrderException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(): CreateOrder
    {
        return app(CreateOrder::class);
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $items, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Rahima Akter',
            'customer_phone' => '01712345678',
            'customer_alternate_phone' => null,
            'customer_email' => 'rahima@example.com',
            'shipping_address' => 'House 12, Road 5',
            'shipping_area' => 'Mirpur',
            'shipping_city' => 'Dhaka',
            'shipping_postal_code' => '1216',
            'items' => $items,
            'delivery_charge' => '60.00',
            'discount_total' => '0',
            'payment_method' => PaymentMethod::CashOnDelivery->value,
            'source' => OrderSource::Phone->value,
            'customer_note' => 'Call before delivery',
            'admin_note' => 'VIP',
        ], $overrides);
    }

    // --- Successful placement ---------------------------------------------------------

    public function test_a_single_item_order_is_placed_with_server_calculated_totals(): void
    {
        $user = User::factory()->manager()->create();
        $product = Product::factory()->withStock(10)->create(['name' => 'Panjabi', 'sku' => 'PANJ-1', 'selling_price' => '1500.00', 'purchase_price' => '800.00']);

        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 2]]), $user);

        $order->refresh();
        $this->assertMatchesRegularExpression('/^ORD-\d{6}-[A-HJKMNP-Z2-9]{6}$/', $order->order_number);
        $this->assertSame('3000.00', $order->subtotal);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertSame('60.00', $order->delivery_charge);
        $this->assertSame('3060.00', $order->total);
        $this->assertSame('0.00', $order->amount_paid);
        $this->assertSame('BDT', $order->currency);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(PaymentMethod::CashOnDelivery, $order->payment_method);
        $this->assertSame(OrderSource::Phone, $order->source);
        $this->assertSame('Call before delivery', $order->customer_note);
        $this->assertSame('VIP', $order->admin_note);
        $this->assertTrue($order->creator->is($user));

        $item = $order->items()->sole();
        $this->assertSame(['Panjabi', 'PANJ-1', 2, '1500.00', '800.00', '0.00', '3000.00'],
            [$item->product_name, $item->sku, $item->quantity, $item->unit_price, $item->unit_cost, $item->discount_amount, $item->line_total]);
    }

    public function test_multiple_items_with_product_discounts_order_discount_and_delivery(): void
    {
        $shirt = Product::factory()->withStock(10)->create(['selling_price' => '500.00']);
        $saree = Product::factory()->withStock(10)->discounted('2500.00')->create(['selling_price' => '3000.00']);

        $order = $this->createOrder()->handle($this->data([
            ['product_id' => $saree->id, 'quantity' => 1],
            ['product_id' => $shirt->id, 'quantity' => 3],
        ], ['delivery_charge' => '120.00', 'discount_total' => '100.00']));

        // 1500.00 + (3000.00 - 500.00) = 4000.00; - 100 + 120 = 4020.00
        $order->refresh();
        $this->assertSame('4000.00', $order->subtotal);
        $this->assertSame('100.00', $order->discount_total);
        $this->assertSame('120.00', $order->delivery_charge);
        $this->assertSame('4020.00', $order->total);
        $this->assertSame('500.00', $order->items()->where('product_id', $saree->id)->value('discount_amount'));
        $this->assertSame(2, $order->items()->count());
    }

    public function test_customer_and_delivery_details_are_snapshotted(): void
    {
        $product = Product::factory()->withStock(5)->create();

        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]], [
            'customer_phone' => '+880 1712-345678', 'customer_alternate_phone' => '8801812345678',
        ]))->refresh();

        $this->assertSame('Rahima Akter', $order->customer_name);
        $this->assertSame('01712345678', $order->customer_phone);
        $this->assertSame('01812345678', $order->customer_alternate_phone);
        $this->assertSame('rahima@example.com', $order->customer_email);
        $this->assertSame('House 12, Road 5', $order->shipping_address);
        $this->assertSame(['Mirpur', 'Dhaka', '1216'], [$order->shipping_area, $order->shipping_city, $order->shipping_postal_code]);
    }

    public function test_a_new_phone_creates_a_customer_with_the_delivery_address(): void
    {
        $product = Product::factory()->withStock(5)->create();

        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]]));

        $customer = Customer::sole();
        $this->assertTrue($order->customer->is($customer));
        $this->assertSame(['Rahima Akter', '01712345678', 'House 12, Road 5', 'Mirpur', 'Dhaka'],
            [$customer->name, $customer->phone, $customer->address, $customer->area, $customer->city]);
    }

    public function test_an_existing_customer_is_reused_whatever_the_phone_format_and_is_not_overwritten(): void
    {
        $customer = Customer::factory()->create(['phone' => '01712345678', 'name' => 'Rahima Original', 'address' => 'Saved address']);
        $product = Product::factory()->withStock(5)->create();

        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]], [
            'customer_phone' => '008801712345678', 'customer_name' => 'Rahima (gift for sister)', 'shipping_address' => 'Sister\'s house',
        ]));

        $this->assertSame(1, Customer::count());
        $this->assertTrue($order->customer->is($customer));
        $this->assertSame('Rahima (gift for sister)', $order->customer_name);
        $this->assertSame('Sister\'s house', $order->shipping_address);
        $this->assertSame('Rahima Original', $customer->refresh()->name);
        $this->assertSame('Saved address', $customer->address);
    }

    public function test_the_initial_status_history_is_recorded(): void
    {
        $user = User::factory()->manager()->create();
        $product = Product::factory()->withStock(5)->create();

        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]]), $user);

        $history = $order->statusHistories()->sole();
        $this->assertNull($history->old_status);
        $this->assertSame(OrderStatus::Pending, $history->new_status);
        $this->assertTrue($history->changedBy->is($user));
    }

    // --- Customer & product rules --------------------------------------------------------

    public function test_blocked_customers_cannot_order(): void
    {
        Customer::factory()->blocked()->create(['phone' => '01712345678', 'name' => 'Fake Orderer']);
        $product = Product::factory()->withStock(5)->create();

        try {
            $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]], ['customer_phone' => '+8801712345678']));
            $this->fail('Expected OrderException.');
        } catch (OrderException $exception) {
            $this->assertSame('Fake Orderer is blocked and cannot place orders.', $exception->getMessage());
        }

        $this->assertNothingWasCreated($product, 5);
    }

    public function test_archived_and_draft_products_cannot_be_ordered(): void
    {
        foreach ([Product::factory()->archived(), Product::factory()->draft()] as $factory) {
            $product = $factory->withStock(5)->create();

            try {
                $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]]));
                $this->fail('Expected OrderException.');
            } catch (OrderException $exception) {
                $this->assertStringContainsString('not available for sale', $exception->getMessage());
            }

            $this->assertNothingWasCreated($product, 5);
        }
    }

    public function test_unknown_products_are_rejected(): void
    {
        $this->expectException(OrderException::class);

        $this->createOrder()->handle($this->data([['product_id' => 999999, 'quantity' => 1]]));
    }

    public function test_zero_negative_and_duplicate_item_input_is_rejected(): void
    {
        $product = Product::factory()->withStock(5)->create();

        foreach ([
            [['product_id' => $product->id, 'quantity' => 0]],
            [['product_id' => $product->id, 'quantity' => -2]],
            [['product_id' => $product->id, 'quantity' => 1], ['product_id' => $product->id, 'quantity' => 1]],
            [],
        ] as $items) {
            try {
                $this->createOrder()->handle($this->data($items));
                $this->fail('Expected OrderException for '.json_encode($items));
            } catch (OrderException) {
                $this->assertNothingWasCreated($product, 5);
            }
        }
    }

    public function test_an_order_discount_larger_than_the_subtotal_is_rejected(): void
    {
        $product = Product::factory()->withStock(5)->create(['selling_price' => '100.00']);

        $this->expectException(OrderException::class);

        try {
            $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]], ['discount_total' => '150.00']));
        } finally {
            $this->assertNothingWasCreated($product, 5);
        }
    }

    // --- Stock ---------------------------------------------------------------------------------

    public function test_stock_is_deducted_with_sale_movements_linked_to_the_order(): void
    {
        $user = User::factory()->manager()->create();
        $a = Product::factory()->withStock(10)->create();
        $b = Product::factory()->withStock(3)->create();

        $order = $this->createOrder()->handle($this->data([
            ['product_id' => $a->id, 'quantity' => 4],
            ['product_id' => $b->id, 'quantity' => 3],
        ]), $user);

        $this->assertSame(6, $a->refresh()->stock_quantity);
        $this->assertSame(0, $b->refresh()->stock_quantity);
        $this->assertSame(StockStatus::OutOfStock, $b->stock_status);

        $movements = $order->stockMovements()->orderBy('product_id')->get();
        $this->assertCount(2, $movements);
        $this->assertTrue($movements->every(fn (StockMovement $m) => $m->type === StockMovementType::Sale && $m->user_id === $user->id));
        $this->assertSame([[$a->id, -4, 6], [$b->id, -3, 0]], $movements->map(fn ($m) => [$m->product_id, $m->quantity_change, $m->quantity_after])->all());
        $this->assertStringContainsString($order->order_number, $movements[0]->note);
    }

    public function test_insufficient_stock_rejects_the_whole_order(): void
    {
        $product = Product::factory()->withStock(2)->create();

        $this->expectException(InsufficientStockException::class);

        try {
            $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 3]]));
        } finally {
            $this->assertNothingWasCreated($product, 2);
        }
    }

    public function test_one_unfulfillable_item_rolls_back_every_item_stock_change_and_the_new_customer(): void
    {
        $plenty = Product::factory()->withStock(50)->create();
        $scarce = Product::factory()->withStock(1)->create();

        try {
            $this->createOrder()->handle($this->data([
                ['product_id' => $plenty->id, 'quantity' => 5],
                ['product_id' => $scarce->id, 'quantity' => 2],
            ]));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(50, $plenty->refresh()->stock_quantity);
        $this->assertSame(1, $scarce->refresh()->stock_quantity);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, Customer::count(), 'The customer created for this order is rolled back too.');
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public function test_competing_orders_cannot_oversell(): void
    {
        $product = Product::factory()->withStock(1)->create();

        $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]]));

        try {
            $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]], ['customer_phone' => '01912345678']));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(0, $product->refresh()->stock_quantity);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, StockMovement::count());
    }

    public function test_product_rows_are_locked_in_ascending_id_order(): void
    {
        $products = Product::factory()->count(3)->withStock(10)->create();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'for update') && str_contains($query->sql, '`products`')) {
                $queries[] = $query;
            }
        });

        $this->createOrder()->handle($this->data([
            ['product_id' => $products[2]->id, 'quantity' => 1],
            ['product_id' => $products[0]->id, 'quantity' => 1],
            ['product_id' => $products[1]->id, 'quantity' => 1],
        ]));

        $batchLock = $queries[0];
        $this->assertStringContainsString('order by `id` asc', $batchLock->sql);
        $this->assertStringContainsString('in ('.$products->pluck('id')->sort()->implode(', ').')', $batchLock->sql);

        $perProductLocks = array_map(fn ($query) => $query->bindings[0], array_slice($queries, 1));
        $this->assertSame($products->pluck('id')->sort()->values()->all(), $perProductLocks, 'Stock deductions also run in id order.');
    }

    public function test_untracked_products_are_ordered_without_stock_movements(): void
    {
        $product = Product::factory()->create(['manage_stock' => false, 'stock_quantity' => 0]);

        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 7]]));

        $this->assertSame(1, $order->items()->count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_untracked_products_marked_out_of_stock_cannot_be_ordered(): void
    {
        $product = Product::factory()->create(['manage_stock' => false]);
        $product->forceFill(['stock_status' => StockStatus::OutOfStock])->save();

        $this->expectException(OrderException::class);

        $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]]));
    }

    // --- Snapshots stay historical ----------------------------------------------------------

    public function test_changing_the_product_later_does_not_change_the_order(): void
    {
        $product = Product::factory()->withStock(5)->create(['name' => 'Original', 'sku' => 'ORIG-1', 'selling_price' => '500.00', 'purchase_price' => '300.00']);
        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 2]]));

        $product->update(['name' => 'Renamed', 'sku' => 'NEW-1', 'selling_price' => '999.00', 'purchase_price' => '1.00', 'discount_price' => '10.00']);

        $item = $order->items()->sole();
        $this->assertSame(['Original', 'ORIG-1', '500.00', '300.00', '1000.00'], [$item->product_name, $item->sku, $item->unit_price, $item->unit_cost, $item->line_total]);
        $this->assertSame('1060.00', $order->refresh()->total);
    }

    public function test_changing_the_customer_later_does_not_change_the_order(): void
    {
        $product = Product::factory()->withStock(5)->create();
        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]]));

        $order->customer->update(['name' => 'Changed', 'phone' => '01999999999', 'email' => 'new@example.com', 'address' => 'Moved away']);

        $order->refresh();
        $this->assertSame('Rahima Akter', $order->customer_name);
        $this->assertSame('01712345678', $order->customer_phone);
        $this->assertSame('rahima@example.com', $order->customer_email);
        $this->assertSame('House 12, Road 5', $order->shipping_address);
    }

    // --- Order number ---------------------------------------------------------------------------

    public function test_an_order_number_collision_is_retried(): void
    {
        Order::factory()->create(['order_number' => 'ORD-261004-TAKEN1']);
        $generator = Mockery::mock(OrderNumberGenerator::class);
        $generator->shouldReceive('generate')->twice()->andReturn('ORD-261004-TAKEN1', 'ORD-261004-FRESH2');
        $this->app->instance(OrderNumberGenerator::class, $generator);
        $product = Product::factory()->withStock(5)->create();

        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]]));

        $this->assertSame('ORD-261004-FRESH2', $order->order_number);
        $this->assertSame(4, $product->refresh()->stock_quantity, 'Stock is deducted once, not once per attempt.');
        $this->assertSame(1, StockMovement::count());
    }

    public function test_repeated_collisions_give_up_without_side_effects(): void
    {
        Order::factory()->create(['order_number' => 'ORD-261004-TAKEN1']);
        $generator = Mockery::mock(OrderNumberGenerator::class);
        $generator->shouldReceive('generate')->times(3)->andReturn('ORD-261004-TAKEN1');
        $this->app->instance(OrderNumberGenerator::class, $generator);
        $product = Product::factory()->withStock(5)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]]));
        } finally {
            $this->assertSame(5, $product->refresh()->stock_quantity);
            $this->assertSame(1, Order::count());
        }
    }

    // --- Events ------------------------------------------------------------------------------------

    public function test_order_placed_is_dispatched_only_after_the_transaction_commits(): void
    {
        $product = Product::factory()->withStock(5)->create();
        $dispatched = [];
        Event::listen(OrderPlaced::class, function (OrderPlaced $event) use (&$dispatched): void {
            $dispatched[] = $event->order->order_number;
        });

        DB::beginTransaction();
        $order = $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 1]]));
        $this->assertSame([], $dispatched, 'Not dispatched while the surrounding transaction is open.');
        DB::commit();

        $this->assertSame([$order->order_number], $dispatched);
    }

    public function test_order_placed_is_not_dispatched_when_placement_fails(): void
    {
        Event::fake([OrderPlaced::class]);
        $product = Product::factory()->withStock(1)->create();

        try {
            $this->createOrder()->handle($this->data([['product_id' => $product->id, 'quantity' => 2]]));
        } catch (InsufficientStockException) {
        }

        Event::assertNotDispatched(OrderPlaced::class);
    }

    private function assertNothingWasCreated(Product $product, int $expectedStock): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame($expectedStock, $product->refresh()->stock_quantity);
    }
}
