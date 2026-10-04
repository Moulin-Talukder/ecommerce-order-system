<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, bool, PaymentStatus}>
     */
    public static function ledgerScenarios(): array
    {
        return [
            'nothing paid' => ['1060.00', '0.00', '0.00', false, PaymentStatus::Pending],
            'last attempt failed' => ['1060.00', '0.00', '0.00', true, PaymentStatus::Failed],
            'advance delivery charge' => ['1060.00', '60.00', '0.00', false, PaymentStatus::PartiallyPaid],
            'paid in full' => ['1060.00', '1060.00', '0.00', false, PaymentStatus::Paid],
            'overpaid counts as paid' => ['1060.00', '1100.00', '0.00', false, PaymentStatus::Paid],
            'paid after an earlier failure' => ['1060.00', '1060.00', '0.00', true, PaymentStatus::Paid],
            'partially refunded' => ['1060.00', '1060.00', '500.00', false, PaymentStatus::PartiallyRefunded],
            'fully refunded' => ['1060.00', '1060.00', '1060.00', false, PaymentStatus::Refunded],
            'one paisa short is partial' => ['1060.00', '1059.99', '0.00', false, PaymentStatus::PartiallyPaid],
        ];
    }

    #[DataProvider('ledgerScenarios')]
    public function test_status_is_derived_from_ledger_totals(
        string $total,
        string $paid,
        string $refunded,
        bool $lastAttemptFailed,
        PaymentStatus $expected,
    ): void {
        $this->assertSame($expected, PaymentStatus::fromLedger($total, $paid, $refunded, $lastAttemptFailed));
    }

    public function test_it_supports_exactly_the_approved_statuses(): void
    {
        $this->assertSame(
            ['pending', 'partially_paid', 'paid', 'failed', 'refunded', 'partially_refunded'],
            array_column(PaymentStatus::cases(), 'value'),
        );
    }
}
