@props([
    'role',
    'installment',
])

<div class="grid gap-4 sm:grid-cols-2">
    <x-loans.payment-date-field />
    <x-loans.payment-method />
    @if ($role === 'admin')
        <flux:field class="sm:col-span-2">
            <flux:label>
                Denda akhir
                <span class="text-xs font-normal text-slate-500">(kosongkan untuk perhitungan otomatis)</span>
            </flux:label>
            <flux:input type="number" name="denda" min="0" step="1" :value="$installment->denda" />
        </flux:field>
    @endif
    <div class="sm:col-span-2">
        <x-image-upload
            label="Bukti pembayaran (opsional)"
            name="bukti"
            description="JPG, PNG, atau WebP disimpan privat."
        />
    </div>
</div>
