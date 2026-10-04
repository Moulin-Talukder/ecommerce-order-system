<x-layouts::admin :title="$product->name">
    <x-admin.page-header :title="$product->name" :description="'SKU '.$product->sku.' · '.$categoryPath">
        <x-slot:actions>
            @can('update', $product)
                <x-admin.button :href="route('admin.products.edit', $product)">Edit</x-admin.button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Overview --}}
            <section class="rounded-lg border border-gray-200 bg-white p-6">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <x-admin.product-status-badge :status="$product->status" />
                    <x-admin.stock-badge :product="$product" />
                    @if ($product->is_featured)
                        <x-admin.badge tone="blue">★ Featured</x-admin.badge>
                    @endif
                </div>

                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-gray-500">Price</dt><dd class="mt-1"><x-admin.price :product="$product" /></dd></div>
                    <div><dt class="text-gray-500">Purchase price</dt><dd class="mt-1 text-gray-900">{{ App\Support\Money::format($product->purchase_price) }}</dd></div>
                    <div><dt class="text-gray-500">Brand</dt><dd class="mt-1 text-gray-900">{{ $product->brand?->name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Slug</dt><dd class="mt-1 font-mono text-xs text-gray-900">{{ $product->slug }}</dd></div>
                    <div><dt class="text-gray-500">Weight</dt><dd class="mt-1 text-gray-900">{{ $product->weight !== null ? $product->weight.' kg' : '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500">Dimensions (L × W × H)</dt>
                        <dd class="mt-1 text-gray-900">
                            @if ($product->length || $product->width || $product->height)
                                {{ $product->length ?? '–' }} × {{ $product->width ?? '–' }} × {{ $product->height ?? '–' }} cm
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($product->short_description)
                    <p class="mt-6 text-sm text-gray-700">{{ $product->short_description }}</p>
                @endif
                @if ($product->description)
                    <div class="mt-4 text-sm whitespace-pre-line text-gray-700">{{ $product->description }}</div>
                @endif
            </section>

            {{-- Images --}}
            <section class="rounded-lg border border-gray-200 bg-white p-6" id="images">
                <div class="mb-4 flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">Images</h2>
                    <span class="text-xs text-gray-500">{{ $product->images->count() }} / {{ config('media.products.max_images') }} · the first image is the primary image</span>
                </div>

                @if ($product->images->isEmpty())
                    <p class="text-sm text-gray-500">No images yet.</p>
                @else
                    <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($product->images as $image)
                            <li class="overflow-hidden rounded-md border border-gray-200">
                                <div class="relative aspect-square bg-gray-50">
                                    <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?? $product->name }}" class="size-full object-contain" loading="lazy">
                                    @if ($loop->first)
                                        <x-admin.badge tone="blue" class="absolute top-2 left-2">Primary</x-admin.badge>
                                    @endif
                                </div>

                                @can('update', $product)
                                    <div class="space-y-2 p-3">
                                        <form method="POST" action="{{ route('admin.products.images.update', [$product, $image]) }}" class="flex gap-2">
                                            @csrf
                                            @method('PATCH')
                                            <label for="alt-{{ $image->id }}" class="sr-only">Alt text</label>
                                            <input id="alt-{{ $image->id }}" name="alt_text" value="{{ $image->alt_text }}" maxlength="255" placeholder="Alt text (describe the image)"
                                                class="block w-full min-w-0 rounded-md border border-gray-300 px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-gray-900">
                                            <button type="submit" class="text-xs font-medium text-gray-700 hover:text-gray-900">Save</button>
                                        </form>

                                        <div class="flex flex-wrap items-center gap-3 text-xs">
                                            @unless ($loop->first)
                                                <form method="POST" action="{{ route('admin.products.images.move', [$product, $image, 'first']) }}">
                                                    @csrf @method('PATCH')
                                                    <button type="submit" class="text-gray-600 hover:text-gray-900">Make primary</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.products.images.move', [$product, $image, 'up']) }}">
                                                    @csrf @method('PATCH')
                                                    <button type="submit" class="text-gray-600 hover:text-gray-900" aria-label="Move earlier">↑</button>
                                                </form>
                                            @endunless
                                            @unless ($loop->last)
                                                <form method="POST" action="{{ route('admin.products.images.move', [$product, $image, 'down']) }}">
                                                    @csrf @method('PATCH')
                                                    <button type="submit" class="text-gray-600 hover:text-gray-900" aria-label="Move later">↓</button>
                                                </form>
                                            @endunless
                                            <form method="POST" action="{{ route('admin.products.images.destroy', [$product, $image]) }}" class="ml-auto"
                                                onsubmit="return confirm('Delete this image?')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-800">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif

                @can('update', $product)
                    @if ($product->images->count() < config('media.products.max_images'))
                        <form method="POST" action="{{ route('admin.products.images.store', $product) }}" enctype="multipart/form-data" class="mt-6 border-t border-gray-100 pt-4">
                            @csrf
                            <label for="images" class="block text-sm font-medium text-gray-700">Upload images</label>
                            <input id="images" name="images[]" type="file" multiple accept="image/jpeg,image/png,image/webp" required
                                class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-gray-200">
                            <p class="mt-1 text-xs text-gray-500">
                                JPEG, PNG or WebP · up to {{ config('media.images.max_kb') / 1024 }} MB each · up to {{ config('media.products.max_files_per_request') }} at a time.
                                Images are re-processed for safety and resized to at most {{ config('media.images.resize_to') }}px.
                            </p>
                            @foreach (collect($errors->get('images'))->merge(collect($errors->get('images.*'))->flatten())->unique() as $message)
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @endforeach
                            <x-admin.button class="mt-3">Upload</x-admin.button>
                        </form>
                    @endif
                @endcan
            </section>
        </div>

        <div class="space-y-6">
            {{-- Status --}}
            @can('update', $product)
                <section class="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 class="text-sm font-semibold text-gray-900">Status</h2>
                    <p class="mt-1 text-sm text-gray-600">Currently <strong>{{ $product->status->label() }}</strong>.</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach (['active' => 'Publish (active)', 'draft' => 'Move to draft', 'archived' => 'Archive'] as $value => $label)
                            @continue($product->status->value === $value)
                            <form method="POST" action="{{ route('admin.products.status', $product) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ $value }}">
                                <x-admin.button variant="secondary">{{ $label }}</x-admin.button>
                            </form>
                        @endforeach
                    </div>
                </section>
            @endcan

            {{-- Stock --}}
            <section class="rounded-lg border border-gray-200 bg-white p-6" id="stock">
                <h2 class="text-sm font-semibold text-gray-900">Stock</h2>

                @if ($product->manage_stock)
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-900">{{ $product->stock_quantity }}</p>
                    <p class="text-xs text-gray-500">
                        units on hand{{ $product->low_stock_threshold !== null ? ' · low-stock alert at '.$product->low_stock_threshold : '' }}
                    </p>

                    @can('adjustStock', $product)
                        <form method="POST" action="{{ route('admin.products.stock.adjust', $product) }}" class="mt-4 space-y-3 border-t border-gray-100 pt-4">
                            @csrf
                            <x-form.select name="type" label="Adjustment type" :value="old('type', 'restock')"
                                :options="['restock' => 'Restock (add units)', 'adjustment' => 'Correction (+ or −)']" />
                            <x-form.input name="quantity_change" label="Quantity change" type="number" step="1" required
                                hint="Positive adds stock, negative removes it (corrections only)." />
                            <x-form.input name="note" label="Note" maxlength="500" hint="Required for corrections, e.g. “damaged in storage”." />
                            <x-admin.button>Record stock change</x-admin.button>
                        </form>
                    @endcan
                @else
                    <p class="mt-2 text-sm text-gray-700">Quantity is not tracked. Availability: <strong>{{ $product->stock_status->label() }}</strong>.</p>

                    @can('adjustStock', $product)
                        <form method="POST" action="{{ route('admin.products.stock.availability', $product) }}" class="mt-4 flex items-end gap-2">
                            @csrf
                            @method('PATCH')
                            <div class="flex-1">
                                <x-form.select name="stock_status" label="Availability" :value="$product->stock_status->value"
                                    :options="collect(App\Enums\StockStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                            </div>
                            <x-admin.button variant="secondary">Save</x-admin.button>
                        </form>
                    @endcan
                @endif

                <h3 class="mt-6 text-xs font-semibold uppercase tracking-wider text-gray-500">Recent stock movements</h3>
                @if ($movements->isEmpty())
                    <p class="mt-2 text-sm text-gray-500">None yet.</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach ($movements as $movement)
                            <li class="py-2">
                                <div class="flex justify-between gap-2">
                                    <span class="text-gray-900">{{ $movement->type->label() }}</span>
                                    <span @class(['tabular-nums font-medium', 'text-green-700' => $movement->quantity_change > 0, 'text-red-700' => $movement->quantity_change < 0])>
                                        {{ $movement->quantity_change > 0 ? '+' : '' }}{{ $movement->quantity_change }} → {{ $movement->quantity_after }}
                                    </span>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $movement->created_at->format('d M Y, h:i A') }} · {{ $movement->user?->name ?? 'System' }}
                                    @if ($movement->note) · {{ $movement->note }} @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Delete --}}
            @can('delete', $product)
                <section class="rounded-lg border border-red-200 bg-white p-6">
                    <h2 class="text-sm font-semibold text-red-700">Delete product</h2>
                    @if ($product->order_items_count > 0)
                        <p class="mt-1 text-sm text-gray-600">This product has been ordered and must be kept for order history. Archive it instead.</p>
                    @else
                        <p class="mt-1 text-sm text-gray-600">Permanently deletes the product, its images and its stock history.</p>
                        <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="mt-4"
                            onsubmit="return confirm('Delete this product permanently?')">
                            @csrf
                            @method('DELETE')
                            <x-admin.button variant="danger">Delete product</x-admin.button>
                        </form>
                    @endif
                </section>
            @endcan
        </div>
    </div>
</x-layouts::admin>
