<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'type' => PaymentType::Payment,
            'method' => PaymentMethod::CashOnDelivery,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => '1060.00',
            'currency' => 'BDT',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentTransactionStatus::Completed,
            'paid_at' => now(),
        ]);
    }

    public function refund(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PaymentType::Refund,
        ]);
    }
}
