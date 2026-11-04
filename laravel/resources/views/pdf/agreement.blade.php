<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;margin:24px}h1{font-size:18px;margin:0 0 8px}.muted{color:#555}.box{border:1px solid #ccc;padding:12px;margin-top:12px}</style></head><body>
<h1>{{ $cooperative->nama ?? 'Koperasi' }}</h1>
<p class="muted">Perjanjian pinjaman</p>
<div class="box">
    <p><strong>Nomor:</strong> {{ $agreement->nomor_perjanjian ?? '-' }}</p>
    <p><strong>Nasabah:</strong> {{ $loan->nama_lengkap }} ({{ $loan->kode_anggota }})</p>
    <p><strong>Kode pinjaman:</strong> {{ $loan->kode_pinjaman }}</p>
    <p><strong>Jenis:</strong> {{ $loan->nama_jenis }}</p>
    <p><strong>Pokok:</strong> Rp {{ number_format((float) $loan->jumlah_pinjaman, 0, ',', '.') }}</p>
    <p><strong>Versi:</strong> {{ $agreement->versi ?? '-' }}</p>
    <p><strong>Ditandatangani:</strong> {{ $agreement->ditandatangani_at ?? '-' }}</p>
</div>
</body></html>
