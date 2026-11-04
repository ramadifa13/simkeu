@props([
    'tone' => 'default',
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'disabled' => false,
])

@php
    // Use `tone` (not `variant`) so Flux icons do not inherit an invalid icon variant via Blaze scope.
    $classes = match ($tone) {
        'primary' => 'loan-action-chip is-primary',
        'muted' => 'loan-action-chip is-muted',
        'danger' => 'loan-action-chip is-danger',
        'ghost' => 'loan-action-chip is-ghost',
        default => 'loan-action-chip',
    };
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class([$classes, 'is-disabled' => $disabled]) }}
        {!! $disabled ? 'aria-disabled="true" tabindex="-1"' : '' !!}
    >
        @if ($icon)
            <flux:icon :name="$icon" variant="micro" class="size-3.5 shrink-0" />
        @endif
        <span>{{ $slot }}</span>
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->class([$classes]) }}
        @disabled($disabled)
    >
        @if ($icon)
            <flux:icon :name="$icon" variant="micro" class="size-3.5 shrink-0" />
        @endif
        <span>{{ $slot }}</span>
    </button>
@endif
