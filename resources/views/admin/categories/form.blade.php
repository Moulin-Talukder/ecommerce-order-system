{{-- Shared create/edit form. Expects $category, $parentOptions, $action, $method. --}}
<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @method($method)

    <div class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
        <x-form.input name="name" label="Name" :value="$category->name" required maxlength="150" autofocus />

        <x-form.input name="slug" label="Slug" :value="$category->slug" maxlength="170"
            hint="Used in URLs. Leave blank to generate it from the name." />

        <x-form.select name="parent_id" label="Parent category" :options="$parentOptions" :value="$category->parent_id"
            placeholder="— None (top-level category) —"
            hint="Only categories that keep the tree within {{ App\Models\Category::MAX_DEPTH }} levels are listed." />

        <x-form.input name="sort_order" label="Sort order" type="number" min="0" :value="$category->sort_order ?? 0"
            hint="Lower numbers appear first." />

        <div class="sm:col-span-2">
            <x-form.textarea name="description" label="Description" :value="$category->description" rows="4" />
        </div>

        <div class="sm:col-span-2">
            <x-form.checkbox name="is_active" label="Active" :checked="$category->is_active"
                hint="Inactive categories (and their subcategories) are hidden from the storefront." />
        </div>
    </div>

    <div class="grid gap-6 rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-gray-900">Search engine listing</h2>
        <x-form.input name="meta_title" label="Meta title" :value="$category->meta_title" maxlength="255" />
        <x-form.textarea name="meta_description" label="Meta description" :value="$category->meta_description" rows="2" maxlength="500" />
    </div>

    <div class="flex items-center gap-3">
        <x-admin.button>{{ $submitLabel }}</x-admin.button>
        <x-admin.button variant="secondary" :href="route('admin.categories.index')">Cancel</x-admin.button>
    </div>
</form>
