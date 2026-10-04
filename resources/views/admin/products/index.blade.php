<x-layouts::admin title="Products">
    <x-admin.page-header title="Products" description="{{ $products->total() }} {{ Str::plural('product', $products->total()) }}{{ $isFiltered ? ' match your filters' : '' }}.">
        @can('create', App\Models\Product::class)
            <x-slot:actions>
                <x-admin.button :href="route('admin.products.create')">New product</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    <form method="GET" action="{{ route('admin.products.index') }}" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        @php($filterClass = 'block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900')
        <div class="lg:col-span-2">
            <label for="search" class="sr-only">Search</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Search name, SKU or slug…" class="{{ $filterClass }}">
        </div>
        <div>
            <label for="category" class="sr-only">Category</label>
            <select id="category" name="category" class="{{ $filterClass }}">
                <option value="">All categories</option>
                @foreach ($categoryOptions as $id => $path)
                    <option value="{{ $id }}" @selected($filters['category'] === $id)>{{ $path }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="brand" class="sr-only">Brand</label>
            <select id="brand" name="brand" class="{{ $filterClass }}">
                <option value="">All brands</option>
                @foreach ($brandOptions as $id => $name)
                    <option value="{{ $id }}" @selected($filters['brand'] === $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status" class="{{ $filterClass }}">
                <option value="">All statuses</option>
                @foreach (App\Enums\ProductStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($filters['status'] === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="stock" class="sr-only">Stock</label>
            <select id="stock" name="stock" class="{{ $filterClass }}">
                <option value="">Any stock</option>
                <option value="in_stock" @selected($filters['stock'] === 'in_stock')>In stock</option>
                <option value="low" @selected($filters['stock'] === 'low')>Low stock</option>
                <option value="out_of_stock" @selected($filters['stock'] === 'out_of_stock')>Out of stock</option>
            </select>
        </div>
        <div class="flex items-center gap-3 lg:col-span-6">
            <x-admin.button variant="secondary">Filter</x-admin.button>
            @if ($isFiltered)
                <a href="{{ route('admin.products.index') }}" class="text-sm text-gray-600 underline hover:text-gray-900">Clear filters</a>
            @endif
        </div>
    </form>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Product</th>
                    <th scope="col" class="px-4 py-3">Category / Brand</th>
                    <th scope="col" class="px-4 py-3 text-right">Price</th>
                    <th scope="col" class="px-4 py-3">Stock</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($products as $product)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                @if ($product->primaryImage)
                                    <img src="{{ $product->primaryImage->url() }}" alt="" class="size-10 shrink-0 rounded object-cover ring-1 ring-gray-200" loading="lazy">
                                @else
                                    <div class="size-10 shrink-0 rounded bg-gray-100 ring-1 ring-gray-200" aria-hidden="true"></div>
                                @endif
                                <div class="min-w-0">
                                    <a href="{{ route('admin.products.show', $product) }}" class="font-medium text-gray-900 hover:underline">{{ $product->name }}</a>
                                    @if ($product->is_featured)
                                        <span class="ml-1 text-yellow-500" title="Featured" aria-label="Featured">★</span>
                                    @endif
                                    <div class="font-mono text-xs text-gray-500">{{ $product->sku }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            <div>{{ $product->category->name }}</div>
                            <div class="text-xs text-gray-500">{{ $product->brand?->name ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums"><x-admin.price :product="$product" /></td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <x-admin.stock-badge :product="$product" />
                            @if ($product->manage_stock)
                                <span class="ml-1 tabular-nums text-gray-600">{{ $product->stock_quantity }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3"><x-admin.product-status-badge :status="$product->status" /></td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.products.show', $product) }}" class="mr-3 text-gray-600 hover:text-gray-900">View</a>
                            @can('update', $product)
                                <a href="{{ route('admin.products.edit', $product) }}" class="font-medium text-gray-900 hover:underline">Edit</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                            {{ $isFiltered ? 'No products match your filters.' : 'No products yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
</x-layouts::admin>
