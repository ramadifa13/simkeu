@props(['count' => 0, 'columns' => []])

<div {{ $attributes->merge(['class' => 'ui-bulk-action-bar']) }}>
    <div class="flex items-center gap-3"><flux:checkbox aria-label="Pilih seluruh data" /><span><strong>{{ $count }} data dipilih</strong><span class="hidden sm:inline"> · aksi massal muncul hanya setelah ada pilihan</span></span></div>
    <div class="flex flex-wrap gap-2">{{ $slot }}</div>
</div>
@if (count($columns))
    <div class="ui-column-preview"><span>Kolom tampil:</span>@foreach ($columns as $column => $checked)<label><input type="checkbox" @checked($checked)> {{ $column }}</label>@endforeach</div>
@endif
