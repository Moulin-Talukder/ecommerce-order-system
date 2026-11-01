<x-layouts::admin title="New account">
    <x-admin.page-header title="New account" description="Create an admin-panel account for a staff member." />

    <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-6">
        @csrf

        <div class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
            <x-form.input name="name" label="Name" :value="$user->name" required maxlength="255" autofocus />
            <x-form.input name="email" label="Email" type="email" :value="$user->email" required maxlength="255" autocomplete="off" />
            <x-form.select name="role" label="Role" :options="$roles" :value="$user->role->value"
                hint="Super Admin accounts cannot be created here." />
            <div class="sm:col-span-2">
                <x-form.checkbox name="is_active" label="Active" :checked="$user->is_active"
                    hint="Inactive accounts cannot sign in." />
            </div>
            <x-form.input name="password" label="Password" type="password" required autocomplete="new-password"
                hint="At least 12 characters, with upper- and lower-case letters and a number." />
            <x-form.input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        </div>

        <div class="flex items-center gap-3">
            <x-admin.button>Create account</x-admin.button>
            <x-admin.button variant="secondary" :href="route('admin.users.index')">Cancel</x-admin.button>
        </div>
    </form>
</x-layouts::admin>
