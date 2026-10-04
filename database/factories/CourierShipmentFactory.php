<?php

namespace Database\Factories;

use App\Enums\CourierProvider;
use App\Models\CourierShipment;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourierShipment>
 */
class CourierShipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'courier' => CourierProvider::Steadfast,
            'invoice' => fn (array $attributes) => Order::find($attributes['order_id'])->order_number,
            'consignment_id' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'tracking_code' => fake()->unique()->regexify('[A-Z0-9]{10}'),
            'status' => 'in_review',
            'cod_amount' => fn (array $attributes) => Order::find($attributes['order_id'])->amountDue(),
            'submitted_at' => now(),
        ];
    }
}
