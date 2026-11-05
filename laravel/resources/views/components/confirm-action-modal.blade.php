@props([
    'name',
    'title' => 'Konfirmasi tindakan',
    'description' => 'Tindakan ini akan diproses setelah Anda mengonfirmasi.',
    'confirmLabel' => 'Konfirmasi',
    'variant' => 'primary',
    'action' => null,
    'method' => 'POST',
    'maxWidth' => 'lg',
])

@php
    $widthClass = match ($maxWidth) {
        'md' => 'max-w-md',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        default => 'max-w-lg',
    };
@endphp

<flux:modal name="{{ $name }}" class="{{ $widthClass }} ui-confirm-modal">
    <form
        {!! $action ? 'method="POST" action="'.e($action).'"' : '' !!}
        enctype="multipart/form-data"
        class="ui-confirm-modal-form"
    >
        @if ($action)
            @csrf
            @if (! in_array(strtoupper($method), ['GET', 'POST'], true))
                @method($method)
            @endif
        @endif
        <div class="ui-confirm-modal-head">
            <flux:heading size="lg" class="ui-confirm-modal-title">{{ $title }}</flux:heading>
            @if (filled($description))
                <flux:subheading class="ui-confirm-modal-desc">{{ $description }}</flux:subheading>
            @endif
        </div>
        @if (! $slot->isEmpty())
            <div class="ui-confirm-modal-body space-y-3">
                {{ $slot }}
            </div>
        @endif
        <div class="ui-confirm-modal-actions">
            <flux:modal.close>
                <flux:button type="button" variant="ghost" class="w-full sm:w-auto">Batal</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="{{ $variant }}" class="w-full sm:w-auto">{{ $confirmLabel }}</flux:button>
        </div>
    </form>
</flux:modal>
