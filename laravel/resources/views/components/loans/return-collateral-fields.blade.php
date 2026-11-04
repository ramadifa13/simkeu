<div class="space-y-4">
    <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
        <input
            type="checkbox"
            name="cek_fisik"
            value="1"
            class="mt-1 rounded border-slate-300 text-teal-700 focus:ring-teal-600"
            required
        >
        <span>Fisik barang jaminan sudah diperiksa bersama nasabah sebelum diserahkan.</span>
    </label>
    <x-image-upload
        label="Foto penyerahan barang"
        name="foto"
        :required="true"
        description="JPG, PNG, atau WebP. Otomatis dikompres maksimal 1 MB."
    />
    <x-signature-field label="Tanda tangan nasabah *" name="ttd_nasabah" />
    <x-signature-field label="Tanda tangan teller *" name="ttd_teller" />
    <x-loans.note-field
        name="catatan"
        rows="2"
        placeholder="Catatan kondisi barang (opsional)"
    />
</div>
