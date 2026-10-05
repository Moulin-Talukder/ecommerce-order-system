<x-layouts::admin :title="$customer->name">
    <x-admin.page-header :title="$customer->name" :description="'Customer since '.$customer->created_at->format('d M Y')">
        <x-slot:actions>
            @can('create', App\Models\Order::class)
                @if ($customer->status !== App\Enums\CustomerStatus::Blocked)
                    <x-admin.button variant="secondary" :href="route('admin.orders.create', ['customer' => $customer->id])">New order</x-admin.button>
                @endif
            @endcan
            @can('update', $customer)
                <x-admin.button :href="route('admin.customers.edit', $customer)">Edit</x-admin.button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Summary (computed server-side from the orders table) --}}
            <section class="grid gap-4 sm:grid-cols-4">
                @foreach ([
                    'Orders' => $summary['orders_count'],
                    'Delivered' => $summary['completed_orders_count'],
                    'Cancelled / returned' => $summary['cancelled_or_returned_count'],
                    'Total spent' => App\Support\Money::format($summary['total_spent']),
                ] as $label => $value)
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        <div class="text-xs text-gray-500">{{ $label }}</div>
                        <div class="mt-1 text-xl font-semibold tabular-nums text-gray-900">{{ $value }}</div>
                    </div>
                @endforeach
            </section>

            {{-- Order history --}}
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="flex items-baseline justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-900">Order history</h2>
                    @if ($summary['last_order_at'])
                        <span class="text-xs text-gray-500">
                            Last order {{ $summary['last_order_at']->format('d M Y') }}
                            · <a href="{{ route('admin.orders.index', ['customer' => $customer->id]) }}" class="underline hover:text-gray-900">Open in order list</a>
                        </span>
                    @endif
                </div>

                @if ($orders->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-gray-500">This customer has not placed any orders yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th scope="col" class="px-6 py-3">Order</th>
                                    <th scope="col" class="px-6 py-3">Date</th>
                                    <th scope="col" class="px-6 py-3 text-right">Items</th>
                                    <th scope="col" class="px-6 py-3 text-right">Total</th>
                                    <th scope="col" class="px-6 py-3">Status</th>
                                    <th scope="col" class="px-6 py-3">Payment</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($orders as $order)
                                    <tr>
                                        <td class="px-6 py-3"><a href="{{ route('admin.orders.show', $order) }}" class="font-mono text-xs font-medium text-gray-900 hover:underline">{{ $order->order_number }}</a></td>
                                        <td class="px-6 py-3 text-gray-700">{{ $order->created_at->format('d M Y') }}</td>
                                        <td class="px-6 py-3 text-right tabular-nums text-gray-700">{{ $order->items_count }}</td>
                                        <td class="px-6 py-3 text-right tabular-nums text-gray-900">{{ App\Support\Money::format($order->total, $order->currency) }}</td>
                                        <td class="px-6 py-3"><x-admin.order-status-badge :status="$order->status" /></td>
                                        <td class="px-6 py-3 text-gray-700">{{ $order->payment_status->label() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($orders->hasPages())
                        <div class="border-t border-gray-100 px-6 py-3">{{ $orders->links() }}</div>
                    @endif
                @endif
            </section>
        </div>

        <div class="space-y-6">
            {{-- Contact --}}
            <section class="rounded-lg border border-gray-200 bg-white p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">Contact</h2>
                    <x-admin.customer-status-badge :status="$customer->status" />
                </div>
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-gray-500">Phone</dt><dd class="font-mono text-gray-900">{{ $customer->phone }}</dd></div>
                    @if ($customer->alternate_phone)
                        <div><dt class="text-gray-500">Alternate phone</dt><dd class="font-mono text-gray-900">{{ $customer->alternate_phone }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">Email</dt><dd class="text-gray-900">{{ $customer->email ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500">Default address</dt>
                        <dd class="text-gray-900">
                            @if ($customer->address || $customer->area || $customer->city)
                                <span class="whitespace-pre-line">{{ $customer->address }}</span>
                                <div>{{ collect([$customer->area, $customer->city, $customer->postal_code])->filter()->implode(', ') }}</div>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>
            </section>

            @if ($customer->notes)
                <section class="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 class="text-sm font-semibold text-gray-900">Internal notes</h2>
                    <p class="mt-2 text-sm whitespace-pre-line text-gray-700">{{ $customer->notes }}</p>
                </section>
            @endif

            {{-- Status --}}
            @can('changeStatus', $customer)
                <section class="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 class="text-sm font-semibold text-gray-900">Status</h2>
                    @error('status')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @if ($customer->status === App\Enums\CustomerStatus::Blocked)
                        <p class="mt-1 text-sm text-gray-600">This customer is blocked and will not be able to place new orders.</p>
                        <form method="POST" action="{{ route('admin.customers.status', $customer) }}" class="mt-4">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ App\Enums\CustomerStatus::Active->value }}">
                            <x-admin.button variant="secondary">Unblock customer</x-admin.button>
                        </form>
                    @else
                        <p class="mt-1 text-sm text-gray-600">Block a customer to stop new orders (e.g. repeated fake COD orders). Existing orders are not affected.</p>
                        <form method="POST" action="{{ route('admin.customers.status', $customer) }}" class="mt-4"
                            onsubmit="return confirm('Block this customer?')">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ App\Enums\CustomerStatus::Blocked->value }}">
                            <x-admin.button variant="danger">Block customer</x-admin.button>
                        </form>
                    @endif
                </section>
            @endcan

            {{-- Delete --}}
            @can('delete', $customer)
                <section class="rounded-lg border border-red-200 bg-white p-6">
                    <h2 class="text-sm font-semibold text-red-700">Delete customer</h2>
                    @if ($summary['orders_count'] > 0)
                        <p class="mt-1 text-sm text-gray-600">This customer has orders, so the record must be kept for order history.</p>
                    @else
                        <p class="mt-1 text-sm text-gray-600">This customer has no orders. Deleting is permanent (e.g. for duplicate or test records).</p>
                        <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}" class="mt-4"
                            onsubmit="return confirm('Delete this customer permanently?')">
                            @csrf
                            @method('DELETE')
                            <x-admin.button variant="danger">Delete customer</x-admin.button>
                        </form>
                    @endif
                </section>
            @endcan
        </div>
    </div>
</x-layouts::admin>
