<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;margin:24px}h1{font-size:18px}.box{border:1px solid #ccc;padding:12px;margin-top:12px}</style></head><body>
<h1>{{ $cooperative->nama ?? 'Koperasi' }}</h1>
<p>Kwitansi pembayaran SHU</p>
<div class="box">
    <p><strong>Periode:</strong> {{ $row->nama_periode }}</p>
    <p><strong>Penerima:</strong> {{ $row->nama_snapshot }} ({{ $row->kode_snapshot }})</p>
    <p><strong>Jumlah:</strong> Rp {{ number_format((float) $row->total_shu, 0, ',', '.') }}</p>
    <p><strong>Status:</strong> {{ $row->status_bayar }}</p>
    <p><strong>Metode:</strong> {{ $row->metode ?? '-' }}</p>
    <p><strong>Referensi:</strong> {{ $row->referensi ?? '-' }}</p>
    <p><strong>Dibayar:</strong> {{ $row->dibayar_at ?? $row->tanggal_bayar ?? '-' }}</p>
</div>
</body></html>
