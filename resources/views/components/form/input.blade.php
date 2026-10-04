@props(['name', 'label', 'value' => null, 'type' => 'text', 'hint' => null, 'bag' => 'default', 'id' => null])

@php($fieldId = $id ?? $name)

<div>
    <label for="{{ $fieldId }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        @error($name, $bag) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror
        {{ $attributes->class([
            'mt-1 block w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900',
            'border-red-500' => $errors->getBag($bag)->has($name),
            'border-gray-300' => ! $errors->getBag($bag)->has($name),
        ]) }}
    >
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
    @error($name, $bag)
        <p id="{{ $fieldId }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
