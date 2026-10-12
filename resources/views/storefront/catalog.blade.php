<x-layouts::storefront :title="$title" :metaDescription="$category?->meta_description">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">
            @if ($category)
                {{ $category->name }}
            @elseif ($search !== '')
                Results for “{{ $search }}”
            @else
                All products
            @endif
        </h1>
        @if ($category && isset($categoryPath) && $categoryPath !== $category->name)
            <p class="mt-1 text-sm text-gray-500">{{ $categoryPath }}</p>
        @endif
        @if ($category?->description)
            <p class="mt-2 max-w-2xl text-sm text-gray-600">{{ $category->description }}</p>
        @endif
    </div>

    @if ($category)
        <form method="GET" action="{{ route('storefront.category', $category->slug) }}" class="mb-4 flex max-w-sm gap-2" role="search">
            <label for="category-q" class="sr-only">Search in {{ $category->name }}</label>
            <input id="category-q" name="q" type="search" value="{{ $search }}" placeholder="Search in {{ $category->name }}…" maxlength="100"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
            <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm">Search</button>
        </form>
    @endif

    @if ($products->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">
            {{ $search !== '' ? 'No products match your search.' : 'No products here yet.' }}
            <a href="{{ route('storefront.home') }}" class="ml-1 underline">Browse all products</a>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($products as $product)
                <x-storefront.product-card :product="$product" />
            @endforeach
        </div>
        <div class="mt-6">{{ $products->links() }}</div>
    @endif
</x-layouts::storefront>
