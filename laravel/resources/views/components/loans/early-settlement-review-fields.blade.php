<div class="grid gap-3">
    <flux:field>
        <flux:label>Keputusan <x-required-mark /></flux:label>
        <flux:select name="decision" required>
            <option value="disetujui">Setujui</option>
            <option value="ditolak">Tolak</option>
        </flux:select>
    </flux:field>
    <flux:field>
        <flux:label>Diskon bunga (%)</flux:label>
        <flux:input type="number" name="diskon" min="0" max="100" step="0.01" placeholder="Diskon bunga (%)" />
    </flux:field>
    <x-loans.note-field
        name="catatan"
        rows="2"
        placeholder="Catatan Admin"
    />
</div>
