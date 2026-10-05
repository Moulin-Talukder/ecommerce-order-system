<?php

namespace App\Enums;

/**
 * Interpretation of Steadfast delivery statuses, from Steadfast's API
 * documentation. The database keeps the raw string; use
 * SteadfastStatus::tryFrom() so unknown future statuses never break anything.
 */
enum SteadfastStatus: string
{
    case InReview = 'in_review';
    case Pending = 'pending';
    case Hold = 'hold';
    case DeliveredApprovalPending = 'delivered_approval_pending';
    case PartialDeliveredApprovalPending = 'partial_delivered_approval_pending';
    case CancelledApprovalPending = 'cancelled_approval_pending';
    case UnknownApprovalPending = 'unknown_approval_pending';
    case Delivered = 'delivered';
    case PartialDelivered = 'partial_delivered';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::InReview => 'In review',
            self::Pending => 'Pending pickup / in transit',
            self::Hold => 'On hold',
            self::DeliveredApprovalPending => 'Delivered (awaiting approval)',
            self::PartialDeliveredApprovalPending => 'Partially delivered (awaiting approval)',
            self::CancelledApprovalPending => 'Cancelled (awaiting approval)',
            self::UnknownApprovalPending => 'Unknown (awaiting approval)',
            self::Delivered => 'Delivered',
            self::PartialDelivered => 'Partially delivered',
            self::Cancelled => 'Cancelled',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Steadfast will not change this status any further.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::PartialDelivered, self::Cancelled], true);
    }

    /**
     * Human label for any raw status, including ones we do not know yet.
     */
    public static function describe(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }

        return self::tryFrom($raw)?->label() ?? ucfirst(str_replace('_', ' ', $raw)).' (unrecognised)';
    }
}
