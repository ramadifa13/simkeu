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

<flux:modal name="{{ $name }}" class="{{ $widthClass }}">
    <form {!! $action ? 'method="POST" action="'.e($action).'"' : '' !!} enctype="multipart/form-data" class="space-y-4">
        @if ($action)
            @csrf
            @if (! in_array(strtoupper($method), ['GET', 'POST'], true))
                @method($method)
            @endif
        @endif
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            <flux:subheading>{{ $description }}</flux:subheading>
        </div>
        {{ $slot }}
        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <flux:modal.close>
                <flux:button type="button" variant="ghost" class="w-full sm:w-auto">Batal</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="{{ $variant }}" class="w-full sm:w-auto">{{ $confirmLabel }}</flux:button>
        </div>
    </form>
</flux:modal>
