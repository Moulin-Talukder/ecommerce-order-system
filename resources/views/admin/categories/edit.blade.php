<x-layouts::admin title="Edit category">
    <x-admin.page-header title="Edit category" :description="$category->name" />

    @include('admin.categories.form', [
        'action' => route('admin.categories.update', $category),
        'method' => 'PUT',
        'submitLabel' => 'Save changes',
    ])

    @can('delete', $category)
        <div class="mt-10 rounded-lg border border-red-200 bg-white p-6">
            <h2 class="text-sm font-semibold text-red-700">Delete category</h2>

            @if ($category->products_count > 0 || $category->children_count > 0)
                <p class="mt-1 text-sm text-gray-600">
                    This category cannot be deleted because it has
                    {{ $category->products_count }} {{ Str::plural('product', $category->products_count) }} and
                    {{ $category->children_count }} {{ Str::plural('subcategory', $category->children_count) }}.
                    Deactivate it instead to hide it from the storefront.
                </p>
            @else
                <p class="mt-1 text-sm text-gray-600">This category is not used by any product or subcategory. Deleting it is permanent.</p>
                <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" class="mt-4"
                    onsubmit="return confirm('Delete this category permanently?')">
                    @csrf
                    @method('DELETE')
                    <x-admin.button variant="danger">Delete category</x-admin.button>
                </form>
            @endif
        </div>
    @endcan
</x-layouts::admin>
