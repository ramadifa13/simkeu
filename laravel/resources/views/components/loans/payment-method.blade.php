@props([
    'name' => 'metode',
    'value' => 'cash',
    'label' => 'Metode pembayaran',
    'required' => true,
])

<flux:field>
    <flux:label>
        {{ $label }}
        @if ($required)
            <x-required-mark />
        @endif
    </flux:label>
    <flux:select :name="$name" :required="$required" {{ $attributes }}>
        <option value="cash" @selected($value === 'cash')>Tunai</option>
        <option value="transfer" @selected($value === 'transfer')>Transfer</option>
    </flux:select>
</flux:field>
