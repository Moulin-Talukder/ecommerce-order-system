<x-layouts::admin title="New product">
    <x-admin.page-header title="New product" description="Images can be added after the product is created." />

    @include('admin.products.form', [
        'action' => route('admin.products.store'),
        'method' => 'POST',
        'submitLabel' => 'Create product',
    ])
</x-layouts::admin>
