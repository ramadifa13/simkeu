<div class="space-y-4">
    <x-loans.note-field
        name="alasan"
        label="Alasan pengajuan"
        placeholder="Alasan pengajuan (minimal 5 karakter)"
        :required="true"
        :minlength="5"
    />
    <x-image-upload
        label="Bukti jaminan"
        name="bukti_jaminan"
        :required="true"
        description="JPG, PNG, atau WebP. Otomatis dikompres maksimal 1 MB."
    />
    <x-image-upload
        label="Bukti lunas"
        name="bukti_lunas"
        :required="true"
        description="JPG, PNG, atau WebP. Otomatis dikompres maksimal 1 MB."
    />
</div>
