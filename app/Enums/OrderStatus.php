<?php

namespace App\Enums;

/**
 * Lifecycle of an order.
 *
 * To add a status: add a case, its label, and its entries in transitions().
 * The column is a VARCHAR, so no migration is required.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Processing',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
        };
    }

    /**
     * Button label for moving an order into this status.
     */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Pending => 'Mark pending',
            self::Confirmed => 'Confirm order',
            self::Processing => 'Start processing',
            self::Shipped => 'Mark shipped',
            self::Delivered => 'Mark delivered',
            self::Cancelled => 'Cancel order',
            self::Returned => 'Mark returned',
        };
    }

    /**
     * Whether delivery details (address, phones, recipient) may still be corrected.
     */
    public function allowsDeliveryChanges(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Processing], true);
    }

    /**
     * Statuses this status may move to.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Processing, self::Cancelled],
            self::Processing => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered, self::Returned],
            self::Delivered => [self::Returned],
            self::Cancelled, self::Returned => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->transitions(), true);
    }

    /**
     * Whether no further status changes are possible.
     */
    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }

    /**
     * Whether entering this status puts the order's stock back into inventory.
     */
    public function restoresStock(): bool
    {
        return in_array($this, [self::Cancelled, self::Returned], true);
    }

    /**
     * Statuses excluded from sales figures.
     *
     * @return list<self>
     */
    public static function nonRevenue(): array
    {
        return [self::Cancelled, self::Returned];
    }
}
