<?php

namespace App\Enums;

/**
 * Payment summary of an order.
 *
 * This is a cached value derived from the payments ledger; it is never set
 * directly from user input. See {@see self::fromLedger()}.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially Refunded',
        };
    }

    /**
     * Derive the summary status from ledger totals.
     *
     * All amounts are decimal strings (2 dp) so they can be compared with bcmath.
     *
     * @param  string  $orderTotal  The order's total.
     * @param  string  $paid  Sum of completed payment rows.
     * @param  string  $refunded  Sum of completed refund rows.
     * @param  bool  $lastAttemptFailed  Whether the most recent payment attempt failed.
     */
    public static function fromLedger(string $orderTotal, string $paid, string $refunded, bool $lastAttemptFailed = false): self
    {
        if (bccomp($refunded, '0', 2) === 1) {
            return bccomp($refunded, $paid, 2) >= 0 ? self::Refunded : self::PartiallyRefunded;
        }

        if (bccomp($paid, '0', 2) === 1) {
            return bccomp($paid, $orderTotal, 2) >= 0 ? self::Paid : self::PartiallyPaid;
        }

        return $lastAttemptFailed ? self::Failed : self::Pending;
    }
}
