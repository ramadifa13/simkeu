@props(['name', 'title', 'description' => null, 'maxWidth' => 'xl'])

@php
    $widthClass = match ($maxWidth) {
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        '2xl' => 'max-w-2xl',
        default => 'max-w-xl',
    };
@endphp

<flux:modal name="{{ $name }}" class="{{ $widthClass }} ui-confirm-modal">
    <div class="ui-confirm-modal-form">
        <div class="ui-confirm-modal-head">
            <flux:heading size="lg" class="ui-confirm-modal-title">{{ $title }}</flux:heading>
            @if ($description)
                <flux:subheading class="ui-confirm-modal-desc">{{ $description }}</flux:subheading>
            @endif
        </div>
        <div class="ui-confirm-modal-body space-y-3">
            {{ $slot }}
        </div>
    </div>
</flux:modal>
