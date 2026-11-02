@props([
    'name',
    'title' => 'Konfirmasi tindakan',
    'description' => 'Tindakan ini akan diproses setelah Anda mengonfirmasi.',
    'confirmLabel' => 'Konfirmasi',
    'variant' => 'primary',
])

<flux:modal name="{{ $name }}" class="max-w-lg">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            <flux:subheading>{{ $description }}</flux:subheading>
        </div>
        {{ $slot }}
        <div class="flex justify-end gap-3">
            <flux:modal.close><flux:button type="button" variant="ghost">Batal</flux:button></flux:modal.close>
            <flux:button type="submit" variant="{{ $variant }}">{{ $confirmLabel }}</flux:button>
        </div>
    </div>
</flux:modal>
