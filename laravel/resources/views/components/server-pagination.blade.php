@props(['paginator', 'perPageName' => 'per_page', 'query' => []])

<nav {{ $attributes->merge(['class' => 'ui-pagination']) }} aria-label="Navigasi halaman">
    <p class="ui-pagination-meta">Menampilkan {{ $paginator->firstItem() ?? 0 }}-{{ $paginator->lastItem() ?? 0 }} dari {{ $paginator->total() }} data</p>
    <div class="flex flex-wrap items-center gap-3">
        <form method="GET" class="ui-pagination-per-page">
            @foreach ($query as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <label>Baris
                <select name="{{ $perPageName }}" onchange="this.form.submit()" aria-label="Jumlah baris per halaman">
                    @foreach ([5, 10, 25, 50] as $size)<option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>@endforeach
                </select>
            </label>
        </form>
        @if ($paginator->hasPages())
            <div class="ui-pagination-controls">
                <a href="{{ $paginator->url(1) }}" class="ui-pagination-button" @if($paginator->onFirstPage()) aria-disabled="true" tabindex="-1" @endif aria-label="Halaman pertama">&laquo;</a>
                <a href="{{ $paginator->previousPageUrl() ?: '#' }}" class="ui-pagination-button" @if($paginator->onFirstPage()) aria-disabled="true" tabindex="-1" @endif aria-label="Halaman sebelumnya">&lsaquo;</a>
                @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 1), min($paginator->lastPage(), $paginator->currentPage() + 1)) as $page => $url)
                    <a href="{{ $url }}" class="ui-pagination-button" @if($page === $paginator->currentPage()) aria-current="page" @endif aria-label="Halaman {{ $page }}">{{ $page }}</a>
                @endforeach
                <a href="{{ $paginator->nextPageUrl() ?: '#' }}" class="ui-pagination-button" @if(!$paginator->hasMorePages()) aria-disabled="true" tabindex="-1" @endif aria-label="Halaman berikutnya">&rsaquo;</a>
                <a href="{{ $paginator->url($paginator->lastPage()) }}" class="ui-pagination-button" @if(!$paginator->hasMorePages()) aria-disabled="true" tabindex="-1" @endif aria-label="Halaman terakhir">&raquo;</a>
            </div>
        @endif
    </div>
</nav>
