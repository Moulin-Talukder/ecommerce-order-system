@props(['product'])

@if ($product->stock_status === App\Enums\StockStatus::OutOfStock)
    <x-admin.badge tone="red">Out of stock</x-admin.badge>
@elseif ($product->isLowStock())
    <x-admin.badge tone="yellow">Low stock</x-admin.badge>
@else
    <x-admin.badge tone="green">In stock</x-admin.badge>
@endif
