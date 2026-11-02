@props(['paginator', 'perPageModel' => null])

@if ($paginator->hasPages())
    <nav {{ $attributes->merge(['class' => 'ui-pagination']) }} aria-label="Navigasi halaman">
        <p class="ui-pagination-meta">Menampilkan {{ $paginator->firstItem() }}-{{ $paginator->lastItem() }} dari {{ $paginator->total() }} data</p>
        <div class="flex flex-wrap items-center gap-3">
            @if ($perPageModel)
                <label class="ui-pagination-per-page">Baris
                    <select wire:model.live="{{ $perPageModel }}" aria-label="Jumlah baris per halaman">
                        @foreach ([5, 10, 25, 50] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach
                    </select>
                </label>
            @endif
            <div class="ui-pagination-controls">
                <button type="button" class="ui-pagination-button" wire:click="gotoPage(1)" @disabled($paginator->onFirstPage()) aria-label="Halaman pertama">«</button>
                <button type="button" class="ui-pagination-button" wire:click="previousPage" @disabled($paginator->onFirstPage()) aria-label="Halaman sebelumnya">‹</button>
                @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 1), min($paginator->lastPage(), $paginator->currentPage() + 1)) as $page => $url)
                    <button type="button" class="ui-pagination-button" wire:click="gotoPage({{ $page }})" @if($page === $paginator->currentPage()) aria-current="page" @endif aria-label="Halaman {{ $page }}">{{ $page }}</button>
                @endforeach
                <button type="button" class="ui-pagination-button" wire:click="nextPage" @disabled(!$paginator->hasMorePages()) aria-label="Halaman berikutnya">›</button>
                <button type="button" class="ui-pagination-button" wire:click="gotoPage({{ $paginator->lastPage() }})" @disabled(!$paginator->hasMorePages()) aria-label="Halaman terakhir">»</button>
            </div>
        </div>
    </nav>
@elseif ($perPageModel && $paginator->total() > 0)
    <div {{ $attributes->merge(['class' => 'ui-pagination']) }}>
        <p class="ui-pagination-meta">{{ $paginator->total() }} data ditemukan</p>
        <label class="ui-pagination-per-page">Baris <select wire:model.live="{{ $perPageModel }}" aria-label="Jumlah baris per halaman">@foreach ([5, 10, 25, 50] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</select></label>
    </div>
@endif
