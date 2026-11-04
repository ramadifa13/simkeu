@props([
    'name' => 'tanggal_bayar',
    'label' => 'Tanggal bayar',
    'value' => null,
    'max' => null,
    'min' => null,
    'required' => true,
])

@php
    $value ??= today()->toDateString();
    $max ??= today()->toDateString();
@endphp

<flux:field>
    <flux:label>
        {{ $label }}
        @if ($required)
            <x-required-mark />
        @endif
    </flux:label>
    <x-date-input
        :name="$name"
        :value="$value"
        :min="$min"
        :max="$max"
        :required="$required"
        {{ $attributes }}
    />
</flux:field>
