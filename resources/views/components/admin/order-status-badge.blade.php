@props(['status'])

<x-admin.badge :tone="match ($status) {
    App\Enums\OrderStatus::Pending => 'yellow',
    App\Enums\OrderStatus::Confirmed, App\Enums\OrderStatus::Processing, App\Enums\OrderStatus::Shipped => 'blue',
    App\Enums\OrderStatus::Delivered => 'green',
    App\Enums\OrderStatus::Cancelled, App\Enums\OrderStatus::Returned => 'red',
}">{{ $status->label() }}</x-admin.badge>
