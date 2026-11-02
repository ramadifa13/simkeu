@props([
    'label',
    'name',
    'options' => [],
    'value' => '',
    'placeholder' => 'Ketik untuk mencari…',
    'required' => false,
])

@php($listId = 'autocomplete-'.\Illuminate\Support\Str::slug($name).'-'.\Illuminate\Support\Str::random(6))

<flux:field>
    <flux:label>{{ $label }}</flux:label>
    <flux:input name="{{ $name }}" value="{{ $value }}" list="{{ $listId }}" placeholder="{{ $placeholder }}" :required="$required" autocomplete="off" {{ $attributes }} />
    <datalist id="{{ $listId }}">
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
        @endforeach
    </datalist>
    <flux:error name="{{ $name }}" />
</flux:field>
