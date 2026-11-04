@props([
    'returnStatus' => 'menunggu',
])

<div class="space-y-3">
    <flux:field>
        <flux:label>Keputusan <x-required-mark /></flux:label>
        <flux:select name="decision" required>
            @if ($returnStatus === 'menunggu')
                <option value="pengecekan">Tandai sedang dicek</option>
            @endif
            <option value="disetujui">Setujui</option>
            <option value="ditolak">Tolak</option>
        </flux:select>
    </flux:field>
    <x-loans.note-field
        name="catatan_admin"
        placeholder="Catatan Admin (minimal 5 karakter)"
        :required="true"
        :minlength="5"
    />
</div>
