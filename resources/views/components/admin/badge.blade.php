@props(['tone' => 'gray'])

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
    match ($tone) {
        'green' => 'bg-green-100 text-green-800',
        'yellow' => 'bg-yellow-100 text-yellow-800',
        'red' => 'bg-red-100 text-red-800',
        'blue' => 'bg-blue-100 text-blue-800',
        default => 'bg-gray-100 text-gray-700',
    },
]) }}>{{ $slot }}</span>
