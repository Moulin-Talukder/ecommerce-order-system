@props(['href', 'active' => false])

<a
    href="{{ $href }}"
    wire:navigate
    @if ($active) aria-current="page" @endif
    @class([
        'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
        'bg-gray-800 text-white' => $active,
        'hover:bg-gray-800 hover:text-white' => ! $active,
    ])
>{{ $slot }}</a>
