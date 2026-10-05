<?php

use App\Models\Order;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\Dashboard\DashboardPeriod;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operational dashboard. All figures come from DashboardMetrics; this
 * component only resolves the date range (kept in the URL) and renders.
 */
new #[Layout('layouts::admin')] #[Title('Dashboard')] class extends Component
{
    #[Url]
    public string $range = DashboardPeriod::DEFAULT_RANGE;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updatedRange(): void
    {
        $this->resetErrorBag();
        unset($this->period);
    }

    /**
     * Apply the custom from/to dates.
     */
    public function applyCustomRange(): void
    {
        $this->range = 'custom';
        $this->resetErrorBag();
        unset($this->period);
    }

    /**
     * The selected period; an invalid custom range reports its errors and
     * falls back to the default range instead of showing wrong numbers.
     */
    #[Computed]
    public function period(): DashboardPeriod
    {
        try {
            return DashboardPeriod::resolve($this->range, $this->from, $this->to);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return DashboardPeriod::resolve(DashboardPeriod::DEFAULT_RANGE);
        }
    }

    #[Computed]
    public function canViewSales(): bool
    {
        return Gate::allows('viewSalesSummary', Order::class);
    }

    public function with(DashboardMetrics $metrics): array
    {
        $period = $this->period;
        $today = DashboardPeriod::today();
        $showSales = $this->canViewSales;
        $series = $metrics->dailySeries($period);

        return [
            'period' => $period,
            'queues' => $metrics->queueCounts(),
            'todayOrders' => $metrics->orderSummary($today),
            'periodOrders' => $metrics->orderSummary($period),
            'todaySales' => $showSales ? $metrics->salesSummary($today) : null,
            'periodSales' => $showSales ? $metrics->salesSummary($period) : null,
            'outstanding' => $showSales ? $metrics->outstanding() : null,
            'series' => $series,
            'maxDailyOrders' => max(1, ...array_column($series, 'orders')),
            'recentOrders' => $metrics->recentOrders(),
            'restock' => $metrics->productsNeedingRestock(),
        ];
    }
};
?>

