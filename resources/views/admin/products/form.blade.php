{{-- Shared create/edit form. Expects $product, $categoryOptions, $brandOptions, $action, $method, $submitLabel. --}}
@php($isNew = ! $product->exists)

<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @method($method)

    <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
        <h2 class="text-sm font-semibold text-gray-900 sm:col-span-2">Basic information</h2>

        <div class="sm:col-span-2">
            <x-form.input name="name" label="Name" :value="$product->name" required maxlength="255" autofocus />
        </div>

        <x-form.input name="sku" label="SKU" :value="$product->sku" required maxlength="64" class="font-mono"
            hint="Unique stock-keeping code, e.g. TSHIRT-BLU-M." />

        <x-form.input name="slug" label="Slug" :value="$product->slug" maxlength="255"
            hint="Used in the product URL. Leave blank to generate it from the name." />

        <x-form.select name="category_id" label="Category" :options="$categoryOptions" :value="$product->category_id"
            placeholder="— Select a category —" required />

        <x-form.select name="brand_id" label="Brand" :options="$brandOptions" :value="$product->brand_id"
            placeholder="— No brand —" />

        <div class="sm:col-span-2">
            <x-form.textarea name="short_description" label="Short description" :value="$product->short_description" rows="2" maxlength="500"
                hint="One or two sentences shown in product listings." />
        </div>

        <div class="sm:col-span-2">
            <x-form.textarea name="description" label="Description" :value="$product->description" rows="6" />
        </div>
    </section>

    <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-3">
        <div class="sm:col-span-3">
            <h2 class="text-sm font-semibold text-gray-900">Pricing (BDT)</h2>
            <p class="mt-1 text-xs text-gray-500">Customers pay the discount price when set; it must be lower than the selling price.</p>
        </div>

        <x-form.input name="selling_price" label="Selling price" type="number" step="0.01" min="0" :value="$product->selling_price" required />
        <x-form.input name="discount_price" label="Discount price" type="number" step="0.01" min="0" :value="$product->discount_price"
            hint="Optional sale price." />
        <x-form.input name="purchase_price" label="Purchase (cost) price" type="number" step="0.01" min="0" :value="$product->purchase_price"
            hint="Internal only; used for profit reports." />
    </section>

    <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-3">
        <h2 class="text-sm font-semibold text-gray-900 sm:col-span-3">Inventory</h2>

        {{-- Errors for stock fields this form never submits (tampered requests). --}}
        @foreach ($isNew ? ['stock_quantity', 'stock_status'] : ['stock_quantity', 'stock_status', 'opening_stock'] as $field)
            @error($field)
                <p class="text-sm text-red-600 sm:col-span-3">{{ $message }}</p>
            @enderror
        @endforeach

        <div class="sm:col-span-3">
            <x-form.checkbox name="manage_stock" label="Track stock quantity" :checked="$product->manage_stock"
                hint="Untick for made-to-order items; you then set availability manually on the product page." />
        </div>

        @if ($isNew)
            <x-form.input name="opening_stock" label="Opening stock" type="number" min="0" step="1" :value="0"
                hint="Recorded as the first stock movement. Ignored when stock is not tracked." />
        @else
            <div>
                <span class="block text-sm font-medium text-gray-700">Current stock</span>
                <p class="mt-2 text-sm text-gray-900">
                    {{ $product->manage_stock ? $product->stock_quantity.' units' : $product->stock_status->label() }}
                </p>
                <p class="mt-1 text-xs text-gray-500">Change it with a stock adjustment on the <a href="{{ route('admin.products.show', $product) }}" class="underline">product page</a>.</p>
            </div>
        @endif

        <x-form.input name="low_stock_threshold" label="Low-stock alert at" type="number" min="0" step="1" :value="$product->low_stock_threshold"
            hint="Optional. Flag the product when stock falls to this level." />
    </section>

    <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-4">
        <h2 class="text-sm font-semibold text-gray-900 sm:col-span-4">Shipping</h2>

        <x-form.input name="weight" label="Weight (kg)" type="number" step="0.001" min="0" :value="$product->weight" />
        <x-form.input name="length" label="Length (cm)" type="number" step="0.01" min="0" :value="$product->length" />
        <x-form.input name="width" label="Width (cm)" type="number" step="0.01" min="0" :value="$product->width" />
        <x-form.input name="height" label="Height (cm)" type="number" step="0.01" min="0" :value="$product->height" />
    </section>

    <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
        <h2 class="text-sm font-semibold text-gray-900 sm:col-span-2">Visibility</h2>

        <x-form.select name="status" label="Status"
            :options="collect(App\Enums\ProductStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
            :value="$product->status?->value"
            hint="Only active products are shown on the storefront." />

        <div class="pt-6">
            <x-form.checkbox name="is_featured" label="Featured product" :checked="$product->is_featured" />
        </div>
    </section>

    <section class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-gray-900">Search engine listing</h2>
        <x-form.input name="meta_title" label="Meta title" :value="$product->meta_title" maxlength="255" />
        <x-form.textarea name="meta_description" label="Meta description" :value="$product->meta_description" rows="2" maxlength="500" />
    </section>

    <div class="flex items-center gap-3">
        <x-admin.button>{{ $submitLabel }}</x-admin.button>
        <x-admin.button variant="secondary" :href="$isNew ? route('admin.products.index') : route('admin.products.show', $product)">Cancel</x-admin.button>
    </div>
</form>
