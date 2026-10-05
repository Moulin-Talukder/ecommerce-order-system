<?php

namespace Tests\Unit\Courier;

use App\Enums\SteadfastStatus;
use PHPUnit\Framework\TestCase;

class SteadfastStatusTest extends TestCase
{
    public function test_documented_statuses_are_recognised(): void
    {
        foreach (['pending', 'delivered_approval_pending', 'partial_delivered_approval_pending', 'cancelled_approval_pending',
            'unknown_approval_pending', 'delivered', 'partial_delivered', 'cancelled', 'hold', 'in_review', 'unknown'] as $raw) {
            $this->assertNotNull(SteadfastStatus::tryFrom($raw), $raw);
        }
    }

    public function test_only_delivered_partial_and_cancelled_are_final(): void
    {
        $final = array_values(array_filter(SteadfastStatus::cases(), fn ($status) => $status->isFinal()));

        $this->assertSame([SteadfastStatus::Delivered, SteadfastStatus::PartialDelivered, SteadfastStatus::Cancelled], $final);
    }

    public function test_unknown_and_empty_values_are_described_safely(): void
    {
        $this->assertSame('In review', SteadfastStatus::describe('in_review'));
        $this->assertSame('Out for delivery (unrecognised)', SteadfastStatus::describe('out_for_delivery'));
        $this->assertSame('—', SteadfastStatus::describe(null));
        $this->assertSame('—', SteadfastStatus::describe(''));
    }
}