@php
    $money = fn ($amount) => App\Support\Money::format($amount);
    $queueLabels = App\Services\Orders\OrderSearch::VIEWS;
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Dashboard</h1>
            <p class="mt-1 text-sm text-gray-500">Welcome back, {{ auth()->user()->name }}. All times are Asia/Dhaka.</p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <div>
                <label for="range" class="block text-xs font-medium text-gray-600">Period</label>
                <select id="range" wire:model.live="range" class="mt-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
                    @foreach (App\Services\Dashboard\DashboardPeriod::RANGES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($range === 'custom')
                <form wire:submit="applyCustomRange" class="flex flex-wrap items-end gap-2">
                    <div>
                        <label for="from" class="block text-xs font-medium text-gray-600">From</label>
                        <input id="from" type="date" wire:model="from" class="mt-1 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm">
                    </div>
                    <div>
                        <label for="to" class="block text-xs font-medium text-gray-600">To</label>
                        <input id="to" type="date" wire:model="to" class="mt-1 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm">
                    </div>
                    <x-admin.button variant="secondary">Apply</x-admin.button>
                </form>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
            <p class="mt-1">Showing {{ $period->label() }} instead.</p>
        </div>
    @endif

    {{-- Work queues (snapshot, same definitions as the order list tabs) --}}
    <section>
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Work queues <span class="font-normal text-gray-500">· right now</span></h2>
        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-7">
            @foreach ($queues as $key => $count)
                <a href="{{ route('admin.orders.index', ['view' => $key]) }}"
                    @class(['rounded-lg border bg-white p-4 hover:border-gray-400', 'border-yellow-300' => $count > 0 && in_array($key, ['needs_confirmation', 'awaiting_payment'], true), 'border-gray-200' => ! ($count > 0 && in_array($key, ['needs_confirmation', 'awaiting_payment'], true))])>
                    <div class="text-xs text-gray-500">{{ $queueLabels[$key] }}</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $count }}</div>
                </a>
            @endforeach
        </div>
    </section>

    {{-- Headline metrics --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">Today's orders</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $todayOrders['orders'] }}</div>
            <div class="text-xs text-gray-500">{{ $todayOrders['cancelled_or_returned'] }} cancelled / returned</div>
        </div>
        @if ($todaySales)
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="text-xs text-gray-500">Today's sales</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $money($todaySales['sales']) }}</div>
                <div class="text-xs text-gray-500">Collected today {{ $money($todaySales['collected']) }}</div>
            </div>
        @endif
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">Orders · {{ App\Services\Dashboard\DashboardPeriod::RANGES[$period->range] }}</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $periodOrders['orders'] }}</div>
            <div class="text-xs text-gray-500">{{ $periodOrders['cancelled_or_returned'] }} cancelled / returned</div>
        </div>
        @if ($periodSales)
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="text-xs text-gray-500">Sales · {{ App\Services\Dashboard\DashboardPeriod::RANGES[$period->range] }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $money($periodSales['sales']) }}</div>
                <div class="text-xs text-gray-500">Avg. order {{ $money($periodSales['average_order_value']) }} · Collected {{ $money($periodSales['collected']) }}</div>
            </div>
        @endif
    </section>
    <p class="-mt-3 text-xs text-gray-500">
        Period: {{ $period->label() }}. Orders count every order placed in the period; sales exclude cancelled and returned orders;
        collected is payments minus refunds recorded in the period.
    </p>

    @if ($outstanding)
        <section class="grid gap-3 sm:grid-cols-2">
            <a href="{{ route('admin.orders.index', ['view' => 'awaiting_payment']) }}" class="rounded-lg border border-gray-200 bg-white p-4 hover:border-gray-400">
                <div class="text-xs text-gray-500">Delivered but not fully paid · right now</div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-gray-900">{{ $money($outstanding['delivered_unpaid_amount']) }}</div>
                <div class="text-xs text-gray-500">{{ $outstanding['delivered_unpaid_orders'] }} {{ Str::plural('order', $outstanding['delivered_unpaid_orders']) }} (e.g. COD cash not yet recorded)</div>
            </a>
            <a href="{{ route('admin.orders.index', ['payment_status' => 'partially_paid']) }}" class="rounded-lg border border-gray-200 bg-white p-4 hover:border-gray-400">
                <div class="text-xs text-gray-500">Partially paid open orders · right now</div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-gray-900">{{ $money($outstanding['partially_paid_amount']) }} due</div>
                <div class="text-xs text-gray-500">{{ $outstanding['partially_paid_orders'] }} {{ Str::plural('order', $outstanding['partially_paid_orders']) }}</div>
            </a>
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Orders per day --}}
        <section class="rounded-lg border border-gray-200 bg-white p-6 lg:col-span-2">
            <h2 class="text-sm font-semibold text-gray-900">Orders per day</h2>
            <p class="text-xs text-gray-500">{{ $period->label() }}</p>
            <div class="mt-4 flex h-40 items-end gap-1" aria-hidden="true">
                @foreach ($series as $day)
                    <div class="flex h-full flex-1 flex-col justify-end" title="{{ $day['label'] }}: {{ $day['orders'] }} orders{{ $periodSales ? ', '.$money($day['sales']) : '' }}">
                        <div class="rounded-t bg-gray-800" style="height: {{ round($day['orders'] / $maxDailyOrders * 100) }}%; min-height: {{ $day['orders'] > 0 ? '2px' : '0' }}"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between text-xs text-gray-500" aria-hidden="true">
                <span>{{ $series[0]['label'] }}</span>
                <span>{{ end($series)['label'] }}</span>
            </div>
            <details class="mt-3 text-sm">
                <summary class="cursor-pointer text-xs text-gray-600">Show as table</summary>
                <table class="mt-2 w-full text-left text-xs">
                    <thead><tr class="text-gray-500"><th class="py-1">Day</th><th class="py-1 text-right">Orders</th>@if ($periodSales)<th class="py-1 text-right">Sales</th>@endif</tr></thead>
                    <tbody>
                        @foreach ($series as $day)
                            <tr><td class="py-0.5">{{ $day['label'] }}</td><td class="py-0.5 text-right tabular-nums">{{ $day['orders'] }}</td>@if ($periodSales)<td class="py-0.5 text-right tabular-nums">{{ $money($day['sales']) }}</td>@endif</tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        </section>

        {{-- Status distribution --}}
        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="text-sm font-semibold text-gray-900">Status of orders placed in the period</h2>
            <ul class="mt-4 space-y-2 text-sm">
                @foreach (App\Enums\OrderStatus::cases() as $status)
                    @php($count = $periodOrders['by_status'][$status->value])
                    <li>
                        <div class="flex justify-between"><span class="text-gray-700">{{ $status->label() }}</span><span class="tabular-nums text-gray-900">{{ $count }}</span></div>
                        <div class="mt-1 h-1.5 rounded bg-gray-100">
                            <div class="h-1.5 rounded bg-gray-700" style="width: {{ $periodOrders['orders'] > 0 ? round($count / $periodOrders['orders'] * 100) : 0 }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Recent orders --}}
        <section class="rounded-lg border border-gray-200 bg-white">
            <div class="flex items-baseline justify-between border-b border-gray-100 px-6 py-4">
                <h2 class="text-sm font-semibold text-gray-900">Recent orders</h2>
                <a href="{{ route('admin.orders.index') }}" class="text-xs text-gray-600 underline hover:text-gray-900">All orders</a>
            </div>
            @if ($recentOrders->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">No orders yet.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($recentOrders as $order)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-6 py-3">
                            <div>
                                <a href="{{ route('admin.orders.show', $order) }}" class="font-mono text-xs font-medium text-gray-900 hover:underline">{{ $order->order_number }}</a>
                                <div class="text-xs text-gray-500">{{ $order->customer_name }} · {{ $order->created_at->format('d M, h:i A') }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="tabular-nums text-gray-900">{{ App\Support\Money::format($order->total, $order->currency) }}</span>
                                <x-admin.payment-status-badge :status="$order->payment_status" />
                                <x-admin.order-status-badge :status="$order->status" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Products needing restock --}}
        <section class="rounded-lg border border-gray-200 bg-white">
            <div class="flex items-baseline justify-between border-b border-gray-100 px-6 py-4">
                <h2 class="text-sm font-semibold text-gray-900">Products needing restock</h2>
                @if ($restock['total'] > 0)
                    <span class="text-xs text-gray-500">{{ $restock['total'] }} {{ Str::plural('product', $restock['total']) }}</span>
                @endif
            </div>
            @if ($restock['products']->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">All products are sufficiently stocked.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($restock['products'] as $product)
                        <li class="flex items-center justify-between gap-2 px-6 py-3">
                            <div>
                                <a href="{{ route('admin.products.show', $product) }}" class="font-medium text-gray-900 hover:underline">{{ $product->name }}</a>
                                <div class="font-mono text-xs text-gray-500">{{ $product->sku }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($product->manage_stock)
                                    <span class="tabular-nums text-gray-700">{{ $product->stock_quantity }}{{ $product->low_stock_threshold !== null ? ' / '.$product->low_stock_threshold : '' }}</span>
                                @endif
                                <x-admin.stock-badge :product="$product" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
