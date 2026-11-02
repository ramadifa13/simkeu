@props(['title' => 'Koneksi terputus', 'description' => 'Simpan otomatis ditunda. Data yang sudah tersimpan tidak akan dikirim ulang tanpa validasi.'])

<aside {{ $attributes->merge(['class' => 'ui-offline-banner']) }} role="status">
    <div><p class="font-semibold text-slate-900">{{ $title }}</p><p class="mt-1 text-sm text-slate-600">{{ $description }}</p></div>
    @if (isset($action))<div class="shrink-0">{{ $action }}</div>@endif
</aside>
