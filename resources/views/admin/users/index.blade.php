<x-layouts::admin title="Users">
    <x-admin.page-header title="Users" description="Admin-panel accounts. Deactivate an account to remove access; it is never deleted.">
        @can('create', App\Models\User::class)
            <x-slot:actions>
                <x-admin.button :href="route('admin.users.create')">New account</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    <form method="GET" action="{{ route('admin.users.index') }}" class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-72">
            <label for="search" class="sr-only">Search</label>
            <input id="search" name="search" type="search" value="{{ $search }}" placeholder="Search name or email…"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
        </div>
        <div>
            <label for="role" class="sr-only">Role</label>
            <select id="role" name="role" class="block rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
                <option value="">All roles</option>
                @foreach (App\Enums\UserRole::cases() as $case)
                    <option value="{{ $case->value }}" @selected($role === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
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
        @if ($search !== '' || $role || filled($status))
            <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-600 underline hover:text-gray-900">Clear</a>
        @endif
    </form>

    <div class="relative overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Email</th>
                    <th scope="col" class="px-4 py-3">Role</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Created</th>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($users as $listedUser)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">
                            {{ $listedUser->name }}
                            @if ($listedUser->is(auth()->user()))
                                <x-admin.badge tone="blue">You</x-admin.badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700">{{ $listedUser->email }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $listedUser->role->label() }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <x-admin.status-badge :active="$listedUser->is_active" />
                                @can('changeStatus', $listedUser)
                                    <form method="POST" action="{{ route('admin.users.status', $listedUser) }}"
                                        @if ($listedUser->is_active) onsubmit="return confirm('Deactivate {{ e($listedUser->name) }}? They will be signed out everywhere.')" @endif>
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $listedUser->is_active ? 0 : 1 }}">
                                        <button type="submit" class="text-xs text-gray-600 underline hover:text-gray-900">
                                            {{ $listedUser->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-600">{{ $listedUser->created_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right">
                            @can('update', $listedUser)
                                <a href="{{ route('admin.users.edit', $listedUser) }}" class="font-medium text-gray-900 hover:underline">Edit</a>
                            @else
                                <span class="text-xs text-gray-400">Protected</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-500">No accounts match your filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</x-layouts::admin>
