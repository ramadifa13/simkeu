<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;margin:24px}h1{font-size:16px;text-align:center;text-decoration:underline}.box{border:1px solid #ccc;padding:12px;margin-top:12px}.muted{color:#555}</style></head><body>
<h1>DRAFT SURAT PERJANJIAN HUTANG PIUTANG</h1>
<p class="muted" style="text-align:center">{{ $cooperative->nama ?? 'Koperasi' }} · {{ $application->kode_pengajuan }}</p>
<div class="box">
    <p><strong>Nasabah:</strong> {{ $application->nama_lengkap }} ({{ $application->kode_anggota }})</p>
    <p><strong>NIK:</strong> {{ $application->ktp }}</p>
    <p><strong>Alamat:</strong> {{ $application->alamat }}</p>
    <p><strong>Telepon:</strong> {{ $application->telepon }}</p>
    <p><strong>Jenis pinjaman:</strong> {{ $application->nama_jenis }}</p>
    <p><strong>Pokok:</strong> Rp {{ number_format((float) $application->jumlah_pinjaman, 0, ',', '.') }}</p>
    <p><strong>Tenor:</strong> {{ $application->tenor }} bulan · {{ $application->frekuensi }}</p>
    @if($application->memerlukan_jaminan)
        <p><strong>Jaminan:</strong> {{ $application->jenis_barang ?: '-' }} · {{ $application->identitas_barang }}</p>
        <p>{{ $application->deskripsi_barang }}</p>
    @endif
</div>
<p class="muted">Dokumen draft sebelum pencairan. Versi final tersedia setelah pinjaman aktif.</p>
</body></html>
