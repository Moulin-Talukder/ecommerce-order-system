@props(['name', 'label', 'checked' => false, 'hint' => null])

<div>
    <input type="hidden" name="{{ $name }}" value="0">
    <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
        <input
            type="checkbox"
            name="{{ $name }}"
            value="1"
            @checked(old($name, $checked))
            {{ $attributes->class('rounded border-gray-300') }}
        >
        {{ $label }}
    </label>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
