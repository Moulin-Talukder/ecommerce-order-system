<?php

namespace Tests\Unit\Enums;

use App\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    /**
     * @return array<string, array{OrderStatus, OrderStatus}>
     */
    public static function allowedTransitions(): array
    {
        return [
            'pending → confirmed' => [OrderStatus::Pending, OrderStatus::Confirmed],
            'pending → cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled],
            'confirmed → processing' => [OrderStatus::Confirmed, OrderStatus::Processing],
            'confirmed → cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled],
            'processing → shipped' => [OrderStatus::Processing, OrderStatus::Shipped],
            'processing → cancelled' => [OrderStatus::Processing, OrderStatus::Cancelled],
            'shipped → delivered' => [OrderStatus::Shipped, OrderStatus::Delivered],
            'shipped → returned' => [OrderStatus::Shipped, OrderStatus::Returned],
            'delivered → returned' => [OrderStatus::Delivered, OrderStatus::Returned],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_allowed_transitions(OrderStatus $from, OrderStatus $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    public function test_every_transition_not_in_the_map_is_rejected(): void
    {
        $allowed = array_map(
            fn (array $pair) => $pair[0]->value.'>'.$pair[1]->value,
            array_values(self::allowedTransitions()),
        );

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                if (in_array($from->value.'>'.$to->value, $allowed, true)) {
                    continue;
                }

                $this->assertFalse($from->canTransitionTo($to), "{$from->value} → {$to->value} should be rejected");
            }
        }
    }

    public function test_shipped_orders_can_no_longer_be_cancelled(): void
    {
        $this->assertFalse(OrderStatus::Shipped->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Delivered->canTransitionTo(OrderStatus::Cancelled));
    }

    public function test_cancelled_and_returned_are_final(): void
    {
        $finals = array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->isFinal()));

        $this->assertSame([OrderStatus::Cancelled, OrderStatus::Returned], $finals);
    }

    public function test_only_cancelled_and_returned_restore_stock(): void
    {
        $restoring = array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->restoresStock()));

        $this->assertSame([OrderStatus::Cancelled, OrderStatus::Returned], $restoring);
        $this->assertSame([OrderStatus::Cancelled, OrderStatus::Returned], OrderStatus::nonRevenue());
    }

    public function test_every_status_has_a_label(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }
}
