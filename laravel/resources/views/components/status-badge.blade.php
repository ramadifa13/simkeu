@props(['status'])

@php
    $normalized = str_replace('_', ' ', strtolower((string) $status));
    $colors = match ($normalized) {
        'aktif', 'disetujui', 'lunas', 'sudah', 'dibayar', 'berhasil', 'masuk', 'sesuai' => 'bg-emerald-100 text-emerald-800 ring-emerald-500/30',
        'draft', 'menunggu', 'belum', 'pending', 'perlu ditinjau' => 'bg-amber-100 text-amber-800 ring-amber-500/30',
        'terlambat', 'jatuh tempo', 'overdue' => 'bg-orange-100 text-orange-800 ring-orange-500/30',
        'macet', 'ditolak', 'gagal', 'dibatalkan', 'batal', 'nonaktif', 'keluar' => 'bg-rose-100 text-rose-800 ring-rose-500/30',
        default => 'bg-sky-100 text-sky-800 ring-sky-500/25',
    };
@endphp

<span {{ $attributes->merge(['class' => "ui-status-badge inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {$colors}"]) }}>{{ ucfirst($normalized) }}</span>
