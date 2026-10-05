@php
    $money = fn ($amount) => App\Support\Money::format($amount, $order->currency);
    $due = bcsub($order->total, $ledger['net'], 2);
    $due = bccomp($due, '0', 2) > 0 ? $due : '0.00';
@endphp

<x-layouts::admin :title="'Order '.$order->order_number">
    <x-admin.page-header :title="'Order '.$order->order_number"
        :description="'Placed '.$order->created_at->format('d M Y, h:i A').' · '.$order->source->label().($order->creator ? ' · by '.$order->creator->name : '')">
        <x-slot:actions>
            <x-admin.order-status-badge :status="$order->status" />
            <x-admin.payment-status-badge :status="$order->payment_status" />
            @can('update', $order)
                <x-admin.button variant="secondary" :href="route('admin.orders.edit', $order)">Edit details</x-admin.button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Items & totals (snapshots taken at purchase time) --}}
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <h2 class="border-b border-gray-100 px-6 py-4 text-sm font-semibold text-gray-900">Items</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <tr>
                                <th scope="col" class="px-6 py-3">Product</th>
                                <th scope="col" class="px-6 py-3 text-right">Unit price</th>
                                <th scope="col" class="px-6 py-3 text-right">Qty</th>
                                <th scope="col" class="px-6 py-3 text-right">Discount</th>
                                <th scope="col" class="px-6 py-3 text-right">Line total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($order->items as $item)
                                <tr>
                                    <td class="px-6 py-3">
                                        @if ($item->product)
                                            <a href="{{ route('admin.products.show', $item->product) }}" class="font-medium text-gray-900 hover:underline">{{ $item->product_name }}</a>
                                        @else
                                            <span class="font-medium text-gray-900">{{ $item->product_name }}</span>
                                        @endif
                                        <div class="font-mono text-xs text-gray-500">{{ $item->sku }}</div>
                                    </td>
                                    <td class="px-6 py-3 text-right tabular-nums">{{ $money($item->unit_price) }}</td>
                                    <td class="px-6 py-3 text-right tabular-nums">{{ $item->quantity }}</td>
                                    <td class="px-6 py-3 text-right tabular-nums text-gray-600">{{ bccomp($item->discount_amount, '0', 2) > 0 ? '−'.$money($item->discount_amount) : '—' }}</td>
                                    <td class="px-6 py-3 text-right tabular-nums font-medium">{{ $money($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <dl class="space-y-1 border-t border-gray-100 px-6 py-4 text-sm">
                    <div class="flex justify-between text-gray-600"><dt>Items at regular price</dt><dd class="tabular-nums">{{ $money($itemsGross) }}</dd></div>
                    @if (bccomp($itemDiscounts, '0', 2) > 0)
                        <div class="flex justify-between text-gray-600"><dt>Product discounts</dt><dd class="tabular-nums">−{{ $money($itemDiscounts) }}</dd></div>
                    @endif
                    <div class="flex justify-between text-gray-900"><dt>Subtotal</dt><dd class="tabular-nums">{{ $money($order->subtotal) }}</dd></div>
                    @if (bccomp($order->discount_total, '0', 2) > 0)
                        <div class="flex justify-between text-gray-600"><dt>Order discount</dt><dd class="tabular-nums">−{{ $money($order->discount_total) }}</dd></div>
                    @endif
                    <div class="flex justify-between text-gray-600"><dt>Delivery charge</dt><dd class="tabular-nums">{{ $money($order->delivery_charge) }}</dd></div>
                    <div class="flex justify-between border-t border-gray-100 pt-2 text-base font-semibold text-gray-900"><dt>Total</dt><dd class="tabular-nums">{{ $money($order->total) }}</dd></div>
                </dl>
            </section>

            {{-- Customer & delivery --}}
            <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Customer</h2>
                    <p class="mt-2 text-sm text-gray-900">{{ $order->customer_name }}</p>
                    <p class="font-mono text-sm text-gray-700">{{ $order->customer_phone }}</p>
                    @if ($order->customer_alternate_phone)
                        <p class="font-mono text-sm text-gray-500">{{ $order->customer_alternate_phone }} (alt)</p>
                    @endif
                    @if ($order->customer_email)
                        <p class="text-sm text-gray-700">{{ $order->customer_email }}</p>
                    @endif
                    <a href="{{ route('admin.customers.show', $order->customer) }}" class="mt-2 inline-block text-xs text-gray-600 underline hover:text-gray-900">View customer record</a>
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Delivery address</h2>
                    <p class="mt-2 text-sm whitespace-pre-line text-gray-900">{{ $order->shipping_address }}</p>
                    <p class="text-sm text-gray-700">{{ collect([$order->shipping_area, $order->shipping_city, $order->shipping_postal_code])->filter()->implode(', ') }}</p>
                </div>
                @if ($order->customer_note || $order->admin_note)
                    <div class="grid gap-4 sm:col-span-2 sm:grid-cols-2">
                        @if ($order->customer_note)
                            <div><h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Customer note</h3><p class="mt-1 text-sm whitespace-pre-line text-gray-700">{{ $order->customer_note }}</p></div>
                        @endif
                        @if ($order->admin_note)
                            <div><h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Internal note</h3><p class="mt-1 text-sm whitespace-pre-line text-gray-700">{{ $order->admin_note }}</p></div>
                        @endif
                    </div>
                @endif
            </section>

            {{-- Customer history: helps judge COD risk before confirming --}}
            <section class="rounded-lg border border-gray-200 bg-white p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-900">Customer history</h2>
                    @if ($customerSummary['orders_count'] > 1)
                        <a href="{{ route('admin.orders.index', ['customer' => $order->customer_id]) }}" class="text-xs text-gray-600 underline hover:text-gray-900">All {{ $customerSummary['orders_count'] }} orders from this customer</a>
                    @endif
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-gray-500">Orders</dt><dd class="tabular-nums text-gray-900">{{ $customerSummary['orders_count'] }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Delivered</dt><dd class="tabular-nums text-gray-900">{{ $customerSummary['completed_orders_count'] }}</dd></div>
                    <div>
                        <dt class="text-xs text-gray-500">Cancelled / returned</dt>
                        <dd @class(['tabular-nums', 'font-semibold text-red-700' => $customerSummary['cancelled_or_returned_count'] > 0, 'text-gray-900' => $customerSummary['cancelled_or_returned_count'] === 0])>{{ $customerSummary['cancelled_or_returned_count'] }}</dd>
                    </div>
                    <div><dt class="text-xs text-gray-500">Total spent</dt><dd class="tabular-nums text-gray-900">{{ App\Support\Money::format($customerSummary['total_spent']) }}</dd></div>
                </dl>
                @if ($order->customer->status === App\Enums\CustomerStatus::Blocked)
                    <p class="mt-3 text-sm font-medium text-red-700">This customer is now blocked from placing new orders.</p>
                @endif
                @if ($otherOrders->isNotEmpty())
                    <ul class="mt-4 divide-y divide-gray-100 border-t border-gray-100 text-sm">
                        @foreach ($otherOrders as $other)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <a href="{{ route('admin.orders.show', $other) }}" class="font-mono text-xs text-gray-900 hover:underline">{{ $other->order_number }}</a>
                                <span class="text-xs text-gray-500">{{ $other->created_at->format('d M Y') }}</span>
                                <span class="tabular-nums text-gray-700">{{ App\Support\Money::format($other->total, $other->currency) }}</span>
                                <x-admin.order-status-badge :status="$other->status" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 text-sm text-gray-500">This is the customer's first order.</p>
                @endif
            </section>

            {{-- Timeline: status changes and payments in order --}}
            <section class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-semibold text-gray-900">Timeline</h2>
                <x-admin.order-timeline :entries="$timeline" />
            </section>

            {{-- Stock movements caused by this order --}}
            @if ($order->stockMovements->isNotEmpty())
                <section class="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 class="text-sm font-semibold text-gray-900">Stock movements</h2>
                    <ul class="mt-3 divide-y divide-gray-100 text-sm">
                        @foreach ($order->stockMovements as $movement)
                            <li class="flex justify-between gap-4 py-2">
                                <span class="text-gray-900">{{ $movement->type->label() }} · {{ $movement->product?->name ?? 'Deleted product' }}</span>
                                <span @class(['tabular-nums', 'text-red-700' => $movement->quantity_change < 0, 'text-green-700' => $movement->quantity_change > 0])>
                                    {{ $movement->quantity_change > 0 ? '+' : '' }}{{ $movement->quantity_change }} → {{ $movement->quantity_after }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <div class="space-y-6">
            {{-- Status actions --}}
            @can('changeStatus', $order)
                <section class="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 class="text-sm font-semibold text-gray-900">Update status</h2>
                    @foreach ($errors->getBag('status')->all() as $message)
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @endforeach

                    @if ($order->status->isFinal())
                        <p class="mt-1 text-sm text-gray-600">This order is {{ strtolower($order->status->label()) }}; no further status changes are possible.</p>
                    @else
                        <div class="mt-4 space-y-3">
                            @foreach ($order->status->transitions() as $next)
                                @if ($next->restoresStock())
                                    <form method="POST" action="{{ route('admin.orders.status', $order) }}" class="space-y-2 rounded-md border border-red-100 p-3"
                                        onsubmit="return confirm('{{ $next->actionLabel() }}? Stock will be returned to inventory.')">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $next->value }}">
                                        <label for="note-{{ $next->value }}" class="sr-only">Reason</label>
                                        <input id="note-{{ $next->value }}" name="note" required maxlength="1000" placeholder="Reason (required)"
                                            class="block w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
                                        <x-admin.button variant="danger" class="w-full">{{ $next->actionLabel() }}</x-admin.button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.orders.status', $order) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $next->value }}">
                                        <x-admin.button class="w-full">{{ $next->actionLabel() }}</x-admin.button>
                                    </form>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </section>
            @endcan

            {{-- Payments --}}
            <section class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="text-sm font-semibold text-gray-900">Payment</h2>
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Method</dt><dd>{{ $order->payment_method->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Status</dt><dd><x-admin.payment-status-badge :status="$order->payment_status" /></dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Order total</dt><dd class="tabular-nums">{{ $money($order->total) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Received</dt><dd class="tabular-nums">{{ $money($ledger['paid']) }}</dd></div>
                    @if (bccomp($ledger['refunded'], '0', 2) > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">Refunded</dt><dd class="tabular-nums">−{{ $money($ledger['refunded']) }}</dd></div>
                    @endif
                    <div class="flex justify-between font-medium"><dt>Amount due</dt><dd class="tabular-nums">{{ $money($due) }}</dd></div>
                </dl>

                @can('recordPayment', $order)
                    @unless (in_array($order->status, App\Enums\OrderStatus::nonRevenue(), true) || bccomp($due, '0', 2) <= 0)
                        <form method="POST" action="{{ route('admin.orders.payments.store', $order) }}" class="mt-4 space-y-3 border-t border-gray-100 pt-4">
                            @csrf
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Record payment</h3>
                            <x-form.input bag="payment" name="amount" label="Amount" type="number" step="0.01" min="0.01" :value="$due" required />
                            <x-form.select bag="payment" name="method" label="Method" :value="$order->payment_method->value"
                                :options="collect(App\Enums\PaymentMethod::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                            <x-form.select bag="payment" name="status" label="Result" value="completed"
                                :options="['completed' => 'Received', 'failed' => 'Failed attempt']" />
                            <x-form.input bag="payment" name="transaction_reference" label="Reference" maxlength="100" hint="Bank/transaction reference or COD receipt number." />
                            <x-form.input bag="payment" name="note" label="Note" maxlength="500" />
                            <x-admin.button>Record payment</x-admin.button>
                        </form>
                    @endunless
                @endcan

                @can('refund', $order)
                    @if (bccomp($ledger['net'], '0', 2) > 0)
                        <details class="mt-4 border-t border-gray-100 pt-4" @if ($errors->getBag('refund')->any()) open @endif>
                            <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wider text-gray-500">Record refund</summary>
                            <form method="POST" action="{{ route('admin.orders.refunds.store', $order) }}" class="mt-3 space-y-3">
                                @csrf
                                <x-form.input bag="refund" id="refund_amount" name="amount" label="Refund amount" type="number" step="0.01" min="0.01" :value="$ledger['net']" required />
                                <x-form.select bag="refund" id="refund_method" name="method" label="Method" :value="$order->payment_method->value"
                                    :options="collect(App\Enums\PaymentMethod::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                                <x-form.input bag="refund" id="refund_transaction_reference" name="transaction_reference" label="Reference" maxlength="100" />
                                <x-form.input bag="refund" id="refund_note" name="note" label="Reason" maxlength="500" required />
                                <x-admin.button variant="danger">Record refund</x-admin.button>
                            </form>
                        </details>
                    @endif
                @endcan

                <h3 class="mt-6 text-xs font-semibold uppercase tracking-wider text-gray-500">Payment history</h3>
                @if ($order->payments->isEmpty())
                    <p class="mt-2 text-sm text-gray-500">No payments recorded.</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach ($order->payments as $payment)
                            <li class="py-2">
                                <div class="flex justify-between gap-2">
                                    <span class="text-gray-900">{{ $payment->type->label() }} · {{ $payment->method->label() }}</span>
                                    <span @class(['tabular-nums font-medium', 'text-green-700' => $payment->type === App\Enums\PaymentType::Payment && $payment->status === App\Enums\PaymentTransactionStatus::Completed, 'text-red-700' => $payment->type === App\Enums\PaymentType::Refund, 'text-gray-400 line-through' => $payment->status === App\Enums\PaymentTransactionStatus::Failed])>
                                        {{ $payment->type === App\Enums\PaymentType::Refund ? '−' : '' }}{{ $money($payment->amount) }}
                                    </span>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $payment->status->label() }} · {{ $payment->created_at->format('d M Y, h:i A') }} · {{ $payment->recordedBy?->name ?? 'System' }}
                                    @if ($payment->transaction_reference) · Ref {{ $payment->transaction_reference }} @endif
                                    @if ($payment->note) · {{ $payment->note }} @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-layouts::admin>
