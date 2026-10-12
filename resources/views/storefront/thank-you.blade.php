<x-layouts::storefront title="Thank you">
    @php($money = fn ($amount) => App\Support\Money::format($amount, $order->currency))

    <div class="mx-auto max-w-2xl">
        <div class="rounded-lg border border-green-200 bg-green-50 p-6 text-center">
            <h1 class="text-2xl font-semibold text-green-900">Thank you! Your order has been placed.</h1>
            <p class="mt-2 text-green-900">Order number <strong class="font-mono">{{ $order->order_number }}</strong></p>
            <p class="mt-1 text-sm text-green-800">We will call you to confirm before delivery. Please keep your order number for reference.</p>
        </div>

        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-4">
            <h2 class="font-semibold">Order summary</h2>
            <ul class="mt-2 divide-y divide-gray-100 text-sm">
                @foreach ($order->items as $item)
                    <li class="flex justify-between gap-2 py-2">
                        <span>{{ $item->product_name }} × {{ $item->quantity }} <span class="text-gray-500">@ {{ $money(bcdiv($item->line_total, (string) $item->quantity, 2)) }}</span></span>
                        <span class="tabular-nums">{{ $money($item->line_total) }}</span>
                    </li>
                @endforeach
            </ul>
            <dl class="mt-3 space-y-1 border-t border-gray-100 pt-3 text-sm">
                <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular-nums">{{ $money($order->subtotal) }}</dd></div>
                <div class="flex justify-between"><dt>Delivery</dt><dd class="tabular-nums">{{ $money($order->delivery_charge) }}</dd></div>
                <div class="flex justify-between text-base font-semibold"><dt>Total to pay</dt><dd class="tabular-nums">{{ $money($order->total) }}</dd></div>
            </dl>
            <dl class="mt-4 grid gap-2 border-t border-gray-100 pt-3 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">Payment</dt><dd>{{ $order->payment_method->label() }}</dd></div>
                <div><dt class="text-gray-500">Order status</dt><dd>{{ $order->status->label() }}</dd></div>
                <div><dt class="text-gray-500">Contact number</dt><dd class="font-mono">{{ App\Support\PhoneNumber::mask($order->customer_phone) }}</dd></div>
                <div><dt class="text-gray-500">Delivering to</dt><dd>{{ collect([$order->shipping_area, $order->shipping_city])->filter()->implode(', ') }}</dd></div>
            </dl>
        </section>

        <p class="mt-6 text-center"><a href="{{ route('storefront.home') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white">Continue shopping</a></p>
    </div>
</x-layouts::storefront>
