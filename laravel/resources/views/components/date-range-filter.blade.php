@props(['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => '', 'toValue' => ''])

<div {{ $attributes->merge(['class' => 'grid gap-3 sm:grid-cols-2']) }}>
    <flux:field>
        <flux:label>Dari tanggal</flux:label>
        <flux:input type="date" name="{{ $fromName }}" value="{{ $fromValue }}" />
    </flux:field>
    <flux:field>
        <flux:label>Sampai tanggal</flux:label>
        <flux:input type="date" name="{{ $toName }}" value="{{ $toValue }}" />
    </flux:field>
</div>
