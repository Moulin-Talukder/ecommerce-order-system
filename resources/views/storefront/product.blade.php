<x-layouts::storefront :title="$product->meta_title ?: $product->name" :metaDescription="$product->meta_description ?: $product->short_description">
    <nav class="mb-4 text-sm text-gray-500" aria-label="Breadcrumb">
        <a href="{{ route('storefront.home') }}" class="hover:underline">Home</a>
        › <a href="{{ route('storefront.category', $product->category->slug) }}" class="hover:underline">{{ $categoryPath }}</a>
    </nav>

    <div class="grid gap-8 md:grid-cols-2">
        <div x-data="{ active: 0 }">
            <div class="aspect-square overflow-hidden rounded-lg border border-gray-200 bg-white">
                @forelse ($product->images as $image)
                    <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?? $product->name }}" class="size-full object-contain"
                        x-show="active === {{ $loop->index }}" @if (! $loop->first) x-cloak @endif>
                @empty
                    <span class="flex size-full items-center justify-center text-sm text-gray-400">No image</span>
                @endforelse
            </div>
            @if ($product->images->count() > 1)
                <div class="mt-3 flex gap-2 overflow-x-auto">
                    @foreach ($product->images as $image)
                        <button type="button" x-on:click="active = {{ $loop->index }}" class="size-16 shrink-0 overflow-hidden rounded border"
                            x-bind:class="active === {{ $loop->index }} ? 'border-gray-900' : 'border-gray-200'">
                            <img src="{{ $image->url() }}" alt="" class="size-full object-cover" loading="lazy">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div>
            @if ($product->brand)
                <p class="text-sm text-gray-500">{{ $product->brand->name }}</p>
            @endif
            <h1 class="mt-1 text-2xl font-semibold">{{ $product->name }}</h1>
            <p class="mt-1 font-mono text-xs text-gray-500">SKU {{ $product->sku }}</p>

            <x-storefront.price :product="$product" class="mt-4 block text-xl" />
            @if ($product->discount_price !== null)
                <p class="text-sm text-green-700">You save {{ App\Support\Money::format(bcsub($product->selling_price, $product->discount_price, 2)) }}</p>
            @endif

            <p @class(['mt-3 text-sm font-medium', 'text-green-700' => $inStock, 'text-red-700' => ! $inStock])>{{ $inStock ? 'In stock' : 'Out of stock' }}</p>

            @if ($product->short_description)
                <p class="mt-4 text-gray-700">{{ $product->short_description }}</p>
            @endif

            <div class="mt-6">
                @if ($inStock)
                    <livewire:storefront.add-to-cart :product-id="$product->id" :max-quantity="$maxQuantity" />
                @else
                    <p class="rounded-md bg-gray-100 px-4 py-3 text-sm text-gray-600">This product is currently out of stock.</p>
                @endif
            </div>

            <p class="mt-6 text-sm text-gray-500">Cash on Delivery · Delivery charge ৳{{ (int) config('storefront.delivery_zones.inside_dhaka.charge') }} inside Dhaka, ৳{{ (int) config('storefront.delivery_zones.outside_dhaka.charge') }} outside Dhaka.</p>
        </div>
    </div>

    @if ($product->description)
        <section class="mt-10 max-w-3xl">
            <h2 class="text-lg font-semibold">Description</h2>
            <div class="mt-2 text-sm whitespace-pre-line text-gray-700">{{ $product->description }}</div>
        </section>
    @endif
</x-layouts::storefront>
