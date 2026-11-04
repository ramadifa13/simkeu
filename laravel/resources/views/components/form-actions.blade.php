@props([
    'note' => null,
    'cancelHref' => null,
    'cancelLabel' => 'Batal',
    'submitLabel' => 'Simpan',
    'submitIcon' => null,
])

<footer {{ $attributes->merge(['class' => 'ui-form-actions']) }}>
    @if ($note)
        <p class="ui-form-actions-note">{{ $note }}</p>
    @endif
    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        @if ($cancelHref)
            <a href="{{ $cancelHref }}" class="ui-form-cancel w-full text-center sm:w-auto">{{ $cancelLabel }}</a>
        @endif
        @if (isset($actions))
            {{ $actions }}
        @else
            <flux:button type="submit" variant="primary" :icon="$submitIcon" class="w-full sm:w-auto">{{ $submitLabel }}</flux:button>
        @endif
    </div>
</footer>
