<?php

namespace Tests\Feature\Database;

use App\Enums\OrderStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CourierShipment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_nest_through_parent_and_children(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        $grandchild = Category::factory()->childOf($child)->create();

        $this->assertTrue($grandchild->parent->is($child));
        $this->assertTrue($grandchild->parent->parent->is($root));
        $this->assertTrue($root->children->first()->is($child));
        $this->assertNull($root->parent);
    }

    public function test_products_belong_to_a_category_and_an_optional_brand(): void
    {
        $brand = Brand::factory()->create();
        $branded = Product::factory()->for($brand)->create();
        $unbranded = Product::factory()->for($branded->category)->create();

        $this->assertTrue($branded->brand->is($brand));
        $this->assertNull($unbranded->brand);
        $this->assertCount(2, $branded->category->products);
        $this->assertCount(1, $brand->products);
    }

    public function test_product_images_are_ordered_and_the_first_is_primary(): void
    {
        $product = Product::factory()->create();
        $second = ProductImage::factory()->for($product)->create(['sort_order' => 2]);
        $first = ProductImage::factory()->for($product)->create(['sort_order' => 1]);

        $this->assertSame([$first->id, $second->id], $product->images->pluck('id')->all());
        $this->assertTrue($first->product->is($product));
    }

    public function test_customer_order_history(): void
    {
        $customer = Customer::factory()->create();
        Order::factory()->for($customer)->count(3)->create();
        Order::factory()->create();

        $this->assertCount(3, $customer->orders);
        $this->assertTrue($customer->orders->first()->customer->is($customer));
    }

    public function test_order_items_link_orders_and_products(): void
    {
        $order = Order::factory()->create();
        $product = Product::factory()->create();
        OrderItem::factory()->for($order)->for($product)->create();
        OrderItem::factory()->for($order)->create();

        $this->assertCount(2, $order->items);
        $this->assertTrue($order->items->first()->product->is($product));
        $this->assertTrue($product->orderItems->first()->order->is($order));
    }

    public function test_status_histories_form_a_chronological_timeline(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        OrderStatusHistory::factory()->for($order)->transition(null, OrderStatus::Pending)->create(['created_at' => now()->subHour()]);
        OrderStatusHistory::factory()->for($order)->transition(OrderStatus::Pending, OrderStatus::Confirmed)->create(['changed_by' => $user->id]);

        $timeline = $order->statusHistories;

        $this->assertSame([null, OrderStatus::Pending], [$timeline[0]->old_status, $timeline[0]->new_status]);
        $this->assertSame(OrderStatus::Confirmed, $timeline[1]->new_status);
        $this->assertTrue($timeline[1]->changedBy->is($user));
        $this->assertNull($timeline[0]->changedBy);
    }

    public function test_orders_have_many_payments(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        Payment::factory()->for($order)->completed()->create(['recorded_by' => $user->id]);
        Payment::factory()->for($order)->refund()->create(['amount' => '100.00']);

        $this->assertCount(2, $order->payments);
        $this->assertTrue($order->payments->first()->recordedBy->is($user));
    }

    public function test_latest_shipment_is_the_most_recent_booking(): void
    {
        $order = Order::factory()->create();
        CourierShipment::factory()->for($order)->create(['invoice' => $order->order_number]);
        $resubmitted = CourierShipment::factory()->for($order)->create(['invoice' => $order->order_number.'-2']);

        $this->assertCount(2, $order->shipments);
        $this->assertTrue($order->latestShipment->is($resubmitted));
        $this->assertTrue($resubmitted->order->is($order));
    }

    public function test_latest_shipment_can_be_eager_loaded_for_order_lists(): void
    {
        $orders = Order::factory()->count(2)->create();
        $orders->each(fn (Order $order) => CourierShipment::factory()->for($order)->create());

        $loaded = Order::with('latestShipment')->get();

        $this->assertTrue($loaded->every(fn (Order $order) => $order->relationLoaded('latestShipment') && $order->latestShipment !== null));
    }

    public function test_stock_movements_link_products_orders_and_users(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        $movement = StockMovement::factory()->create(['order_id' => $order->id, 'user_id' => $user->id]);

        $this->assertTrue($movement->product->stockMovements->first()->is($movement));
        $this->assertTrue($order->stockMovements->first()->is($movement));
        $this->assertTrue($movement->user->is($user));
    }

    public function test_orders_record_the_admin_who_created_them(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create(['created_by' => $admin->id]);

        $this->assertTrue($order->creator->is($admin));
        $this->assertNull(Order::factory()->create()->creator);
    }
}
