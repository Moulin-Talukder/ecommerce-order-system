<x-layouts::admin title="New customer">
    <x-admin.page-header title="New customer" />

    @include('admin.customers.form', [
        'action' => route('admin.customers.store'),
        'method' => 'POST',
        'submitLabel' => 'Create customer',
    ])
</x-layouts::admin>
