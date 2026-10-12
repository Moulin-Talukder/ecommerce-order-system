@props(['product'])

@php($catalog = app(App\Services\Storefront\Catalog::class))

<article class="flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white">
    <a href="{{ route('storefront.product', $product->slug) }}" class="block aspect-square bg-gray-100">
        @if ($product->primaryImage)
            <img src="{{ $product->primaryImage->url() }}" alt="{{ $product->primaryImage->alt_text ?? $product->name }}" class="size-full object-cover" loading="lazy">
        @else
            <span class="flex size-full items-center justify-center text-xs text-gray-400">No image</span>
        @endif
    </a>
    <div class="flex flex-1 flex-col gap-1 p-3">
        @if ($product->brand)
            <span class="text-xs text-gray-500">{{ $product->brand->name }}</span>
        @endif
        <a href="{{ route('storefront.product', $product->slug) }}" class="line-clamp-2 text-sm font-medium text-gray-900 hover:underline">{{ $product->name }}</a>
        <x-storefront.price :product="$product" class="mt-1" />
        <div class="mt-auto pt-2">
            @if ($catalog->inStock($product))
                <form method="POST" action="{{ route('storefront.cart.add') }}">
                    @csrf
                    <input type="hidden" name="product" value="{{ $product->slug }}">
                    <button type="submit" class="w-full rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800">Add to cart</button>
                </form>
            @else
                <p class="rounded-md bg-gray-100 px-3 py-2 text-center text-sm text-gray-500">Out of stock</p>
            @endif
        </div>
    </div>
</article>
