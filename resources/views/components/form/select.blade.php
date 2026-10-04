@props(['name', 'label', 'options' => [], 'value' => null, 'placeholder' => null, 'hint' => null, 'bag' => 'default', 'id' => null])

@php($fieldId = $id ?? $name)

@php($selected = (string) old($name, $value))

<div>
    <label for="{{ $fieldId }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    <select
        id="{{ $fieldId }}"
        name="{{ $name }}"
        @error($name, $bag) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror
        {{ $attributes->class([
            'mt-1 block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-gray-900',
            'border-red-500' => $errors->getBag($bag)->has($name),
            'border-gray-300' => ! $errors->getBag($bag)->has($name),
        ]) }}
    >
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($selected === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
    @error($name, $bag)
        <p id="{{ $fieldId }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
