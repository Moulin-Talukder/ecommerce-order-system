<x-layouts::admin :title="'Edit order '.$order->order_number">
    <x-admin.page-header :title="'Edit order '.$order->order_number"
        description="Items, prices and totals cannot be changed after an order is placed. Cancel the order and place a new one if the items are wrong." />

    <form method="POST" action="{{ route('admin.orders.update', $order) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @if ($order->status->allowsDeliveryChanges())
            <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
                <h2 class="text-sm font-semibold text-gray-900 sm:col-span-2">Customer & delivery (this order only)</h2>
                <x-form.input name="customer_name" label="Recipient name" :value="$order->customer_name" required maxlength="150" />
                <x-form.input name="customer_phone" label="Phone" type="tel" :value="$order->customer_phone" required maxlength="30" class="font-mono" />
                <x-form.input name="customer_alternate_phone" label="Alternate phone" type="tel" :value="$order->customer_alternate_phone" maxlength="30" class="font-mono" />
                <x-form.input name="customer_email" label="Email" type="email" :value="$order->customer_email" maxlength="255" />
                <div class="sm:col-span-2">
                    <x-form.textarea name="shipping_address" label="Delivery address" :value="$order->shipping_address" rows="2" required maxlength="500" />
                </div>
                <div class="grid gap-6 sm:col-span-2 sm:grid-cols-3">
                    <x-form.input name="shipping_area" label="Area / Thana" :value="$order->shipping_area" maxlength="100" />
                    <x-form.input name="shipping_city" label="City / District" :value="$order->shipping_city" maxlength="100" />
                    <x-form.input name="shipping_postal_code" label="Postal code" :value="$order->shipping_postal_code" maxlength="20" />
                </div>
                <div class="sm:col-span-2">
                    <x-form.textarea name="customer_note" label="Customer note" :value="$order->customer_note" rows="2" />
                </div>
            </section>
        @else
            <p class="rounded-md border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
                This order is {{ strtolower($order->status->label()) }}, so its delivery details are locked. Only the internal note can be changed.
            </p>
        @endif

        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <x-form.textarea name="admin_note" label="Internal note" :value="$order->admin_note" rows="3" hint="Visible to staff only." />
        </section>

        <div class="flex items-center gap-3">
            <x-admin.button>Save changes</x-admin.button>
            <x-admin.button variant="secondary" :href="route('admin.orders.show', $order)">Cancel</x-admin.button>
        </div>
    </form>
</x-layouts::admin>
