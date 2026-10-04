<?php

namespace Tests\Feature\Database;

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
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the database itself enforces the approved constraints, independent
 * of application code.
 */
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    // --- Unique constraints -------------------------------------------------

    public function test_category_and_brand_slugs_are_unique(): void
    {
        Category::factory()->create(['slug' => 'phones']);
        Brand::factory()->create(['slug' => 'acme']);

        $this->assertUniqueViolation(fn () => Category::factory()->create(['slug' => 'phones']));
        $this->assertUniqueViolation(fn () => Brand::factory()->create(['slug' => 'acme']));
    }

    public function test_product_slug_and_sku_are_unique(): void
    {
        Product::factory()->create(['slug' => 'blue-shirt', 'sku' => 'SHIRT-001']);

        $this->assertUniqueViolation(fn () => Product::factory()->create(['slug' => 'blue-shirt']));
        $this->assertUniqueViolation(fn () => Product::factory()->create(['sku' => 'SHIRT-001']));
    }

    public function test_customer_phone_is_unique_across_input_formats(): void
    {
        Customer::factory()->create(['phone' => '01712345678']);

        $this->assertUniqueViolation(fn () => Customer::factory()->create(['phone' => '+880 1712-345678']));
    }

    public function test_order_number_is_unique(): void
    {
        Order::factory()->create(['order_number' => 'ORD-261004-ABCDEF']);

        $this->assertUniqueViolation(fn () => Order::factory()->create(['order_number' => 'ORD-261004-ABCDEF']));
    }

    public function test_courier_invoice_and_consignment_are_unique_per_courier(): void
    {
        CourierShipment::factory()->create(['invoice' => 'ORD-1', 'consignment_id' => '1001']);

        $this->assertUniqueViolation(fn () => CourierShipment::factory()->create(['invoice' => 'ORD-1']));
        $this->assertUniqueViolation(fn () => CourierShipment::factory()->create(['consignment_id' => '1001']));
    }

    public function test_multiple_shipments_may_await_a_consignment_id(): void
    {
        CourierShipment::factory()->count(2)->create(['consignment_id' => null]);

        $this->assertSame(2, CourierShipment::whereNull('consignment_id')->count());
    }

    public function test_a_gateway_transaction_can_only_be_recorded_once(): void
    {
        Payment::factory()->create(['gateway' => 'sslcommerz', 'transaction_reference' => 'TXN-1']);

        $this->assertUniqueViolation(fn () => Payment::factory()->create(['gateway' => 'sslcommerz', 'transaction_reference' => 'TXN-1']));
    }

    public function test_payments_without_a_gateway_reference_do_not_collide(): void
    {
        Payment::factory()->count(2)->create(['gateway' => null, 'transaction_reference' => null]);

        $this->assertSame(2, Payment::count());
    }

    public function test_setting_keys_are_unique(): void
    {
        Setting::factory()->create(['key' => 'store.name']);

        $this->assertUniqueViolation(fn () => Setting::factory()->create(['key' => 'store.name']));
    }

    // --- Referenced records cannot be physically deleted ---------------------

    public function test_a_category_with_products_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();

        $this->assertQueryFails(fn () => $product->category->delete());
    }

    public function test_a_category_with_subcategories_cannot_be_deleted(): void
    {
        $parent = Category::factory()->create();
        Category::factory()->childOf($parent)->create();

        $this->assertQueryFails(fn () => $parent->delete());
    }

    public function test_a_brand_with_products_cannot_be_deleted(): void
    {
        $product = Product::factory()->for(Brand::factory())->create();

        $this->assertQueryFails(fn () => $product->brand->delete());
    }

    public function test_an_ordered_product_cannot_be_deleted(): void
    {
        $item = OrderItem::factory()->create();

        $this->assertQueryFails(fn () => $item->product->delete());
    }

    public function test_a_customer_with_orders_cannot_be_deleted(): void
    {
        $order = Order::factory()->create();

        $this->assertQueryFails(fn () => $order->customer->delete());
    }

    public function test_an_order_with_payments_or_shipments_cannot_be_deleted(): void
    {
        $paid = Payment::factory()->create()->order;
        $shipped = CourierShipment::factory()->create()->order;

        $this->assertQueryFails(fn () => $paid->delete());
        $this->assertQueryFails(fn () => $shipped->delete());
    }

    public function test_unreferenced_products_can_be_deleted_with_their_images_and_stock_history(): void
    {
        $product = Product::factory()->create();
        ProductImage::factory()->for($product)->count(2)->create();
        StockMovement::factory()->for($product)->create();

        $product->delete();

        $this->assertDatabaseCount('product_images', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_deleting_a_user_keeps_the_records_they_touched(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['created_by' => $user->id]);
        $history = OrderStatusHistory::factory()->for($order)->create(['changed_by' => $user->id]);
        $payment = Payment::factory()->for($order)->create(['recorded_by' => $user->id]);

        $user->delete();

        $this->assertNull($order->refresh()->created_by);
        $this->assertNull($history->refresh()->changed_by);
        $this->assertNull($payment->refresh()->recorded_by);
    }

    // --- Value checks -------------------------------------------------------

    public function test_stock_quantity_can_never_become_negative(): void
    {
        $product = Product::factory()->withStock(2)->create();

        $this->assertQueryFails(fn () => DB::table('products')->where('id', $product->id)->decrement('stock_quantity', 3));
        $this->assertSame(2, $product->refresh()->stock_quantity);
    }

    public function test_selling_price_cannot_be_negative(): void
    {
        $this->assertQueryFails(fn () => Product::factory()->create(['selling_price' => '-1.00']));
    }

    public function test_discount_price_must_be_lower_than_selling_price(): void
    {
        $this->assertQueryFails(fn () => Product::factory()->create(['selling_price' => '500.00', 'discount_price' => '500.00']));
        $this->assertQueryFails(fn () => Product::factory()->create(['selling_price' => '500.00', 'discount_price' => '600.00']));

        $product = Product::factory()->create(['selling_price' => '500.00', 'discount_price' => '450.00']);
        $this->assertSame('450.00', $product->refresh()->discount_price);
    }

    public function test_discount_price_is_optional(): void
    {
        $product = Product::factory()->create(['discount_price' => null]);

        $this->assertNull($product->refresh()->discount_price);
    }

    public function test_order_item_quantity_must_be_at_least_one(): void
    {
        $this->assertQueryFails(fn () => OrderItem::factory()->create(['quantity' => 0]));
    }

    public function test_order_amounts_cannot_be_negative(): void
    {
        $this->assertQueryFails(fn () => Order::factory()->create(['total' => '-1.00']));
        $this->assertQueryFails(fn () => Order::factory()->create(['discount_total' => '-5.00']));
    }

    public function test_payment_amounts_must_be_positive(): void
    {
        $this->assertQueryFails(fn () => Payment::factory()->create(['amount' => '0.00']));
        $this->assertQueryFails(fn () => Payment::factory()->refund()->create(['amount' => '-100.00']));
    }

    public function test_stock_movements_must_change_the_quantity(): void
    {
        $this->assertQueryFails(fn () => StockMovement::factory()->create(['quantity_change' => 0]));
    }

    // --- Connection ---------------------------------------------------------

    public function test_database_session_uses_the_application_timezone(): void
    {
        $this->assertSame('Asia/Dhaka', config('app.timezone'));
        $this->assertSame('+06:00', DB::scalar('SELECT @@session.time_zone'));
    }

    private function assertQueryFails(Closure $callback): void
    {
        try {
            DB::transaction($callback);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the database to reject the query.');
    }

    private function assertUniqueViolation(Closure $callback): void
    {
        try {
            DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected a unique constraint violation.');
    }
}
