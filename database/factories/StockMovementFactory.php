<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'order_id' => null,
            'type' => StockMovementType::Adjustment,
            'quantity_change' => 10,
            'quantity_after' => 60,
            'user_id' => null,
            'note' => null,
        ];
    }
}
