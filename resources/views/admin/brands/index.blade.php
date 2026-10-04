<x-layouts::admin title="Brands">
    <x-admin.page-header title="Brands" description="Manufacturers and labels your products can belong to.">
        @can('create', App\Models\Brand::class)
            <x-slot:actions>
                <x-admin.button :href="route('admin.brands.create')">New brand</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    <form method="GET" action="{{ route('admin.brands.index') }}" class="mb-4 flex flex-wrap items-end gap-3">
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
        @if ($search !== '' || filled($status))
            <a href="{{ route('admin.brands.index') }}" class="text-sm text-gray-600 underline hover:text-gray-900">Clear</a>
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
                @forelse ($brands as $brand)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $brand->name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $brand->slug }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ $brand->products_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <x-admin.status-badge :active="$brand->is_active" />
                                @can('update', $brand)
                                    <form method="POST" action="{{ route('admin.brands.status', $brand) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $brand->is_active ? 0 : 1 }}">
                                        <button type="submit" class="text-xs text-gray-600 underline hover:text-gray-900">
                                            {{ $brand->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @can('update', $brand)
                                <a href="{{ route('admin.brands.edit', $brand) }}" class="font-medium text-gray-900 hover:underline">Edit</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-gray-500">
                            {{ $search !== '' || filled($status) ? 'No brands match your filters.' : 'No brands yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $brands->links() }}</div>
</x-layouts::admin>
