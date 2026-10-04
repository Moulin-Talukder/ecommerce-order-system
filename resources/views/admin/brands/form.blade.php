{{-- Shared create/edit form. Expects $brand, $action, $method, $submitLabel. --}}
<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @method($method)

    <div class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
        <x-form.input name="name" label="Name" :value="$brand->name" required maxlength="150" autofocus />

        <x-form.input name="slug" label="Slug" :value="$brand->slug" maxlength="170"
            hint="Used in URLs. Leave blank to generate it from the name." />

        <div class="sm:col-span-2">
            <x-form.textarea name="description" label="Description" :value="$brand->description" rows="4" />
        </div>

        <div class="sm:col-span-2">
            <x-form.checkbox name="is_active" label="Active" :checked="$brand->is_active"
                hint="Inactive brands are hidden from storefront filters." />
        </div>
    </div>

    <div class="flex items-center gap-3">
        <x-admin.button>{{ $submitLabel }}</x-admin.button>
        <x-admin.button variant="secondary" :href="route('admin.brands.index')">Cancel</x-admin.button>
    </div>
</form>
