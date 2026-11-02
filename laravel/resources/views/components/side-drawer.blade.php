@props(['name', 'title', 'description' => null])

<flux:modal name="{{ $name }}" variant="flyout" position="right"><div class="flex h-full flex-col"><div class="flex items-start justify-between gap-3"><div><flux:heading size="lg">{{ $title }}</flux:heading>@if($description)<flux:subheading>{{ $description }}</flux:subheading>@endif</div><flux:modal.close><flux:button variant="ghost" square icon="x-mark" aria-label="Tutup" /></flux:modal.close></div><div class="mt-6">{{ $slot }}</div></div></flux:modal>
