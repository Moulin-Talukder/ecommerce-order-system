<x-layouts::admin title="Customers">
    <x-admin.page-header title="Customers" description="{{ $customers->total() }} {{ Str::plural('customer', $customers->total()) }}{{ $search !== '' || $status ? ' match your filters' : '' }}.">
        @can('create', App\Models\Customer::class)
            <x-slot:actions>
                <x-admin.button :href="route('admin.customers.create')">New customer</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    <form method="GET" action="{{ route('admin.customers.index') }}" class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-80">
            <label for="search" class="sr-only">Search</label>
            <input id="search" name="search" type="search" value="{{ $search }}" placeholder="Search name, phone or email…"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
        </div>
        <div>
            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status" class="block rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
                <option value="">All statuses</option>
                @foreach (App\Enums\CustomerStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <x-admin.button variant="secondary">Filter</x-admin.button>
        @if ($search !== '' || $status)
            <a href="{{ route('admin.customers.index') }}" class="text-sm text-gray-600 underline hover:text-gray-900">Clear</a>
        @endif
    </form>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Customer</th>
                    <th scope="col" class="px-4 py-3">Phone</th>
                    <th scope="col" class="px-4 py-3">Location</th>
                    <th scope="col" class="px-4 py-3 text-right">Orders</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($customers as $customer)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.customers.show', $customer) }}" class="font-medium text-gray-900 hover:underline">{{ $customer->name }}</a>
                            <div class="text-xs text-gray-500">{{ $customer->email ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-700">
                            {{ $customer->phone }}
                            @if ($customer->alternate_phone)
                                <div class="text-gray-500">{{ $customer->alternate_phone }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700">{{ collect([$customer->area, $customer->city])->filter()->implode(', ') ?: '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ $customer->orders_count }}</td>
                        <td class="px-4 py-3"><x-admin.customer-status-badge :status="$customer->status" /></td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.customers.show', $customer) }}" class="mr-3 text-gray-600 hover:text-gray-900">View</a>
                            @can('update', $customer)
                                <a href="{{ route('admin.customers.edit', $customer) }}" class="font-medium text-gray-900 hover:underline">Edit</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                            {{ $search !== '' || $status ? 'No customers match your filters.' : 'No customers yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $customers->links() }}</div>
</x-layouts::admin>
