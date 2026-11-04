<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;margin:24px}h1{font-size:16px;text-align:center}.box{border:1px solid #ccc;padding:12px;margin-top:12px}.muted{color:#555;white-space:pre-wrap}</style></head><body>
<h1>BERITA ACARA SERAH TERIMA JAMINAN</h1>
<p style="text-align:center">{{ $cooperative->nama ?? 'Koperasi' }}</p>
<div class="box">
    <p><strong>Pinjaman:</strong> {{ $loan->kode_pinjaman }}</p>
    <p><strong>Nasabah:</strong> {{ $loan->nama_lengkap }} ({{ $loan->kode_anggota }})</p>
    <p><strong>Status BAST:</strong> {{ $bast->status }}</p>
    <p><strong>Diserahkan:</strong> {{ $bast->diserahkan_at ?? $bast->updated_at ?? $bast->created_at ?? '-' }}</p>
    @if($collateral)
        <p><strong>Barang:</strong> {{ $collateral->jenis_barang ?? '-' }} · {{ $collateral->identitas_barang ?? '-' }}</p>
        <p>{{ $collateral->deskripsi_barang ?? '' }}</p>
    @endif
</div>
@if($template !== '')
    <div class="box muted">{{ $template }}</div>
@endif
</body></html>
