@props([
    'name' => 'anggota_id',
    'label' => 'Anggota',
    'members' => [],
    'value' => '',
    'required' => false,
])

<x-autocomplete
    :label="$label"
    :name="$name"
    :options="$members"
    :value="$value"
    :required="$required"
    placeholder="Cari kode atau nama anggota…"
    {{ $attributes }}
/>
