@props(['status'])

<x-admin.badge :tone="match ($status) {
    App\Enums\ProductStatus::Active => 'green',
    App\Enums\ProductStatus::Draft => 'yellow',
    App\Enums\ProductStatus::Archived => 'gray',
}">{{ $status->label() }}</x-admin.badge>
