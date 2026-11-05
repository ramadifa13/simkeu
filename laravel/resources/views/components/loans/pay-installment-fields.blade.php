@props([
    'role',
    'installment',
])

@php
    $pokok = (float) ($installment->pokok ?? 0);
    $bunga = (float) ($installment->bunga ?? 0);
    $denda = max(0, (float) ($installment->denda ?? 0) - (float) ($installment->denda_dibayar ?? 0));
    $tagihan = (float) ($installment->jumlah_angsuran ?? ($pokok + $bunga));
    $total = $tagihan + $denda;
    $due = filled($installment->tanggal_jatuh_tempo ?? null)
        ? \Carbon\Carbon::parse($installment->tanggal_jatuh_tempo)->translatedFormat('d M Y')
        : '—';
@endphp

<div class="ui-pay-modal space-y-4">
    <div class="ui-pay-summary" aria-label="Ringkasan angsuran">
        <div class="ui-pay-summary-head">
            <div class="min-w-0">
                <p class="ui-pay-summary-eyebrow">Angsuran ke-{{ $installment->angsuran_ke }}</p>
                <p class="ui-pay-summary-title">Jatuh tempo {{ $due }}</p>
            </div>
            <div class="ui-pay-summary-total">
                <span>Total dibayar</span>
                <strong>Rp{{ number_format($total, 0, ',', '.') }}</strong>
            </div>
        </div>
        <dl class="ui-pay-summary-grid">
            <div class="ui-pay-summary-row">
                <dt>Pokok</dt>
                <dd>Rp{{ number_format($pokok, 0, ',', '.') }}</dd>
            </div>
            <div class="ui-pay-summary-row">
                <dt>Bunga</dt>
                <dd>Rp{{ number_format($bunga, 0, ',', '.') }}</dd>
            </div>
            <div class="ui-pay-summary-row">
                <dt>Tagihan cicilan</dt>
                <dd>Rp{{ number_format($tagihan, 0, ',', '.') }}</dd>
            </div>
            <div class="ui-pay-summary-row">
                <dt>Sisa denda</dt>
                <dd @class(['text-rose-700' => $denda > 0])>Rp{{ number_format($denda, 0, ',', '.') }}</dd>
            </div>
        </dl>
        @if ($denda > 0.005)
            <p class="ui-pay-summary-note">Denda belum lunas ikut ditagihkan. Bayar denda terpisah lewat tombol Denda bila ingin dilunasi dulu.</p>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-loans.payment-date-field />
        <x-loans.payment-method />
        @if ($role === 'admin')
            <flux:field class="sm:col-span-2">
                <flux:label>
                    Denda akhir
                    <span class="text-xs font-normal text-slate-500">(kosongkan untuk perhitungan otomatis)</span>
                </flux:label>
                <flux:input type="number" name="denda" min="0" step="1" :value="(int) round((float) ($installment->denda ?? 0))" />
            </flux:field>
        @endif
        <div class="sm:col-span-2">
            <x-image-upload
                label="Bukti pembayaran (opsional)"
                name="bukti"
                description="JPG, PNG, atau WebP. Disimpan privat di server koperasi."
            />
        </div>
    </div>
</div>
