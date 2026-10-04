<x-layouts::admin title="New order">
    <x-admin.page-header title="New order"
        description="Prices, discounts, totals and stock are calculated by the server when the order is placed." />

    @php
        $productOptions = $products->mapWithKeys(function ($product) {
            $available = $product->manage_stock ? $product->stock_quantity > 0 : $product->stock_status === App\Enums\StockStatus::InStock;
            $stock = $product->manage_stock ? "{$product->stock_quantity} in stock" : $product->stock_status->label();

            return [$product->id => [
                'label' => "{$product->name} ({$product->sku}) — ".App\Support\Money::format($product->effectivePrice())." — {$stock}",
                'available' => $available,
            ]];
        });
        $rows = array_values(old('items', [['product_id' => '', 'quantity' => 1]]));
        $itemErrors = collect($errors->getMessages())
            ->filter(fn ($messages, $key) => Str::startsWith($key, 'items'))
            ->flatMap(fn ($messages, $key) => array_map(
                fn ($message) => preg_match('/^items\.(\d+)\./', $key, $match) ? 'Item '.($match[1] + 1).': '.$message : $message,
                $messages,
            ));
    @endphp

    <form method="POST" action="{{ route('admin.orders.store') }}" class="space-y-6">
        @csrf

        <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <h2 class="text-sm font-semibold text-gray-900">Customer & delivery</h2>
                <p class="mt-1 text-xs text-gray-500">
                    The customer is matched by phone number. A new customer record is created only if no customer has this number.
                    @if ($customer)
                        Pre-filled from <a href="{{ route('admin.customers.show', $customer) }}" class="underline">{{ $customer->name }}</a>.
                    @endif
                </p>
            </div>

            <x-form.input name="customer_phone" label="Phone" type="tel" :value="$customer?->phone" required maxlength="30" class="font-mono" />
            <x-form.input name="customer_name" label="Recipient name" :value="$customer?->name" required maxlength="150" />
            <x-form.input name="customer_alternate_phone" label="Alternate phone" type="tel" :value="$customer?->alternate_phone" maxlength="30" class="font-mono" />
            <x-form.input name="customer_email" label="Email" type="email" :value="$customer?->email" maxlength="255" />

            <div class="sm:col-span-2">
                <x-form.textarea name="shipping_address" label="Delivery address" :value="$customer?->address" rows="2" required maxlength="500" />
            </div>
            <div class="grid gap-6 sm:col-span-2 sm:grid-cols-3">
                <x-form.input name="shipping_area" label="Area / Thana" :value="$customer?->area" maxlength="100" />
                <x-form.input name="shipping_city" label="City / District" :value="$customer?->city" maxlength="100" />
                <x-form.input name="shipping_postal_code" label="Postal code" :value="$customer?->postal_code" maxlength="20" />
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6" x-data="{ rows: @js($rows) }">
            <h2 class="text-sm font-semibold text-gray-900">Items</h2>
            <p class="mt-1 text-xs text-gray-500">Only active products are listed. Each product once; set the quantity.</p>

            <div class="mt-4 space-y-3">
                <template x-for="(row, index) in rows" :key="index">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <label class="block text-xs font-medium text-gray-600" :for="`item-product-${index}`" x-text="`Product ${index + 1}`"></label>
                            <select :id="`item-product-${index}`" :name="`items[${index}][product_id]`" x-model="row.product_id" required
                                class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
                                <option value="">— Select a product —</option>
                                @foreach ($productOptions as $id => $option)
                                    <option value="{{ $id }}" @disabled(! $option['available'])>{{ $option['label'] }}{{ $option['available'] ? '' : ' (unavailable)' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-28">
                            <label class="block text-xs font-medium text-gray-600" :for="`item-qty-${index}`">Quantity</label>
                            <input type="number" min="1" step="1" :id="`item-qty-${index}`" :name="`items[${index}][quantity]`" x-model="row.quantity" required
                                class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
                        </div>
                        <button type="button" class="mb-2 text-sm text-red-600 hover:text-red-800" x-show="rows.length > 1" x-on:click="rows.splice(index, 1)">Remove</button>
                    </div>
                </template>
            </div>

            <button type="button" class="mt-4 text-sm font-medium text-gray-700 hover:text-gray-900" x-on:click="rows.push({ product_id: '', quantity: 1 })">+ Add another product</button>

            @foreach ($itemErrors as $message)
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @endforeach
        </section>

        <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2 lg:grid-cols-4">
            <h2 class="text-sm font-semibold text-gray-900 sm:col-span-2 lg:col-span-4">Charges & payment</h2>

            <x-form.input name="delivery_charge" label="Delivery charge (BDT)" type="number" step="0.01" min="0" :value="$defaults['delivery_charge']" required />
            <x-form.input name="discount_total" label="Order discount (BDT)" type="number" step="0.01" min="0" :value="0"
                hint="Optional extra discount on the whole order. Product sale prices are applied automatically." />
            <x-form.select name="payment_method" label="Payment method" :value="$defaults['payment_method']"
                :options="collect(App\Enums\PaymentMethod::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
            <x-form.select name="source" label="Order source" :value="$defaults['source']"
                :options="collect(App\Enums\OrderSource::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
        </section>

        <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
            <x-form.textarea name="customer_note" label="Customer note" rows="2" hint="Delivery instructions from the customer." />
            <x-form.textarea name="admin_note" label="Internal note" rows="2" hint="Visible to staff only." />
        </section>

        <div class="flex items-center gap-3">
            <x-admin.button>Place order</x-admin.button>
            <x-admin.button variant="secondary" :href="route('admin.orders.index')">Cancel</x-admin.button>
        </div>
    </form>
</x-layouts::admin>
