@props([
    'name' => null,
    'value' => null,
    'type' => 'date',
    'required' => false,
    'max' => null,
    'min' => null,
])

@php
    $allowed = ['date', 'datetime-local', 'month', 'time', 'week'];
    $type = in_array($type, $allowed, true) ? $type : 'date';
@endphp

<div
    class="ui-date-picker"
    x-data="{
        open() {
            const input = this.$refs.input;
            if (! input || input.disabled || input.readOnly) return;
            input.focus({ preventScroll: true });
            if (typeof input.showPicker === 'function') {
                try { input.showPicker(); } catch (e) {}
            }
        }
    }"
>
    <input
        x-ref="input"
        type="{{ $type }}"
        {!! filled($name) ? 'name="'.e($name).'"' : '' !!}
        value="{{ $value }}"
        {!! filled($min) ? 'min="'.e($min).'"' : '' !!}
        {!! filled($max) ? 'max="'.e($max).'"' : '' !!}
        {!! $required ? 'required' : '' !!}
        {{ $attributes->class(['ui-date-input']) }}
        x-on:click="open()"
        x-on:keydown.enter.prevent="open()"
        x-on:keydown.space.prevent="open()"
    />
    <button
        type="button"
        class="ui-date-picker-btn"
        tabindex="-1"
        aria-label="Buka kalender"
        x-on:click.prevent="open()"
    >
        <flux:icon name="calendar-days" variant="micro" class="size-4" />
    </button>
</div>
