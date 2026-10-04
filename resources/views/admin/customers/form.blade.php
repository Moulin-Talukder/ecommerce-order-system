{{-- Shared create/edit form. Expects $customer, $action, $method, $submitLabel. --}}
<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @method($method)

    <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
        <h2 class="text-sm font-semibold text-gray-900 sm:col-span-2">Contact</h2>

        <div class="sm:col-span-2">
            <x-form.input name="name" label="Name" :value="$customer->name" required maxlength="150" autofocus />
        </div>

        <x-form.input name="phone" label="Phone" type="tel" :value="$customer->phone" required maxlength="30" class="font-mono"
            hint="Bangladeshi mobile number. Any format is accepted (e.g. +880 1712-345678) and saved as 01712345678." />

        <x-form.input name="alternate_phone" label="Alternate phone" type="tel" :value="$customer->alternate_phone" maxlength="30" class="font-mono"
            hint="Optional. Given to the courier as a backup contact." />

        <div class="sm:col-span-2">
            <x-form.input name="email" label="Email" type="email" :value="$customer->email" maxlength="255" />
        </div>
    </section>

    <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-3">
        <h2 class="text-sm font-semibold text-gray-900 sm:col-span-3">Default delivery address</h2>

        <div class="sm:col-span-3">
            <x-form.textarea name="address" label="Address" :value="$customer->address" rows="2" maxlength="500"
                hint="House, road, block. Each order keeps its own copy of the address it was delivered to." />
        </div>

        <x-form.input name="area" label="Area / Thana" :value="$customer->area" maxlength="100" />
        <x-form.input name="city" label="City / District" :value="$customer->city" maxlength="100" />
        <x-form.input name="postal_code" label="Postal code" :value="$customer->postal_code" maxlength="20" />
    </section>

    <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6">
        <x-form.textarea name="notes" label="Internal notes" :value="$customer->notes" rows="3"
            hint="Visible to staff only." />
    </section>

    @error('status')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    <div class="flex items-center gap-3">
        <x-admin.button>{{ $submitLabel }}</x-admin.button>
        <x-admin.button variant="secondary" :href="$customer->exists ? route('admin.customers.show', $customer) : route('admin.customers.index')">Cancel</x-admin.button>
    </div>
</form>
