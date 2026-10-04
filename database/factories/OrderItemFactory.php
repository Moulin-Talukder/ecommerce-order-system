<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Snapshots the related product's name, SKU and prices at creation time.
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
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
            'product_id' => Product::factory(),
            'product_name' => fn (array $attributes) => Product::find($attributes['product_id'])->name,
            'sku' => fn (array $attributes) => Product::find($attributes['product_id'])->sku,
            'quantity' => fake()->numberBetween(1, 3),
            'unit_price' => fn (array $attributes) => Product::find($attributes['product_id'])->selling_price,
            'unit_cost' => fn (array $attributes) => Product::find($attributes['product_id'])->purchase_price,
            'discount_amount' => '0.00',
            'line_total' => fn (array $attributes) => bcsub(
                bcmul((string) $attributes['unit_price'], (string) $attributes['quantity'], 2),
                (string) $attributes['discount_amount'],
                2,
            ),
        ];
    }
}
