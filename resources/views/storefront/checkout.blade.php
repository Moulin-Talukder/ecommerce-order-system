<x-layouts::storefront title="Checkout">
    <h1 class="mb-4 text-2xl font-semibold">Checkout</h1>

    @php($defaultZone = old('delivery_zone', 'inside_dhaka'))
    <form method="POST" action="{{ route('storefront.checkout.store') }}" class="grid gap-6 lg:grid-cols-3"
        x-data="{ zone: @js($defaultZone), submitting: false }" x-on:submit="submitting = true">
        @csrf
        <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">
        {{-- Honeypot: humans never see or fill this field. --}}
        <div class="hidden" aria-hidden="true">
            <label for="website">Leave this field empty</label>
            <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <div class="space-y-6 lg:col-span-2">
            <section class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-2">
                <h2 class="font-semibold sm:col-span-2">Your details</h2>
                <x-form.input name="name" label="Full name" required maxlength="150" autocomplete="name" />
                <x-form.input name="phone" label="Mobile number" type="tel" required maxlength="30" autocomplete="tel" placeholder="01XXXXXXXXX" />
                <x-form.input name="alternate_phone" label="Alternate mobile (optional)" type="tel" maxlength="30" />
                <x-form.input name="email" label="Email (optional)" type="email" maxlength="255" autocomplete="email" />
            </section>

            <section class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-2">
                <h2 class="font-semibold sm:col-span-2">Delivery address</h2>
                <div class="sm:col-span-2">
                    <x-form.textarea name="address" label="Address (house, road, block)" required maxlength="500" rows="2" />
                </div>
                <x-form.input name="area" label="Area / Thana" required maxlength="100" />
                <x-form.input name="city" label="City / District" required maxlength="100" />

                <fieldset class="sm:col-span-2">
                    <legend class="text-sm font-medium text-gray-700">Delivery zone</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($zones as $key => $zone)
                            <label class="flex cursor-pointer items-center justify-between rounded-md border border-gray-300 px-3 py-2 text-sm has-[:checked]:border-gray-900 has-[:checked]:ring-1 has-[:checked]:ring-gray-900">
                                <span class="flex items-center gap-2">
                                    <input type="radio" name="delivery_zone" value="{{ $key }}" x-model="zone" @checked($defaultZone === $key) required>
                                    {{ $zone['label'] }}
                                </span>
                                <span class="tabular-nums">{{ App\Support\Money::format($zone['charge']) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('delivery_zone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </fieldset>

                <div class="sm:col-span-2">
                    <x-form.textarea name="note" label="Note for delivery (optional)" maxlength="500" rows="2" />
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="font-semibold">Payment</h2>
                <p class="mt-2 flex items-center gap-2 text-sm"><input type="radio" checked disabled> Cash on Delivery</p>
                <p class="mt-1 text-xs text-gray-500">Pay the delivery person when you receive your order.</p>
            </section>
        </div>

        <aside class="h-fit space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            <h2 class="font-semibold">Order summary</h2>
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($summary->lines as $line)
                    <li class="flex justify-between gap-2 py-2">
                        <span>{{ $line->product->name }} × {{ $line->quantity }}</span>
                        <span class="tabular-nums">{{ App\Support\Money::format($line->pricing->lineTotal) }}</span>
                    </li>
                @endforeach
            </ul>
            {{-- All amounts below are calculated by the server for each zone; the page only shows the selected one. --}}
            @foreach ($zoneTotals as $key => $totals)
                <dl class="space-y-1 border-t border-gray-100 pt-3 text-sm" x-show="zone === @js($key)" @if ($key !== $defaultZone) x-cloak @endif>
                    <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular-nums">{{ App\Support\Money::format($totals->subtotal) }}</dd></div>
                    <div class="flex justify-between"><dt>Delivery ({{ $zones[$key]['label'] }})</dt><dd class="tabular-nums">{{ App\Support\Money::format($totals->deliveryCharge) }}</dd></div>
                    <div class="flex justify-between text-base font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ App\Support\Money::format($totals->total) }}</dd></div>
                </dl>
            @endforeach
            <button type="submit" x-bind:disabled="submitting"
                class="w-full rounded-md bg-gray-900 px-4 py-3 text-sm font-semibold text-white hover:bg-gray-800 disabled:opacity-60">
                <span x-show="! submitting">Place order (Cash on Delivery)</span>
                <span x-show="submitting" x-cloak>Placing order…</span>
            </button>
            <a href="{{ route('storefront.cart') }}" class="block text-center text-sm text-gray-500 hover:underline">Back to cart</a>
        </aside>
    </form>
</x-layouts::storefront>
