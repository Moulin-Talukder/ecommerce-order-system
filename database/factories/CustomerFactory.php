<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => static::bangladeshiMobile(),
            'alternate_phone' => null,
            'email' => fake()->optional()->safeEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Dhaka', 'Chattogram', 'Sylhet', 'Rajshahi', 'Khulna']),
            'area' => fake()->citySuffix(),
            'postal_code' => fake()->numerify('####'),
            'status' => CustomerStatus::Active,
        ];
    }

    /**
     * A unique, valid Bangladeshi mobile number in canonical format.
     */
    public static function bangladeshiMobile(): string
    {
        return fake()->unique()->regexify('01[3-9][0-9]{8}');
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CustomerStatus::Blocked,
        ]);
    }
}
