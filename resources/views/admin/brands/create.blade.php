<x-layouts::admin title="New brand">
    <x-admin.page-header title="New brand" />

    @include('admin.brands.form', [
        'action' => route('admin.brands.store'),
        'method' => 'POST',
        'submitLabel' => 'Create brand',
    ])
</x-layouts::admin>
