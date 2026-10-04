<x-layouts::admin title="Orders">
    <x-admin.page-header title="Orders" description="{{ $orders->total() }} {{ Str::plural('order', $orders->total()) }}{{ $isFiltered ? ' match your filters' : '' }}.">
        @can('create', App\Models\Order::class)
            <x-slot:actions>
                <x-admin.button :href="route('admin.orders.create')">New order</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    <form method="GET" action="{{ route('admin.orders.index') }}" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        @php($filterClass = 'block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900')
        <div class="lg:col-span-2">
            <label for="search" class="sr-only">Search</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Order number, customer name or phone…" class="{{ $filterClass }}">
        </div>
        <div>
            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status" class="{{ $filterClass }}">
                <option value="">All statuses</option>
                @foreach (App\Enums\OrderStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($filters['status'] === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="payment_status" class="sr-only">Payment</label>
            <select id="payment_status" name="payment_status" class="{{ $filterClass }}">
                <option value="">All payments</option>
                @foreach (App\Enums\PaymentStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($filters['payment_status'] === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="source" class="sr-only">Source</label>
            <select id="source" name="source" class="{{ $filterClass }}">
                <option value="">All sources</option>
                @foreach (App\Enums\OrderSource::cases() as $case)
                    <option value="{{ $case->value }}" @selected($filters['source'] === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2">
            <label for="from" class="sr-only">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from']?->format('Y-m-d') }}" class="{{ $filterClass }}" title="From date">
            <label for="to" class="sr-only">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to']?->format('Y-m-d') }}" class="{{ $filterClass }}" title="To date">
        </div>
        <div class="flex items-center gap-3 lg:col-span-6">
            <x-admin.button variant="secondary">Filter</x-admin.button>
            @if ($isFiltered)
                <a href="{{ route('admin.orders.index') }}" class="text-sm text-gray-600 underline hover:text-gray-900">Clear filters</a>
            @endif
        </div>
    </form>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Order</th>
                    <th scope="col" class="px-4 py-3">Customer</th>
                    <th scope="col" class="px-4 py-3 text-right">Items</th>
                    <th scope="col" class="px-4 py-3 text-right">Total</th>
                    <th scope="col" class="px-4 py-3">Payment</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($orders as $order)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.orders.show', $order) }}" class="font-mono text-xs font-medium text-gray-900 hover:underline">{{ $order->order_number }}</a>
                            <div class="text-xs text-gray-500">{{ $order->created_at->format('d M Y, h:i A') }} · {{ $order->source->label() }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-gray-900">{{ $order->customer_name }}</div>
                            <div class="font-mono text-xs text-gray-500">{{ $order->customer_phone }}</div>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ $order->items_count }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums font-medium text-gray-900">{{ App\Support\Money::format($order->total, $order->currency) }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <x-admin.payment-status-badge :status="$order->payment_status" />
                            <div class="mt-0.5 text-xs text-gray-500">{{ $order->payment_method->label() }}</div>
                        </td>
                        <td class="px-4 py-3"><x-admin.order-status-badge :status="$order->status" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                            {{ $isFiltered ? 'No orders match your filters.' : 'No orders yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $orders->links() }}</div>
</x-layouts::admin>
