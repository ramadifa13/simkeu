@props([
    'name' => 'catatan',
    'label' => null,
    'placeholder' => 'Catatan (opsional)',
    'rows' => 3,
    'required' => false,
    'minlength' => null,
    'maxlength' => null,
])

<flux:field {{ $attributes }}>
    @if (filled($label))
        <flux:label>
            {{ $label }}
            @if ($required)
                <x-required-mark />
            @endif
        </flux:label>
    @endif
    <flux:textarea
        name="{{ $name }}"
        rows="{{ $rows }}"
        placeholder="{{ $placeholder }}"
        @if ($required) required @endif
        @if (filled($minlength)) minlength="{{ $minlength }}" @endif
        @if (filled($maxlength)) maxlength="{{ $maxlength }}" @endif
    />
</flux:field>
