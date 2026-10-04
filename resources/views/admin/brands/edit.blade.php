<x-layouts::admin title="Edit brand">
    <x-admin.page-header title="Edit brand" :description="$brand->name" />

    @include('admin.brands.form', [
        'action' => route('admin.brands.update', $brand),
        'method' => 'PUT',
        'submitLabel' => 'Save changes',
    ])

    @can('delete', $brand)
        <div class="mt-10 rounded-lg border border-red-200 bg-white p-6">
            <h2 class="text-sm font-semibold text-red-700">Delete brand</h2>

            @if ($brand->products_count > 0)
                @php($productCount = $brand->products_count)
                <p class="mt-1 text-sm text-gray-600">
                    This brand cannot be deleted because {{ $productCount }} {{ Str::plural('product', $productCount) }} {{ $productCount === 1 ? 'uses' : 'use' }} it. Deactivate it instead.
                </p>
            @else
                <p class="mt-1 text-sm text-gray-600">No products use this brand. Deleting it is permanent.</p>
                <form method="POST" action="{{ route('admin.brands.destroy', $brand) }}" class="mt-4"
                    onsubmit="return confirm('Delete this brand permanently?')">
                    @csrf
                    @method('DELETE')
                    <x-admin.button variant="danger">Delete brand</x-admin.button>
                </form>
            @endif
        </div>
    @endcan
</x-layouts::admin>
