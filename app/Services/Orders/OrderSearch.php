<?php

namespace App\Services\Orders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\Like;
use App\Support\PhoneNumber;
use App\Support\StrictDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Order list filtering, shared by the admin list (and a future API).
 *
 * Quick views map operational queues onto existing indexed columns
 * (status, payment_status, created_at) so the list stays cheap.
 */
class OrderSearch
{
    /**
     * Operational queues shown as tabs above the order list.
     *
     * @var array<string, string>
     */
    public const VIEWS = [
        'needs_confirmation' => 'Needs confirmation',
        'to_fulfil' => 'To fulfil',
        'ready_for_courier' => 'Ready for courier',
        'in_transit' => 'In transit',
        'awaiting_payment' => 'Delivered, unpaid',
        'completed' => 'Completed',
        'closed' => 'Cancelled / returned',
    ];

    /**
     * Normalize raw query-string input into typed filters (invalid values are dropped).
     *
     * @param  array<string, mixed>  $input
     * @return array{search: string, view: ?string, status: ?OrderStatus, payment_status: ?PaymentStatus, source: ?OrderSource, courier: ?string, customer: ?int, from: ?Carbon, to: ?Carbon}
     */
    public function filters(array $input): array
    {
        return [
            'search' => trim((string) ($input['search'] ?? '')),
            'view' => array_key_exists((string) ($input['view'] ?? ''), self::VIEWS) ? $input['view'] : null,
            'status' => OrderStatus::tryFrom((string) ($input['status'] ?? '')),
            'payment_status' => PaymentStatus::tryFrom((string) ($input['payment_status'] ?? '')),
            'source' => OrderSource::tryFrom((string) ($input['source'] ?? '')),
            'courier' => in_array($input['courier'] ?? null, ['booked', 'not_booked'], true) ? $input['courier'] : null,
            'customer' => filter_var($input['customer'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
            'from' => StrictDate::parse($input['from'] ?? null),
            'to' => StrictDate::parse($input['to'] ?? null),
        ];
    }

    /**
     * @param  array{search: string, view: ?string, status: ?OrderStatus, payment_status: ?PaymentStatus, source: ?OrderSource, courier: ?string, customer: ?int, from: ?Carbon, to: ?Carbon}  $filters
     * @return Builder<Order>
     */
    public function query(array $filters): Builder
    {
        return Order::query()
            ->when($filters['search'] !== '', fn (Builder $query) => $this->applySearch($query, $filters['search']))
            ->when($filters['view'], fn (Builder $query, string $view) => $this->applyView($query, $view))
            ->when($filters['status'], fn (Builder $query, OrderStatus $status) => $query->where('status', $status))
            ->when($filters['payment_status'], fn (Builder $query, PaymentStatus $status) => $query->where('payment_status', $status))
            ->when($filters['source'], fn (Builder $query, OrderSource $source) => $query->where('source', $source))
            ->when($filters['courier'] === 'booked', fn (Builder $query) => $query->whereHas('shipments', fn (Builder $query) => $query->booked()))
            ->when($filters['courier'] === 'not_booked', fn (Builder $query) => $query->whereDoesntHave('shipments', fn (Builder $query) => $query->booked()))
            ->when($filters['customer'], fn (Builder $query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['from'], fn (Builder $query, Carbon $from) => $query->where('created_at', '>=', $from->copy()->startOfDay()))
            ->when($filters['to'], fn (Builder $query, Carbon $to) => $query->where('created_at', '<=', $to->copy()->endOfDay()));
    }

    /**
     * Number of orders in each quick view (one grouped query).
     *
     * @return array<string, int>
     */
    public function viewCounts(): array
    {
        $byStatus = Order::query()->toBase()
            ->selectRaw('status, payment_status, COUNT(*) AS total')
            ->groupBy('status', 'payment_status')
            ->get();

        $count = fn (array $statuses, ?array $paymentStatuses = null) => (int) $byStatus
            ->filter(fn ($row) => in_array($row->status, array_map(fn ($s) => $s->value, $statuses), true)
                && ($paymentStatuses === null || in_array($row->payment_status, array_map(fn ($s) => $s->value, $paymentStatuses), true)))
            ->sum('total');

        return [
            'needs_confirmation' => $count([OrderStatus::Pending]),
            'to_fulfil' => $count([OrderStatus::Confirmed, OrderStatus::Processing]),
            'ready_for_courier' => $this->viewQuery('ready_for_courier')->count(),
            'in_transit' => $count([OrderStatus::Shipped]),
            'awaiting_payment' => $count([OrderStatus::Delivered], $this->unpaidStatuses()),
            'completed' => $count([OrderStatus::Delivered], [PaymentStatus::Paid]),
            'closed' => $count([OrderStatus::Cancelled, OrderStatus::Returned]),
        ];
    }

    /**
     * A fresh query for one queue, so other screens reuse the queue definitions.
     *
     * @return Builder<Order>
     */
    public function viewQuery(string $view): Builder
    {
        return $this->applyView(Order::query(), $view);
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function applyView(Builder $query, string $view): Builder
    {
        return match ($view) {
            'needs_confirmation' => $query->where('status', OrderStatus::Pending),
            'to_fulfil' => $query->whereIn('status', [OrderStatus::Confirmed, OrderStatus::Processing]),
            'ready_for_courier' => $query->where('status', OrderStatus::Processing)->whereDoesntHave('shipments', fn (Builder $query) => $query->booked()),
            'in_transit' => $query->where('status', OrderStatus::Shipped),
            'awaiting_payment' => $query->where('status', OrderStatus::Delivered)->whereIn('payment_status', $this->unpaidStatuses()),
            'completed' => $query->where('status', OrderStatus::Delivered)->where('payment_status', PaymentStatus::Paid),
            'closed' => $query->whereIn('status', [OrderStatus::Cancelled, OrderStatus::Returned]),
        };
    }

    /**
     * @return list<PaymentStatus>
     */
    private function unpaidStatuses(): array
    {
        return [PaymentStatus::Pending, PaymentStatus::PartiallyPaid, PaymentStatus::Failed];
    }

    /**
     * Order numbers use the unique index (prefix match); full phone numbers
     * use the customer_phone index (exact match); names fall back to LIKE.
     *
     * @param  Builder<Order>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $prefix = strtoupper(config('orders.number_prefix')).'-';

        if (str_starts_with(strtoupper($search), $prefix)) {
            $query->where('order_number', 'like', addcslashes(strtoupper($search), '%_\\').'%');

            return;
        }

        $phone = PhoneNumber::normalize($search);
        $digits = preg_replace('/\D+/', '', $search);

        $query->where(function (Builder $query) use ($search, $phone, $digits): void {
            $query->where('order_number', 'like', Like::contains($search))
                ->orWhere('customer_name', 'like', Like::contains($search));

            if ($phone !== null) {
                $query->orWhere('customer_phone', $phone);
            } elseif (strlen($digits) >= 4) {
                $query->orWhere('customer_phone', 'like', Like::contains($digits));
            }
        });
    }
}
