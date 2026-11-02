@props(['status'])

@php
    $normalized = str_replace('_', ' ', strtolower((string) $status));
    $colors = match ($normalized) {
        'aktif', 'disetujui', 'lunas', 'sudah', 'dibayar', 'berhasil' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'draft', 'menunggu', 'belum', 'pending' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'terlambat', 'jatuh tempo', 'overdue' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
        'macet', 'ditolak', 'gagal', 'dibatalkan', 'batal', 'nonaktif' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
        default => 'bg-slate-100 text-slate-700 ring-slate-600/20',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {$colors}"]) }}>{{ ucfirst($normalized) }}</span>
