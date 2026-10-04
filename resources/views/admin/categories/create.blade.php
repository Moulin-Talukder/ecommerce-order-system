<x-layouts::admin title="New category">
    <x-admin.page-header title="New category" />

    @include('admin.categories.form', [
        'action' => route('admin.categories.store'),
        'method' => 'POST',
        'submitLabel' => 'Create category',
    ])
</x-layouts::admin>
