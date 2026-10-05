<?php

namespace Tests\Unit\Dashboard;

use App\Services\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardPeriodTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-10-15 14:30:00', 'Asia/Dhaka');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function ranges(): array
    {
        return [
            'today' => ['today', '2026-10-15 00:00:00', '2026-10-15 23:59:59'],
            'yesterday' => ['yesterday', '2026-10-14 00:00:00', '2026-10-14 23:59:59'],
            'last 7 days includes today' => ['last_7_days', '2026-10-09 00:00:00', '2026-10-15 23:59:59'],
            'last 30 days includes today' => ['last_30_days', '2026-09-16 00:00:00', '2026-10-15 23:59:59'],
            'this month to date' => ['this_month', '2026-10-01 00:00:00', '2026-10-15 23:59:59'],
        ];
    }

    #[DataProvider('ranges')]
    public function test_preset_ranges_cover_whole_days_in_dhaka_time(string $range, string $start, string $end): void
    {
        $period = DashboardPeriod::resolve($range, now: $this->now);

        $this->assertSame($start, $period->start->format('Y-m-d H:i:s'));
        $this->assertSame($end, $period->end->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Dhaka', $period->start->getTimezone()->getName());
    }

    public function test_day_counts(): void
    {
        $this->assertSame(1, DashboardPeriod::resolve('today', now: $this->now)->days());
        $this->assertSame(7, DashboardPeriod::resolve('last_7_days', now: $this->now)->days());
        $this->assertSame(15, DashboardPeriod::resolve('this_month', now: $this->now)->days());
    }

    public function test_unknown_ranges_fall_back_to_the_default(): void
    {
        $this->assertSame('last_7_days', DashboardPeriod::resolve('forever', now: $this->now)->range);
    }

    public function test_a_valid_custom_range(): void
    {
        $period = DashboardPeriod::resolve('custom', '2026-09-01', '2026-09-30');

        $this->assertSame('2026-09-01 00:00:00', $period->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 23:59:59', $period->end->format('Y-m-d H:i:s'));
        $this->assertSame(30, $period->days());
        $this->assertSame('Custom range (01 Sep 2026 – 30 Sep 2026)', $period->label());
    }

    public function test_a_single_day_custom_range(): void
    {
        $this->assertSame(1, DashboardPeriod::resolve('custom', '2026-09-01', '2026-09-01')->days());
    }

    /**
     * @return array<string, array{?string, ?string, string}>
     */
    public static function invalidCustomRanges(): array
    {
        return [
            'impossible month' => ['2026-13-01', '2026-12-31', 'from'],
            'impossible day' => ['2026-02-30', '2026-03-05', 'from'],
            'not a date' => ['yesterday', '2026-03-05', 'from'],
            'wrong format' => ['01/09/2026', '2026-09-30', 'from'],
            'missing end' => ['2026-09-01', null, 'to'],
            'end before start' => ['2026-09-30', '2026-09-01', 'to'],
            'longer than 92 days' => ['2026-01-01', '2026-04-03', 'to'],
        ];
    }

    #[DataProvider('invalidCustomRanges')]
    public function test_invalid_custom_ranges_are_rejected_without_rolling_over(?string $from, ?string $to, string $field): void
    {
        try {
            DashboardPeriod::resolve('custom', $from, $to);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_exactly_92_days_is_allowed(): void
    {
        $this->assertSame(92, DashboardPeriod::resolve('custom', '2026-01-01', '2026-04-02')->days());
    }
}
