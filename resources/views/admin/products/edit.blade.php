<x-layouts::admin title="Edit product">
    <x-admin.page-header title="Edit product" :description="$product->name" />

    @include('admin.products.form', [
        'action' => route('admin.products.update', $product),
        'method' => 'PUT',
        'submitLabel' => 'Save changes',
    ])
</x-layouts::admin>
