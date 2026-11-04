<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;margin:24px}h1{font-size:18px}.box{border:1px solid #ccc;padding:12px;margin-top:12px}.total{font-size:16px;font-weight:bold}</style></head><body>
<h1>{{ $cooperative->nama ?? 'Koperasi' }}</h1>
<p>Bukti pembayaran angsuran</p>
<div class="box">
    <p><strong>Nasabah:</strong> {{ $loan->nama_lengkap }}</p>
    <p><strong>Pinjaman:</strong> {{ $loan->kode_pinjaman }}</p>
    <p><strong>Angsuran ke:</strong> {{ $payment->angsuran_ke }}</p>
    <p><strong>Tanggal bayar:</strong> {{ $payment->tanggal_bayar ?? '-' }}</p>
    <p><strong>Pokok + bunga:</strong> Rp {{ number_format((float) $payment->jumlah_angsuran, 0, ',', '.') }}</p>
    <p><strong>Denda:</strong> Rp {{ number_format((float) $payment->denda, 0, ',', '.') }}</p>
    <p class="total">Total: Rp {{ number_format((float) $payment->jumlah_angsuran + (float) $payment->denda, 0, ',', '.') }}</p>
</div>
</body></html>
