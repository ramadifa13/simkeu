@props([
    'role',
    'row',
    'dateValue',
    'methodValue',
    'penaltyValue' => null,
    'hasBlock' => false,
    'residualPenalty' => 0,
])

<article {{ $attributes->class([
    'loan-cart-item',
    'is-blocked' => $hasBlock,
]) }}>
    <div class="loan-cart-item-head">
        <div class="min-w-0">
            <div class="loan-cart-item-kicker">
                <span class="loan-cart-item-code">{{ $row->kode_pinjaman }}</span>
                <span aria-hidden="true">·</span>
                <span>Angsuran ke-{{ $row->angsuran_ke }}</span>
            </div>
            <p class="loan-cart-item-title">{{ $row->nama_lengkap }}</p>
            <p class="loan-cart-item-meta">
                Jatuh tempo {{ \Carbon\Carbon::parse($row->tanggal_jatuh_tempo)->translatedFormat('d M Y') }}
                · <x-money :amount="$row->jumlah_angsuran" />
            </p>
            @if ($hasBlock)
                <p class="loan-cart-item-alert">
                    Sisa denda Rp {{ number_format($residualPenalty, 0, ',', '.') }} — bayar denda dulu
                </p>
            @elseif ((float) $row->denda > 0)
                <p class="loan-cart-item-ok">Denda sudah dilunasi / tidak menghalangi checkout</p>
            @endif
        </div>

        <div class="loan-installment-actions">
            <x-loans.action-chip
                :href="route('portal.loans.show', ['role' => $role, 'loan' => $row->pinjaman_id])"
                icon="eye"
            >
                Detail
            </x-loans.action-chip>
            <x-loans.action-chip
                type="submit"
                form="remove-cart-{{ $row->id }}"
                tone="danger"
                icon="trash"
            >
                Hapus
            </x-loans.action-chip>
        </div>
    </div>

    <div class="loan-cart-item-grid">
        <x-loans.payment-date-field
            name="items[{{ $row->id }}][tanggal_bayar]"
            :value="$dateValue"
            data-cart-date
        />
        <flux:field>
            <flux:label>Metode <x-required-mark /></flux:label>
            <select
                name="items[{{ $row->id }}][metode]"
                data-cart-method
                required
                class="loan-cart-field"
            >
                <option value="cash" @selected($methodValue === 'cash')>Tunai</option>
                <option value="transfer" @selected($methodValue === 'transfer')>Transfer</option>
            </select>
        </flux:field>
        @if ($role === 'admin')
            <flux:field>
                <flux:label>Denda akhir</flux:label>
                <input
                    type="number"
                    name="items[{{ $row->id }}][denda]"
                    min="0"
                    step="1"
                    value="{{ $penaltyValue }}"
                    class="loan-cart-field"
                >
            </flux:field>
        @endif
        <div class="loan-cart-item-subtotal">
            <p class="loan-cart-item-subtotal-label">Subtotal cicilan</p>
            <p class="loan-cart-item-subtotal-value"><x-money :amount="$row->jumlah_angsuran" /></p>
        </div>
    </div>
</article>
