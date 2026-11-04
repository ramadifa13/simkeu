@props([
    'name' => 'decision',
    'noteName' => 'catatan',
    'notePlaceholder' => 'Catatan Admin (opsional)',
    'noteRequired' => false,
    'options' => [
        'disetujui' => 'Setujui',
        'ditolak' => 'Tolak',
    ],
])

<div {{ $attributes->class(['space-y-3']) }}>
    <flux:field>
        <flux:label>Keputusan <x-required-mark /></flux:label>
        <flux:select :name="$name" required>
            @foreach ($options as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>
    </flux:field>
    <x-loans.note-field
        :name="$noteName"
        :placeholder="$notePlaceholder"
        :required="$noteRequired"
    />
</div>
