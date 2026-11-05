@props([
    'loan',
    'collateral' => null,
    'earlyApproved' => false,
])

@php
    $canTakeCollateral = $collateral
        && $collateral->status_jaminan === 'dititipkan'
        && (bool) $loan->memerlukan_jaminan;
    $requiresProof = (bool) $earlyApproved;
    $requiresPhysicalCheck = $requiresProof && (bool) $loan->memerlukan_jaminan;
@endphp

<div class="grid gap-4 sm:grid-cols-2" x-data="{ takeCollateral: false }">
    <x-loans.payment-date-field name="tanggal_bayar" label="Tanggal pelunasan" />
    <x-loans.payment-method />
    <div class="sm:col-span-2">
        <x-loans.note-field
            name="catatan"
            label="Catatan"
            rows="2"
            placeholder="Catatan pelunasan (opsional)"
        />
    </div>
    <div class="sm:col-span-2">
        <x-image-upload
            :label="$requiresProof ? 'Bukti pelunasan' : 'Bukti pelunasan (opsional)'"
            name="bukti"
            :required="$requiresProof"
            description="JPG, PNG, atau WebP disimpan privat.{{ $requiresProof ? ' Wajib untuk pelunasan dipercepat yang sudah disetujui.' : '' }}"
        />
    </div>
    @if ($requiresPhysicalCheck)
        <label class="sm:col-span-2 flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
            <input
                type="checkbox"
                name="cek_fisik"
                value="1"
                class="mt-1 rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                required
            >
            <span>Verifikasi fisik jaminan sudah dilakukan sebelum pelunasan dipercepat dicatat.</span>
        </label>
    @endif
    @if ($canTakeCollateral && ! $requiresProof)
        <label class="sm:col-span-2 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
            <input
                type="checkbox"
                name="ambil_jaminan_langsung"
                value="1"
                class="mt-1 rounded border-amber-300 text-teal-700 focus:ring-teal-600"
                x-model="takeCollateral"
            >
            <span>
                <span class="font-semibold">Barang jaminan diambil sekarang</span>
                <span class="mt-1 block text-amber-900/80">TTD, cek kondisi, dan foto penyerahan wajib jika dicentang (BAST otomatis).</span>
            </span>
        </label>
        <div class="sm:col-span-2 space-y-4 rounded-xl border border-slate-200 p-4" x-show="takeCollateral" x-cloak>
            <label class="flex items-start gap-3 text-sm text-slate-700">
                <input
                    type="checkbox"
                    name="cek_jaminan_nasabah"
                    value="1"
                    class="mt-1 rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                    x-bind:required="takeCollateral"
                >
                <span>
                    <span class="font-semibold">Barang sudah dicek</span>
                    <span class="mt-1 block text-slate-500">Kondisi normal / tidak rusak saat diserahkan ke nasabah.</span>
                </span>
            </label>
            <x-image-upload
                label="Foto penyerahan barang"
                name="foto_serah_terima_jaminan"
                description="Wajib jika mengambil jaminan langsung."
                x-bind:required="takeCollateral"
            />
            <x-signature-field
                label="Tanda tangan nasabah (pemeriksaan jaminan)"
                name="ttd_jaminan_nasabah"
                x-bind:data-required="takeCollateral ? '1' : '0'"
            />
            <x-signature-field
                label="Tanda tangan teller (serah terima)"
                name="ttd_jaminan_teller"
                x-bind:data-required="takeCollateral ? '1' : '0'"
            />
            <fieldset class="space-y-2 rounded-xl border border-slate-200 p-3">
                <legend class="px-1 text-sm font-semibold text-slate-800">Cetak akad serah terima</legend>
                <label class="flex items-start gap-3 text-sm text-slate-700">
                    <input type="radio" name="mode_cetak_akad" value="otomatis" class="mt-1 text-teal-700 focus:ring-teal-600" checked>
                    <span><span class="font-semibold">Otomatis</span> — buka bukti lunas setelah disimpan; BAST PDF tersedia setelah jaminan diserahkan.</span>
                </label>
                <label class="flex items-start gap-3 text-sm text-slate-700">
                    <input type="radio" name="mode_cetak_akad" value="manual" class="mt-1 text-teal-700 focus:ring-teal-600">
                    <span><span class="font-semibold">Manual</span> — lanjut tanpa membuka cetak sekarang.</span>
                </label>
            </fieldset>
        </div>
    @endif
</div>
