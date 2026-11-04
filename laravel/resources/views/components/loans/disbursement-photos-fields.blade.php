@props([
    'requiresCollateral' => false,
    'collateralOptional' => true,
])

<div {{ $attributes->class(['space-y-4']) }}>
    <x-image-upload
        label="Foto nasabah menerima uang"
        name="foto_nasabah"
        :required="true"
        description="JPG, PNG, atau WebP; otomatis dikompres maksimal 1 MB. Disimpan privat."
    />
    @if ($requiresCollateral)
        <x-image-upload
            label="Foto barang jaminan{{ $collateralOptional ? ' (opsional)' : '' }}"
            name="foto_barang_jaminan"
            :required="! $collateralOptional"
            description="JPG, PNG, atau WebP; otomatis dikompres maksimal 1 MB. Disimpan privat."
        />
        <x-image-upload
            label="Foto nasabah & barang{{ $collateralOptional ? ' (opsional)' : '' }}"
            name="foto_nasabah_barang"
            :required="! $collateralOptional"
            description="JPG, PNG, atau WebP; otomatis dikompres maksimal 1 MB. Disimpan privat."
        />
    @endif
</div>
