<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;margin:24px}h1{font-size:18px}.box{border:1px solid #ccc;padding:12px;margin-top:12px}</style></head><body>
<h1>{{ $cooperative->nama ?? 'Koperasi' }}</h1>
<p>Bukti pelunasan pinjaman</p>
<div class="box">
    <p><strong>Nasabah:</strong> {{ $loan->nama_lengkap }}</p>
    <p><strong>Pinjaman:</strong> {{ $loan->kode_pinjaman }}</p>
    <p><strong>Status:</strong> {{ $loan->status }}</p>
    <p><strong>Nominal pelunasan:</strong> Rp {{ number_format((float) ($settlement->jumlah ?? $loan->jumlah_pinjaman), 0, ',', '.') }}</p>
    <p><strong>Tanggal:</strong> {{ $settlement->created_at ?? $loan->tanggal_lunas ?? '-' }}</p>
</div>
</body></html>
