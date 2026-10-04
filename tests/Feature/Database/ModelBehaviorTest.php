<?php

namespace Tests\Feature\Database;

use App\Enums\CourierProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockStatus;
use App\Models\CourierShipment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ModelBehaviorTest extends TestCase
{
    use RefreshDatabase;

    // --- Mass assignment protection ------------------------------------------

    public function test_order_totals_status_and_payment_summary_cannot_be_mass_assigned(): void
    {
        $order = Order::factory()->create();

        $order->fill([
            'order_number' => 'HACKED',
            'customer_id' => Customer::factory()->create()->id,
            'subtotal' => '1.00',
            'discount_total' => '999.00',
            'delivery_charge' => '0.00',
            'total' => '1.00',
            'amount_paid' => '1060.00',
            'payment_status' => PaymentStatus::Paid->value,
            'status' => OrderStatus::Delivered->value,
            'created_by' => 1,
        ])->save();

        $order->refresh();
        $this->assertNotSame('HACKED', $order->order_number);
        $this->assertSame('1000.00', $order->subtotal);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertSame('60.00', $order->delivery_charge);
        $this->assertSame('1060.00', $order->total);
        $this->assertSame('0.00', $order->amount_paid);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->created_by);
    }

    public function test_product_stock_cannot_be_mass_assigned(): void
    {
        $product = Product::factory()->withStock(5)->create();

        $product->fill(['stock_quantity' => 1000, 'stock_status' => StockStatus::InStock->value])->save();

        $this->assertSame(5, $product->refresh()->stock_quantity);
    }

    public function test_customer_status_cannot_be_mass_assigned(): void
    {
        $customer = Customer::factory()->blocked()->create();

        $customer->fill(['status' => 'active'])->save();

        $this->assertSame('blocked', $customer->refresh()->status->value);
    }

    // --- Product behaviour ----------------------------------------------------

    public function test_stock_status_follows_quantity_when_stock_is_managed(): void
    {
        $product = Product::factory()->withStock(1)->create();
        $this->assertSame(StockStatus::InStock, $product->stock_status);

        $product->forceFill(['stock_quantity' => 0])->save();
        $this->assertSame(StockStatus::OutOfStock, $product->refresh()->stock_status);
    }

    public function test_stock_status_is_manual_when_stock_is_not_managed(): void
    {
        $product = Product::factory()->outOfStock()->create(['manage_stock' => false]);
        $product->forceFill(['stock_status' => StockStatus::InStock])->save();

        $this->assertSame(StockStatus::InStock, $product->refresh()->stock_status);
    }

    public function test_effective_price_prefers_the_discount_price(): void
    {
        $this->assertSame('450.00', Product::factory()->discounted('450.00')->create(['selling_price' => '500.00'])->effectivePrice());
        $this->assertSame('500.00', Product::factory()->create(['selling_price' => '500.00'])->effectivePrice());
    }

    // --- Phone normalization -------------------------------------------------

    public function test_customer_phones_are_stored_normalized(): void
    {
        $customer = Customer::factory()->create([
            'phone' => '+880 1712-345678',
            'alternate_phone' => '8801812345678',
        ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'phone' => '01712345678',
            'alternate_phone' => '01812345678',
        ]);
    }

    public function test_order_phone_snapshots_are_stored_normalized(): void
    {
        $order = Order::factory()->create(['customer_phone' => '+8801912345678']);

        $this->assertSame('01912345678', $order->refresh()->customer_phone);
    }

    public function test_invalid_phone_numbers_are_never_stored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Customer::factory()->create(['phone' => '12345']);
    }

    public function test_empty_alternate_phone_is_stored_as_null(): void
    {
        $customer = Customer::factory()->create(['alternate_phone' => '']);

        $this->assertNull($customer->refresh()->alternate_phone);
    }

    // --- Snapshots and money --------------------------------------------------

    public function test_order_item_snapshots_do_not_change_when_the_product_changes(): void
    {
        $product = Product::factory()->create(['name' => 'Original Name', 'sku' => 'SKU-ORIG', 'selling_price' => '500.00']);
        $item = OrderItem::factory()->for($product)->create(['quantity' => 2]);

        $product->update(['name' => 'Renamed', 'sku' => 'SKU-NEW', 'selling_price' => '900.00']);

        $item->refresh();
        $this->assertSame('Original Name', $item->product_name);
        $this->assertSame('SKU-ORIG', $item->sku);
        $this->assertSame('500.00', $item->unit_price);
        $this->assertSame('1000.00', $item->line_total);
    }

    public function test_order_customer_snapshot_does_not_change_when_the_customer_changes(): void
    {
        $customer = Customer::factory()->create(['name' => 'Rahim', 'address' => 'House 1, Road 2']);
        $order = Order::factory()->for($customer)->create();

        $customer->update(['name' => 'Karim', 'address' => 'Somewhere else', 'phone' => '01799999999']);

        $order->refresh();
        $this->assertSame('Rahim', $order->customer_name);
        $this->assertSame('House 1, Road 2', $order->shipping_address);
        $this->assertNotSame('01799999999', $order->customer_phone);
    }

    public function test_money_columns_are_exact_decimal_strings(): void
    {
        $order = Order::factory()->create()->refresh();

        $this->assertSame('1060.00', $order->total);
        $this->assertSame('BDT', $order->currency);
        $this->assertSame(PaymentMethod::CashOnDelivery, $order->payment_method);
    }

    public function test_amount_due_is_total_minus_amount_paid_and_never_negative(): void
    {
        $order = Order::factory()->create();
        $this->assertSame('1060.00', $order->amountDue());

        $order->forceFill(['amount_paid' => '60.00'])->save();
        $this->assertSame('1000.00', $order->amountDue());

        $order->forceFill(['amount_paid' => '2000.00'])->save();
        $this->assertSame('0.00', $order->amountDue());
    }

    // --- Courier and settings ---------------------------------------------------

    public function test_unknown_courier_statuses_are_stored_and_read_without_errors(): void
    {
        $shipment = CourierShipment::factory()->create(['status' => 'some_future_status']);

        $this->assertSame('some_future_status', $shipment->refresh()->status);
        $this->assertSame(CourierProvider::Steadfast, $shipment->courier);
    }

    public function test_courier_payloads_are_stored_as_json_and_hidden_from_serialization(): void
    {
        $shipment = CourierShipment::factory()->create([
            'request_payload' => ['invoice' => 'ORD-1', 'cod_amount' => 1060],
            'response_payload' => ['status' => 200, 'consignment' => ['tracking_code' => 'ABC']],
        ])->refresh();

        $this->assertSame(1060, $shipment->request_payload['cod_amount']);
        $this->assertArrayNotHasKey('request_payload', $shipment->toArray());
        $this->assertArrayNotHasKey('response_payload', $shipment->toArray());
    }

    public function test_settings_store_typed_json_values(): void
    {
        Setting::create(['key' => 'delivery.free_threshold', 'value' => 2000]);
        Setting::create(['key' => 'store.name', 'value' => 'My Store']);
        Setting::create(['key' => 'courier.auto_submit', 'value' => false]);

        $this->assertSame(2000, Setting::firstWhere('key', 'delivery.free_threshold')->value);
        $this->assertSame('My Store', Setting::firstWhere('key', 'store.name')->value);
        $this->assertFalse(Setting::firstWhere('key', 'courier.auto_submit')->value);
    }
}
