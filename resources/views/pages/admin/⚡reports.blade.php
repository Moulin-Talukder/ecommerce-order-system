<?php

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Reports\CustomerReport;
use App\Services\Reports\OrderReport;
use App\Services\Reports\PaymentReport;
use App\Services\Reports\ProductReport;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\SalesReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin reports. Figures come from the App\Services\Reports classes; this
 * component validates the filters (kept in the URL) and renders the active
 * report only, so each request runs a small, fixed number of queries.
 * Access: route middleware can:viewReports (OrderPolicy).
 */
new #[Layout('layouts::admin')] #[Title('Reports')] class extends Component
{
    use WithPagination;

    /** @var array<string, string> */
    public const REPORTS = [
        'sales' => 'Sales',
        'orders' => 'Orders',
        'products' => 'Products',
        'payments' => 'Payments',
        'customers' => 'Customers',
    ];

    /** Which optional filters each report supports. */
    private const FILTERS = [
        'sales' => ['source', 'payment_method'],
        'orders' => ['status', 'source', 'payment_method'],
        'products' => ['source', 'payment_method'],
        'payments' => [],
        'customers' => [],
    ];

    private const PER_PAGE = 15;

    #[Url]
    public string $report = 'sales';

    #[Url]
    public string $range = DashboardPeriod::DEFAULT_RANGE;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $source = '';

    #[Url(as: 'method')]
    public string $paymentMethod = '';

    /**
     * Any filter change starts every table from its first page.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['report', 'range', 'status', 'source', 'paymentMethod'], true)) {
            $this->resetFilters();
        }
    }

    public function applyCustomRange(): void
    {
        $this->range = 'custom';
        $this->resetFilters();
    }

    #[Computed]
    public function activeReport(): string
    {
        return array_key_exists($this->report, self::REPORTS) ? $this->report : 'sales';
    }

    /**
     * Validated filters for the active report. Invalid dates fall back to the
     * default range and unknown filter values to "all", with an error shown,
     * instead of reporting wrong numbers.
     */
    #[Computed]
    public function filters(): ReportFilters
    {
        try {
            $period = DashboardPeriod::resolve($this->range, $this->from, $this->to);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            $period = DashboardPeriod::resolve(DashboardPeriod::DEFAULT_RANGE);
        }

        $supported = self::FILTERS[$this->activeReport];

        return new ReportFilters(
            $period,
            in_array('status', $supported, true) ? $this->enumFilter(OrderStatus::class, $this->status, 'status') : null,
            in_array('source', $supported, true) ? $this->enumFilter(OrderSource::class, $this->source, 'source') : null,
            in_array('payment_method', $supported, true) ? $this->enumFilter(PaymentMethod::class, $this->paymentMethod, 'paymentMethod') : null,
        );
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function supportedFilters(): array
    {
        return self::FILTERS[$this->activeReport];
    }

    public function with(SalesReport $sales, OrderReport $orders, ProductReport $products, PaymentReport $payments, CustomerReport $customers): array
    {
        $filters = $this->filters;

        return match ($this->activeReport) {
            'sales' => [
                'sales' => $sales->summary($filters),
                'daily' => $sales->daily($filters),
            ],
            'orders' => [
                'byStatus' => $orders->byStatus($filters),
                'bySource' => $orders->bySource($filters),
                'byPaymentMethod' => $orders->byPaymentMethod($filters),
            ],
            'products' => [
                'productSummary' => $products->summary($filters),
                'bestSellers' => $products->bestSellers($filters, self::PER_PAGE, 'productsPage'),
                'restock' => Gate::allows('viewAny', Product::class) ? $products->restock(self::PER_PAGE, 'restockPage') : null,
            ],
            'payments' => [
                'collections' => $payments->collections($filters->period),
                'attempts' => $payments->incompleteAttempts($filters->period),
                'balances' => $payments->balances($filters),
            ],
            'customers' => Gate::allows('viewAny', Customer::class) ? [
                'customerSummary' => $customers->summary($filters->period),
                'customerRows' => $customers->customers($filters->period, self::PER_PAGE, 'customersPage'),
            ] : ['customerSummary' => null, 'customerRows' => null],
        };
    }

    private function resetFilters(): void
    {
        $this->resetErrorBag();
        unset($this->filters, $this->activeReport, $this->supportedFilters);

        foreach (['productsPage', 'restockPage', 'customersPage'] as $pageName) {
            $this->resetPage($pageName);
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumFilter(string $enum, string $value, string $field): ?BackedEnum
    {
        if ($value === '') {
            return null;
        }

        return $enum::tryFrom($value) ?? tap(null, fn () => $this->addError($field, 'Unknown filter value ignored; showing all.'));
    }
};
?>

@php
    $money = fn ($amount) => App\Support\Money::format($amount);
    $period = $this->filters->period;
    $periodQuery = array_filter(['range' => $range, 'from' => $range === 'custom' ? $from : null, 'to' => $range === 'custom' ? $to : null]);
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900">Reports</h1>
        <p class="mt-1 text-sm text-gray-500">All dates and times are Asia/Dhaka. Figures are calculated live from orders and the payments ledger.</p>
    </div>

    <nav aria-label="Reports" class="-mx-1 flex gap-1 overflow-x-auto border-b border-gray-200 pb-px">
        @foreach ($this::REPORTS as $key => $label)
            <button type="button" wire:click="$set('report', '{{ $key }}')"
                @if ($this->activeReport === $key) aria-current="page" @endif
                @class(['whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', 'border-gray-900 text-gray-900' => $this->activeReport === $key, 'border-transparent text-gray-500 hover:text-gray-900' => $this->activeReport !== $key])>
                {{ $label }}
            </button>
        @endforeach
    </nav>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4">
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
        @if (in_array('status', $this->supportedFilters, true))
            <div>
                <label for="status" class="block text-xs font-medium text-gray-600">Status</label>
                <select id="status" wire:model.live="status" class="mt-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm">
                    <option value="">All statuses</option>
                    @foreach (App\Enums\OrderStatus::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if (in_array('source', $this->supportedFilters, true))
            <div>
                <label for="source" class="block text-xs font-medium text-gray-600">Source</label>
                <select id="source" wire:model.live="source" class="mt-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm">
                    <option value="">All sources</option>
                    @foreach (App\Enums\OrderSource::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if (in_array('payment_method', $this->supportedFilters, true))
            <div>
                <label for="paymentMethod" class="block text-xs font-medium text-gray-600">Payment method</label>
                <select id="paymentMethod" wire:model.live="paymentMethod" class="mt-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm">
                    <option value="">All methods</option>
                    @foreach (App\Enums\PaymentMethod::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <p class="w-full text-xs text-gray-500">{{ $period->label() }}</p>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
            <p class="mt-1">Showing {{ $period->label() }}.</p>
        </div>
    @endif

    @switch($this->activeReport)
        @case('sales')
            <section aria-labelledby="sales-heading" class="space-y-4">
                <h2 id="sales-heading" class="sr-only">Sales</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs text-gray-500">Net sales</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $money($sales['net_sales']) }}</div>
                        <div class="text-xs text-gray-500">{{ $sales['revenue_orders'] }} {{ Str::plural('order', $sales['revenue_orders']) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs text-gray-500">Average order value</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $money($sales['average_order_value']) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs text-gray-500">Orders placed (any status)</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $sales['orders_placed'] }}</div>
                        <div class="text-xs text-gray-500">Gross order value {{ $money($sales['gross_value']) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs text-gray-500">Cancelled / returned (excluded)</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $sales['excluded_orders'] }}</div>
                        <div class="text-xs text-gray-500">{{ $money($sales['excluded_value']) }}</div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <h3 class="text-sm font-semibold text-gray-900">How net sales add up</h3>
                    <dl class="mt-2 max-w-md space-y-1 text-sm">
                        <div class="flex justify-between"><dt>Products at regular price</dt><dd class="tabular-nums">{{ $money($sales['gross_product_value']) }}</dd></div>
                        <div class="flex justify-between"><dt>− Product discounts</dt><dd class="tabular-nums">{{ $money($sales['product_discounts']) }}</dd></div>
                        <div class="flex justify-between"><dt>= Product sales</dt><dd class="tabular-nums">{{ $money($sales['product_sales']) }}</dd></div>
                        <div class="flex justify-between"><dt>− Order discounts</dt><dd class="tabular-nums">{{ $money($sales['order_discounts']) }}</dd></div>
                        <div class="flex justify-between"><dt>+ Delivery charges</dt><dd class="tabular-nums">{{ $money($sales['delivery_charges']) }}</dd></div>
                        <div class="flex justify-between border-t border-gray-100 pt-1 font-semibold"><dt>= Net sales</dt><dd class="tabular-nums">{{ $money($sales['net_sales']) }}</dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-gray-500">Orders are counted on the day they were placed. Cancelled and returned orders are excluded from net sales. Cash collected is in the Payments report.</p>
                </div>

                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <caption class="sr-only">Net sales per day</caption>
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <tr><th scope="col" class="px-4 py-3">Day</th><th scope="col" class="px-4 py-3 text-right">Orders</th><th scope="col" class="px-4 py-3 text-right">Net sales</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($daily as $day)
                                <tr><td class="px-4 py-2">{{ $day['label'] }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $day['orders'] }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $money($day['net_sales']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            @break

        @case('orders')
            <section aria-labelledby="orders-heading" class="space-y-4">
                <h2 id="orders-heading" class="sr-only">Orders</h2>
                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <caption class="px-4 pt-3 text-left text-sm font-semibold text-gray-900">By status</caption>
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <tr><th scope="col" class="px-4 py-3">Status</th><th scope="col" class="px-4 py-3 text-right">Orders</th><th scope="col" class="px-4 py-3 text-right">Order value</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($byStatus['rows'] as $row)
                                <tr>
                                    <td class="px-4 py-2"><a href="{{ route('admin.orders.index', ['status' => $row['status']->value]) }}" class="hover:underline">{{ $row['status']->label() }}</a></td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $row['orders'] }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $money($row['value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-gray-200 font-semibold">
                            <tr><th scope="row" class="px-4 py-2 text-left">Total</th><td class="px-4 py-2 text-right tabular-nums">{{ $byStatus['orders'] }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $money($byStatus['value']) }}</td></tr>
                        </tfoot>
                    </table>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ([['By source', $bySource, 'source'], ['By payment method', $byPaymentMethod, 'method']] as [$caption, $rows, $key])
                        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <caption class="px-4 pt-3 text-left text-sm font-semibold text-gray-900">{{ $caption }}</caption>
                                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    <tr><th scope="col" class="px-4 py-3">{{ $key === 'source' ? 'Source' : 'Method' }}</th><th scope="col" class="px-4 py-3 text-right">Orders</th><th scope="col" class="px-4 py-3 text-right">Cancelled / returned</th><th scope="col" class="px-4 py-3 text-right">Net sales</th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($rows as $row)
                                        <tr><td class="px-4 py-2">{{ $row[$key]->label() }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $row['orders'] }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $row['excluded_orders'] }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $money($row['net_sales']) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500">Orders placed in the period. Order value includes every status; net sales excludes cancelled and returned orders.</p>
            </section>
            @break

        @case('products')
            <section aria-labelledby="products-heading" class="space-y-4">
                <h2 id="products-heading" class="sr-only">Products</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Units sold</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ $productSummary['units'] }}</div></div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Product sales value</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ $money($productSummary['sales_value']) }}</div></div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Products sold</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ $productSummary['products'] }}</div></div>
                </div>

                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <caption class="px-4 pt-3 text-left text-sm font-semibold text-gray-900">Best sellers</caption>
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <tr><th scope="col" class="px-4 py-3">#</th><th scope="col" class="px-4 py-3">Product</th><th scope="col" class="px-4 py-3 text-right">Units</th><th scope="col" class="px-4 py-3 text-right">Sales value</th><th scope="col" class="px-4 py-3 text-right">Orders</th><th scope="col" class="px-4 py-3 text-right">In stock now</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($bestSellers as $row)
                                <tr>
                                    <td class="px-4 py-2 tabular-nums text-gray-500">{{ $bestSellers->firstItem() + $loop->index }}</td>
                                    <td class="px-4 py-2"><a href="{{ route('admin.products.show', $row->id) }}" class="font-medium hover:underline">{{ $row->name }}</a> <span class="font-mono text-xs text-gray-500">{{ $row->sku }}</span></td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ (int) $row->units }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $money($row->sales_value) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ (int) $row->orders }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $row->manage_stock ? $row->stock_quantity : 'Not tracked' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No products were sold in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <div class="px-4 py-3">{{ $bestSellers->links() }}</div>
                </div>
                <p class="text-xs text-gray-500">From cancelled- and returned-excluded orders placed in the period. Sales value is after product discounts and before order-level discounts.</p>

                @if ($restock)
                    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <caption class="px-4 pt-3 text-left text-sm font-semibold text-gray-900">Needs restocking <span class="font-normal text-gray-500">· right now ({{ $restock->total() }})</span></caption>
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <tr><th scope="col" class="px-4 py-3">Product</th><th scope="col" class="px-4 py-3">Stock</th><th scope="col" class="px-4 py-3 text-right">Quantity</th><th scope="col" class="px-4 py-3 text-right">Low-stock threshold</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($restock as $product)
                                    <tr>
                                        <td class="px-4 py-2"><a href="{{ route('admin.products.show', $product) }}" class="font-medium hover:underline">{{ $product->name }}</a> <span class="font-mono text-xs text-gray-500">{{ $product->sku }}</span></td>
                                        <td class="px-4 py-2"><x-admin.stock-badge :product="$product" /></td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $product->manage_stock ? $product->stock_quantity : '—' }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $product->low_stock_threshold ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">No products need restocking.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div class="px-4 py-3">{{ $restock->links() }}</div>
                    </div>
                @endif
            </section>
            @break

        @case('payments')
            <section aria-labelledby="payments-heading" class="space-y-4">
                <h2 id="payments-heading" class="sr-only">Payments</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs text-gray-500">Net collected</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $money($collections['net']) }}</div>
                        <div class="text-xs text-gray-500">{{ $money($collections['payments']) }} received ({{ $collections['payment_count'] }}) − {{ $money($collections['refunds']) }} refunded ({{ $collections['refund_count'] }})</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs text-gray-500">Outstanding on orders placed</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $money($balances['outstanding']) }}</div>
                        <div class="text-xs text-gray-500">Billed {{ $money($balances['billed']) }} · received {{ $money($balances['received']) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs text-gray-500">Unpaid orders</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $balances['unpaid_orders'] }}</div>
                        <div class="text-xs text-gray-500">{{ $money($balances['unpaid_due']) }} due</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs text-gray-500">Partially paid orders</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $balances['partial_orders'] }}</div>
                        <div class="text-xs text-gray-500">{{ $money($balances['partial_due']) }} due</div>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <caption class="px-4 pt-3 text-left text-sm font-semibold text-gray-900">Collected by method</caption>
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <tr><th scope="col" class="px-4 py-3">Method</th><th scope="col" class="px-4 py-3 text-right">Received</th><th scope="col" class="px-4 py-3 text-right">Refunded</th><th scope="col" class="px-4 py-3 text-right">Net</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($collections['by_method'] as $row)
                                    <tr><td class="px-4 py-2">{{ $row['method']->label() }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $money($row['payments']) }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $money($row['refunds']) }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $money($row['net']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm">
                        <h3 class="font-semibold text-gray-900">Orders placed in the period</h3>
                        <dl class="mt-2 space-y-1">
                            <div class="flex justify-between"><dt>Orders (excl. cancelled/returned)</dt><dd class="tabular-nums">{{ $balances['revenue_orders'] }}</dd></div>
                            <div class="flex justify-between"><dt>Fully paid</dt><dd class="tabular-nums">{{ $balances['paid_orders'] }}</dd></div>
                            <div class="flex justify-between"><dt>Refunded or partly refunded (any status)</dt><dd class="tabular-nums">{{ $balances['refunded_orders'] }}</dd></div>
                            <div class="flex justify-between"><dt>Cancelled/returned still holding payment</dt><dd class="tabular-nums">{{ $balances['excluded_paid_orders'] }} · {{ $money($balances['excluded_paid_amount']) }}</dd></div>
                            <div class="flex justify-between"><dt>Payment attempts pending / failed</dt><dd class="tabular-nums">{{ $attempts['pending'] }} / {{ $attempts['failed'] }}</dd></div>
                        </dl>
                    </div>
                </div>
                <p class="text-xs text-gray-500">Collected counts completed payments and refunds by the date the money moved (any order). Outstanding, unpaid and partially paid cover orders placed in the period, excluding cancelled and returned orders.</p>
            </section>
            @break

        @case('customers')
            <section aria-labelledby="customers-heading" class="space-y-4">
                <h2 id="customers-heading" class="sr-only">Customers</h2>
                @if ($customerSummary)
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Customers who bought</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ $customerSummary['customers'] }}</div></div>
                        <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">New customers</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ $customerSummary['new'] }}</div></div>
                        <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Returning customers</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ $customerSummary['returning'] }}</div></div>
                        <div class="rounded-lg border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">Their orders / spend</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ $customerSummary['orders'] }}</div><div class="text-xs text-gray-500">{{ $money($customerSummary['spend']) }}</div></div>
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <caption class="px-4 pt-3 text-left text-sm font-semibold text-gray-900">Top customers in the period</caption>
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <tr><th scope="col" class="px-4 py-3">Customer</th><th scope="col" class="px-4 py-3 text-right">Orders</th><th scope="col" class="px-4 py-3 text-right">Spend</th><th scope="col" class="px-4 py-3 text-right">Lifetime orders</th><th scope="col" class="px-4 py-3 text-right">Lifetime spend</th><th scope="col" class="px-4 py-3">First order</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($customerRows as $row)
                                    <tr>
                                        <td class="px-4 py-2">
                                            <a href="{{ route('admin.customers.show', $row['customer']) }}" class="font-medium hover:underline">{{ $row['customer']->name }}</a>
                                            <span class="block font-mono text-xs text-gray-500">{{ App\Support\PhoneNumber::mask($row['customer']->phone) }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $row['period_orders'] }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $money($row['period_spend']) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $row['lifetime_orders'] }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $money($row['lifetime_spend']) }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap">{{ $row['first_order_at']->format('d M Y') }} <x-admin.badge :tone="$row['is_new'] ? 'green' : 'gray'">{{ $row['is_new'] ? 'New' : 'Returning' }}</x-admin.badge></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No customers bought in this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div class="px-4 py-3">{{ $customerRows->links() }}</div>
                    </div>
                    <p class="text-xs text-gray-500">Cancelled and returned orders are not counted. New means the customer's first order was placed in this period; returning means they ordered before it.</p>
                @else
                    <p class="rounded-md border border-gray-200 bg-white px-4 py-6 text-center text-sm text-gray-500">You do not have access to customer reports.</p>
                @endif
            </section>
            @break
    @endswitch
</div>
