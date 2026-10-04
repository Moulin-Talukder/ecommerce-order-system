@props(['active'])

<span @class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
    'bg-green-100 text-green-800' => $active,
    'bg-gray-100 text-gray-600' => ! $active,
])>{{ $active ? 'Active' : 'Inactive' }}</span>
