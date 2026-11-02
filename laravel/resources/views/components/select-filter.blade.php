@props(['label', 'name', 'options' => [], 'value' => null])

<flux:field class="min-w-40">
    <flux:label>{{ $label }}</flux:label>
    <flux:select name="{{ $name }}" {{ $attributes }}>
        {{ $slot }}
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </flux:select>
</flux:field>
