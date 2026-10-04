<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
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
            'disk' => 'public',
            'path' => 'products/'.fake()->uuid().'.jpg',
            'alt_text' => fake()->optional()->words(3, true),
            'sort_order' => 0,
        ];
    }
}
