@props(['product'])

@if ($product->discount_price !== null)
    <span class="font-medium text-gray-900">{{ App\Support\Money::format($product->discount_price) }}</span>
    <span class="ml-1 text-xs text-gray-500 line-through">{{ App\Support\Money::format($product->selling_price) }}</span>
@else
    <span class="font-medium text-gray-900">{{ App\Support\Money::format($product->selling_price) }}</span>
@endif
