@props([
    'role',
    'count' => 0,
    'labels' => [],
    'loanId' => null,
    'onThisLoanCount' => 0,
])

@if ($count > 0 && $role !== 'anggota')
    @php
        $otherCount = max(0, (int) $count - (int) $onThisLoanCount);
        $preview = count($labels)
            ? 'Angsuran ke-'.implode(', ', $labels)
            : 'Item dari pinjaman lain';
        if ($otherCount > 0 && count($labels)) {
            $preview .= ' · +'.$otherCount.' pinjaman lain';
        } elseif ($otherCount > 0) {
            $preview = $otherCount.' item dari pinjaman lain';
        }
    @endphp
    <aside {{ $attributes->merge(['class' => 'loan-cart-bar']) }} aria-live="polite">
        <div class="loan-cart-bar-inner">
            <div class="loan-cart-bar-lead">
                <span class="loan-cart-bar-icon" aria-hidden="true">
                    <flux:icon name="shopping-cart" class="size-5" />
                </span>
                <div class="min-w-0">
                    <p class="loan-cart-bar-title">
                        Keranjang angsuran
                        <span class="loan-cart-bar-count">{{ $count }}</span>
                    </p>
                    <p class="loan-cart-bar-meta">{{ $preview }}</p>
                </div>
            </div>
            <div class="loan-cart-bar-actions">
                <x-loans.action-chip
                    :href="route('portal.loans.cart', array_filter(['role' => $role, 'from' => $loanId]))"
                    tone="primary"
                    icon="arrow-right"
                >
                    Buka keranjang
                </x-loans.action-chip>
            </div>
        </div>
    </aside>
@endif
