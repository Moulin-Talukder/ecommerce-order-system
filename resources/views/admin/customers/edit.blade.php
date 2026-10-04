<x-layouts::admin title="Edit customer">
    <x-admin.page-header title="Edit customer" :description="$customer->name" />

    @include('admin.customers.form', [
        'action' => route('admin.customers.update', $customer),
        'method' => 'PUT',
        'submitLabel' => 'Save changes',
    ])
</x-layouts::admin>
