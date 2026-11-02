@props(['title' => 'Data belum dapat dimuat', 'description' => 'Periksa koneksi lalu coba kembali. Tidak ada perubahan data yang dilakukan.', 'retryLabel' => 'Coba lagi'])

<div {{ $attributes->merge(['class' => 'ui-retry-state']) }} role="status">
    <flux:icon name="exclamation-triangle" class="size-5" aria-hidden="true" />
    <div><p class="font-semibold">{{ $title }}</p><p class="mt-1 text-sm leading-6">{{ $description }}</p></div>
    @if (isset($action))<div class="mt-3">{{ $action }}</div>@else<flux:button class="mt-3" variant="ghost" icon="arrow-path">{{ $retryLabel }}</flux:button>@endif
</div>
