@props(['role', 'active' => 'applications', 'cartCount' => null])

@php
    $resolvedCartCount = $cartCount ?? (in_array($role, ['admin', 'teller'], true) ? count(session('loan_installment_cart', [])) : 0);
    $steps = [
        ['key' => 'applications', 'label' => 'Pengajuan', 'icon' => 'document-plus', 'route' => 'portal.loans.applications.index', 'params' => []],
        ['key' => 'review', 'label' => 'Persetujuan', 'icon' => 'clipboard-document-check', 'route' => 'portal.loans.applications.index', 'params' => ['status' => 'menunggu']],
        ['key' => 'disbursement', 'label' => 'Pencairan', 'icon' => 'banknotes', 'route' => 'portal.loans.applications.index', 'params' => ['status' => 'disetujui']],
        ['key' => 'installments', 'label' => 'Angsuran', 'icon' => 'calendar-days', 'route' => 'portal.loans.index', 'params' => ['status' => 'aktif']],
        ['key' => 'cart', 'label' => 'Keranjang', 'icon' => 'shopping-cart', 'route' => 'portal.loans.cart', 'params' => [], 'badge' => $resolvedCartCount],
        ['key' => 'penalties', 'label' => 'Denda', 'icon' => 'exclamation-triangle', 'route' => 'portal.loans.penalties', 'params' => []],
        ['key' => 'settlement', 'label' => 'Pelunasan', 'icon' => 'check-badge', 'route' => 'portal.loans.settlements', 'params' => []],
        ['key' => 'closing', 'label' => 'Penutupan', 'icon' => 'lock-closed', 'route' => 'portal.loans.closing.index', 'params' => []],
    ];
    if ($role !== 'admin') {
        $steps = array_values(array_filter($steps, fn (array $step): bool => $step['key'] !== 'closing'));
    }
    if ($role === 'anggota') {
        $steps = array_values(array_filter($steps, fn (array $step): bool => $step['key'] === 'installments'));
    }
@endphp

<nav {{ $attributes->merge(['class' => 'loan-workflow']) }} aria-label="Alur kerja pinjaman">
    <ol class="loan-workflow-track">
        @foreach ($steps as $step)
            @php($isActive = $step['key'] === $active)
            <li class="loan-workflow-item">
                <a
                    href="{{ route($step['route'], ['role' => $role, ...$step['params']]) }}"
                    @class([
                        'loan-workflow-link',
                        'is-active' => $isActive,
                    ])
                    {!! $isActive ? 'aria-current="step"' : '' !!}
                >
                    <flux:icon :name="$step['icon']" variant="micro" class="size-4 shrink-0" />
                    <span>{{ $step['label'] }}</span>
                    @if (($step['badge'] ?? 0) > 0)
                        <span @class([
                            'loan-workflow-badge',
                            'is-active' => $isActive,
                        ])>{{ $step['badge'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ol>
</nav>
