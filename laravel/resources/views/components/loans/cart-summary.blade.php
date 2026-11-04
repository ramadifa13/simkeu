@props([
    'role',
    'grouped',
    'itemCount' => 0,
])

<aside {{ $attributes->merge(['class' => 'loan-cart-overview']) }}>
    <div class="loan-cart-overview-head">
        <span class="loan-cart-overview-icon" aria-hidden="true">
            <flux:icon name="queue-list" variant="micro" class="size-4" />
        </span>
        <div class="min-w-0">
            <p class="loan-cart-overview-title">Ringkas pilihan</p>
            <p class="loan-cart-overview-meta">{{ $itemCount }} angsuran dari {{ $grouped->count() }} pinjaman</p>
        </div>
    </div>

    <ul class="loan-cart-overview-list">
        @foreach ($grouped as $loanId => $items)
            @php
                $first = $items->first();
                $angsuranList = $items->pluck('angsuran_ke')->sort()->values();
            @endphp
            <li class="loan-cart-overview-card">
                <div class="min-w-0 flex-1">
                    <div class="loan-cart-overview-card-top">
                        <a
                            href="{{ route('portal.loans.show', ['role' => $role, 'loan' => $loanId]) }}"
                            class="loan-cart-overview-code"
                        >
                            {{ $first->kode_pinjaman }}
                        </a>
                        <span class="loan-cart-overview-count">{{ $items->count() }} item</span>
                    </div>
                    <p class="loan-cart-overview-name">{{ $first->nama_lengkap }}</p>
                    <div class="loan-cart-overview-chips">
                        @foreach ($angsuranList as $ke)
                            <span class="loan-cart-overview-chip">Ke-{{ $ke }}</span>
                        @endforeach
                    </div>
                </div>
                <a
                    href="{{ route('portal.loans.show', ['role' => $role, 'loan' => $loanId]) }}"
                    class="loan-cart-overview-link"
                    aria-label="Buka detail {{ $first->kode_pinjaman }}"
                >
                    <flux:icon name="arrow-right" variant="micro" class="size-4" />
                </a>
            </li>
        @endforeach
    </ul>
</aside>
