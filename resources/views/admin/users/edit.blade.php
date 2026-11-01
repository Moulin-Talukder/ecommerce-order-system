<x-layouts::admin title="Edit account">
    <x-admin.page-header title="Edit account" :description="$user->name.' · '.$user->role->label()" />

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="font-medium">The changes were not saved.</p>
            <ul class="mt-1 list-disc ps-5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
            <x-form.input name="name" label="Name" :value="$user->name" required maxlength="255" />
            <x-form.input name="email" label="Email" type="email" :value="$user->email" required maxlength="255" autocomplete="off" />
            @if ($user->isSuperAdmin())
                <p class="text-sm text-gray-600 sm:col-span-2">Role: <strong>{{ $user->role->label() }}</strong>. A Super Admin's role and status cannot be changed here.</p>
            @else
                <x-form.select name="role" label="Role" :options="$roles" :value="$user->role->value" />
            @endif
        </div>

        <div class="flex items-center gap-3">
            <x-admin.button>Save changes</x-admin.button>
            <x-admin.button variant="secondary" :href="route('admin.users.index')">Cancel</x-admin.button>
        </div>
    </form>

    @can('changeStatus', $user)
        <div class="mt-10 rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="text-sm font-semibold text-gray-900">Account status</h2>
            <p class="mt-1 text-sm text-gray-600">
                This account is <strong>{{ $user->is_active ? 'active' : 'inactive' }}</strong>.
                {{ $user->is_active ? 'Deactivating signs them out everywhere and blocks sign-in.' : 'Activating lets them sign in again.' }}
            </p>
            <form method="POST" action="{{ route('admin.users.status', $user) }}" class="mt-4"
                @if ($user->is_active) onsubmit="return confirm('Deactivate this account? They will be signed out everywhere.')" @endif>
                @csrf
                @method('PATCH')
                <input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}">
                <x-admin.button :variant="$user->is_active ? 'danger' : 'secondary'">{{ $user->is_active ? 'Deactivate account' : 'Activate account' }}</x-admin.button>
            </form>
        </div>
    @endcan

    @can('resetPassword', $user)
        <div class="mt-6 rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="text-sm font-semibold text-gray-900">Set a new password</h2>
            <p class="mt-1 text-sm text-gray-600">The current password is never shown. Setting a new one signs this user out everywhere.</p>
            <form method="POST" action="{{ route('admin.users.password', $user) }}" class="mt-4 grid gap-4 sm:grid-cols-2"
                onsubmit="return confirm('Set a new password for this account? They will be signed out everywhere.')">
                @csrf
                @method('PUT')
                <x-form.input name="password" label="New password" type="password" required autocomplete="new-password"
                    hint="At least 12 characters, with upper- and lower-case letters and a number." />
                <x-form.input name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />
                <div class="sm:col-span-2"><x-admin.button variant="secondary">Set new password</x-admin.button></div>
            </form>
        </div>
    @endcan
</x-layouts::admin>
