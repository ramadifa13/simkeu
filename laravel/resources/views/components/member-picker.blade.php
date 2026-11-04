@props([
    'name' => 'anggota_id',
    'label' => 'Anggota',
    'members' => [],
    'options' => null,
    'value' => '',
    'required' => false,
])

@php
    $choices = $options ?? collect($members)
        ->mapWithKeys(function ($member) {
            $label = $member->kode_anggota.' — '.$member->nama_lengkap;
            if (($member->status ?? 'aktif') === 'nonaktif') {
                $label .= ' (nonaktif · akan diaktifkan kembali)';
            }

            return [$member->id => $label];
        })
        ->all();
    $selected = old($name, $value);
@endphp

<x-autocomplete
    :label="$label"
    :name="$name"
    :options="$choices"
    :value="$selected"
    :required="$required"
    placeholder="Cari kode atau nama anggota…"
    {{ $attributes }}
/>
