<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;margin:24px}h1{font-size:18px}.box{border:1px solid #ccc;padding:12px;margin-top:12px}</style></head><body>
<h1>{{ $cooperative->nama ?? 'Koperasi' }}</h1>
<p>Slip pencairan pinjaman</p>
<div class="box">
    <p><strong>Kode:</strong> {{ $loan->kode_pinjaman }}</p>
    <p><strong>Nasabah:</strong> {{ $loan->nama_lengkap }} ({{ $loan->kode_anggota }})</p>
    <p><strong>Jenis:</strong> {{ $loan->nama_jenis }}</p>
    <p><strong>Jumlah cair:</strong> Rp {{ number_format((float) $loan->jumlah_pinjaman, 0, ',', '.') }}</p>
    <p><strong>Tanggal:</strong> {{ $loan->tanggal_pencairan ?? $loan->created_at ?? now()->toDateString() }}</p>
</div>
</body></html>
