<?php

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates an order with consistent totals but no items; add items with
 * OrderItem::factory()->for($order) when a test needs them.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.now()->format('ymd').'-'.fake()->unique()->regexify('[A-HJ-KM-NP-Z2-9]{6}'),
            'customer_id' => Customer::factory(),
            'customer_name' => fn (array $attributes) => Customer::find($attributes['customer_id'])->name,
            'customer_phone' => fn (array $attributes) => Customer::find($attributes['customer_id'])->phone,
            'customer_email' => fn (array $attributes) => Customer::find($attributes['customer_id'])->email,
            'shipping_address' => fn (array $attributes) => Customer::find($attributes['customer_id'])->address ?? fake()->streetAddress(),
            'shipping_city' => 'Dhaka',
            'shipping_area' => 'Mirpur',
            'currency' => 'BDT',
            'subtotal' => '1000.00',
            'discount_total' => '0.00',
            'delivery_charge' => '60.00',
            'total' => '1060.00',
            'amount_paid' => '0.00',
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::Pending,
            'source' => OrderSource::Website,
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
