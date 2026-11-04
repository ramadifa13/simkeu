@props([
    'installment',
])

@php
    $remaining = max(0, (float) $installment->denda - (float) ($installment->denda_dibayar ?? 0));
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <x-loans.payment-date-field />
    <x-loans.payment-method />
    <flux:field class="sm:col-span-2">
        <flux:label>Nominal denda <x-required-mark /></flux:label>
        <flux:input
            type="number"
            name="jumlah"
            min="1"
            step="1"
            :max="$remaining"
            :value="$remaining"
            required
        />
    </flux:field>
    <div class="sm:col-span-2">
        <x-image-upload
            label="Bukti pembayaran denda"
            name="bukti"
            :required="true"
            description="Wajib. JPG, PNG, atau WebP; otomatis dikompres maksimal 1 MB dan disimpan privat."
        />
    </div>
</div>
