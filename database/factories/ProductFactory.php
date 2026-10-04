<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));
        $sellingPrice = fake()->numberBetween(200, 5000);

        return [
            'category_id' => Category::factory(),
            'brand_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'sku' => fake()->unique()->bothify('SKU-####-????'),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'purchase_price' => number_format($sellingPrice * 0.6, 2, '.', ''),
            'selling_price' => number_format($sellingPrice, 2, '.', ''),
            'discount_price' => null,
            'manage_stock' => true,
            'stock_quantity' => 50,
            'status' => ProductStatus::Active,
            'is_featured' => false,
            'weight' => '0.500',
        ];
    }

    /**
     * Put the product on sale at the given price.
     */
    public function discounted(string $discountPrice): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_price' => $discountPrice,
        ]);
    }

    public function withStock(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_quantity' => $quantity,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->withStock(0);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Draft,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Archived,
        ]);
    }
}
