@props(['product'])

{{-- Customer-facing price: sale price with the regular price struck through. --}}
<span {{ $attributes }}>
    <span class="font-semibold text-gray-900">{{ App\Support\Money::format($product->effectivePrice()) }}</span>
    @if ($product->discount_price !== null)
        <span class="ml-1 text-sm text-gray-500 line-through">{{ App\Support\Money::format($product->selling_price) }}</span>
    @endif
</span>
