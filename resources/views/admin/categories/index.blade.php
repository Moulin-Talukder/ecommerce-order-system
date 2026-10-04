<x-layouts::admin title="Categories">
    <x-admin.page-header title="Categories" description="Organize products into up to {{ App\Models\Category::MAX_DEPTH }} levels of categories.">
        @can('create', App\Models\Category::class)
            <x-slot:actions>
                <x-admin.button :href="route('admin.categories.create')">New category</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    <form method="GET" action="{{ route('admin.categories.index') }}" class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-72">
            <label for="search" class="sr-only">Search</label>
            <input id="search" name="search" type="search" value="{{ $search }}" placeholder="Search name or slug…"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
        </div>
        <div>
            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status" class="block rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
                <option value="">All statuses</option>
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="inactive" @selected($status === 'inactive')>Inactive</option>
            </select>
        </div>
        <x-admin.button variant="secondary">Filter</x-admin.button>
        @if ($isFiltered)
            <a href="{{ route('admin.categories.index') }}" class="text-sm text-gray-600 underline hover:text-gray-900">Clear</a>
        @endif
    </form>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Slug</th>
                    <th scope="col" class="px-4 py-3 text-right">Products</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    @php($category = $row['category'])
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            @if ($isFiltered)
                                <span class="font-medium text-gray-900">{{ $row['path'] }}</span>
                            @else
                                <div class="flex items-center" style="padding-left: {{ ($row['depth'] - 1) * 1.5 }}rem">
                                    @if ($row['depth'] > 1)
                                        <span class="mr-2 text-gray-400" aria-hidden="true">└</span>
                                    @endif
                                    <span @class(['text-gray-900', 'font-semibold' => $row['depth'] === 1, 'font-medium' => $row['depth'] > 1])>{{ $category->name }}</span>
                                    @if ($category->children_count > 0)
                                        <span class="ml-2 text-xs text-gray-500">({{ $category->children_count }} {{ Str::plural('subcategory', $category->children_count) }})</span>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $category->slug }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ $category->products_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <x-admin.status-badge :active="$category->is_active" />
                                @can('update', $category)
                                    <form method="POST" action="{{ route('admin.categories.status', $category) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $category->is_active ? 0 : 1 }}">
                                        <button type="submit" class="text-xs text-gray-600 underline hover:text-gray-900">
                                            {{ $category->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($row['depth'] < App\Models\Category::MAX_DEPTH)
                                @can('create', App\Models\Category::class)
                                    <a href="{{ route('admin.categories.create', ['parent' => $category->id]) }}" class="mr-3 text-gray-600 hover:text-gray-900">Add subcategory</a>
                                @endcan
                            @endif
                            @can('update', $category)
                                <a href="{{ route('admin.categories.edit', $category) }}" class="font-medium text-gray-900 hover:underline">Edit</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-gray-500">
                            {{ $isFiltered ? 'No categories match your filters.' : 'No categories yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts::admin>
