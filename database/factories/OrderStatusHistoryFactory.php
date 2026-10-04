<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusHistory>
 */
class OrderStatusHistoryFactory extends Factory
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
            'old_status' => null,
            'new_status' => OrderStatus::Pending,
            'changed_by' => null,
            'note' => null,
        ];
    }

    public function transition(?OrderStatus $from, OrderStatus $to): static
    {
        return $this->state(fn (array $attributes) => [
            'old_status' => $from,
            'new_status' => $to,
        ]);
    }
}
