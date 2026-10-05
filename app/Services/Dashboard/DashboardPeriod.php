<?php

namespace App\Services\Dashboard;

use App\Support\StrictDate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A dashboard date range, inclusive of both whole days, in the application
 * timezone (Asia/Dhaka). Timestamps are stored in that timezone, so the
 * bounds can be compared directly with created_at / paid_at.
 */
final readonly class DashboardPeriod
{
    /** @var array<string, string> */
    public const RANGES = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'this_month' => 'This month',
        'custom' => 'Custom range',
    ];

    public const DEFAULT_RANGE = 'last_7_days';

    public const MAX_CUSTOM_DAYS = 92;

    private function __construct(
        public string $range,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /**
     * @throws ValidationException for an invalid custom range (fields: from, to)
     */
    public static function resolve(string $range, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now())->startOfDay();

        return match ($range) {
            'today' => new self($range, $today, $today->endOfDay()),
            'yesterday' => new self($range, $today->subDay(), $today->subDay()->endOfDay()),
            'last_7_days' => new self($range, $today->subDays(6), $today->endOfDay()),
            'last_30_days' => new self($range, $today->subDays(29), $today->endOfDay()),
            'this_month' => new self($range, $today->startOfMonth(), $today->endOfDay()),
            'custom' => self::custom($from, $to),
            default => self::resolve(self::DEFAULT_RANGE, now: $now),
        };
    }

    public static function today(?CarbonImmutable $now = null): self
    {
        return self::resolve('today', now: $now);
    }

    private static function custom(?string $from, ?string $to): self
    {
        $start = StrictDate::parse($from);
        $end = StrictDate::parse($to);

        $errors = array_filter([
            'from' => $start ? null : 'Enter a valid start date (YYYY-MM-DD).',
            'to' => $end ? null : 'Enter a valid end date (YYYY-MM-DD).',
        ]);

        if ($errors === [] && $start->gt($end)) {
            $errors['to'] = 'The end date must be on or after the start date.';
        }

        if ($errors === [] && $start->diffInDays($end) + 1 > self::MAX_CUSTOM_DAYS) {
            $errors['to'] = 'Custom ranges can cover at most '.self::MAX_CUSTOM_DAYS.' days.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self('custom', $start->toImmutable()->startOfDay(), $end->toImmutable()->endOfDay());
    }

    public function label(): string
    {
        $dates = $this->start->isSameDay($this->end)
            ? $this->start->format('d M Y')
            : $this->start->format('d M Y').' – '.$this->end->format('d M Y');

        return self::RANGES[$this->range].' ('.$dates.')';
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end->startOfDay()) + 1;
    }
}
