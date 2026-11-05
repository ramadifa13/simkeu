@props([
    'name' => 'catatan',
    'label' => 'Catatan',
    'placeholder' => 'Catatan (opsional)',
    'rows' => 3,
    'required' => false,
    'minlength' => null,
    'maxlength' => null,
])

@php
    $fieldId = 'note-'.preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $name);
@endphp

<div {{ $attributes->class(['ui-note-field']) }}>
    @if (filled($label))
        <label class="ui-note-field-label" for="{{ $fieldId }}">
            <span>{{ $label }}</span>
            @if ($required)
                <x-required-mark />
            @endif
        </label>
    @endif
    <textarea
        id="{{ $fieldId }}"
        name="{{ $name }}"
        rows="{{ max(2, (int) $rows) }}"
        placeholder="{{ $placeholder }}"
        class="ui-note-field-input"
        data-flux-control
        @if ($required) required @endif
        @if (filled($minlength)) minlength="{{ $minlength }}" @endif
        @if (filled($maxlength)) maxlength="{{ $maxlength }}" @endif
    ></textarea>
</div>
