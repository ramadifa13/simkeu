@php
    use App\Support\DocumentText as T;
    $judulMap = [
        'denda' => 'KWITANSI PEMBAYARAN DENDA',
        'bayar' => 'KWITANSI PEMBAYARAN PINJAMAN',
        'lelang' => 'BUKTI PENJUALAN JAMINAN',
        'bonus' => 'KWITANSI PEMBAYARAN BONUS',
    ];
    $judul = $judulMap[$jenis] ?? 'KWITANSI LAYANAN';
    $nomor = strtoupper($jenis).'-'.str_pad((string) $row->id, 8, '0', STR_PAD_LEFT);
    $tanggalTampil = T::dateId(date('Y-m-d', strtotime($row->created_at ?? 'now')));
    $pernyataan = [
        'denda' => 'Telah diterima pembayaran denda pinjaman dari nasabah sebagaimana rincian transaksi di bawah ini.',
        'bayar' => 'Telah diterima pembayaran pinjaman dari nasabah sebagaimana rincian transaksi di bawah ini.',
        'lelang' => 'Dokumen ini mencatat hasil penjualan barang jaminan dan alokasi dana terhadap kewajiban pinjaman nasabah.',
        'bonus' => 'Telah dibayarkan bonus loyalitas kepada anggota berdasarkan perhitungan bunga pinjaman yang diterima koperasi.',
    ][$jenis] ?? '';
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $judul }} {{ $nomor }}</title>
<style>
@page{size:A4 portrait;margin:13mm}*{box-sizing:border-box}body{margin:0;background:#edf1f3;color:#172c3a;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.55}.toolbar{text-align:center;padding:12px}.toolbar button{padding:8px 16px;margin:0 4px;cursor:pointer}.sheet{width:210mm;min-height:297mm;margin:22px auto;padding:17mm 18mm;background:#fff;box-shadow:0 8px 32px #193b4726}.letterhead{text-align:center;padding-bottom:12px;border-bottom:3px double #233f4b}.letterhead h1{margin:0;font-size:17px;text-transform:uppercase}.letterhead p{margin:2px 0 0;color:#526b76;font-size:9px}.document-title{margin:24px 0 20px;text-align:center}.document-title h2{margin:0;font-size:15px;text-decoration:underline}.statement{margin:0 0 18px;text-align:justify}.info-grid{display:grid;grid-template-columns:1fr 1fr;border:1px solid #cbd8dd;border-radius:8px;overflow:hidden}.info-item{display:grid;grid-template-columns:112px 1fr;gap:9px;padding:9px 11px;border-bottom:1px solid #dce5e9}.info-item:nth-child(odd){border-right:1px solid #dce5e9}.section{margin-top:20px}.section h3{margin:0 0 9px;padding-bottom:6px;border-bottom:1px solid #b9cbd2;color:#234b58;font-size:12px;text-transform:uppercase}.transaction-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.transaction-grid>div{padding:11px 12px;border:1px solid #d7e2e6;border-radius:8px;background:#f8fafb}.transaction-grid span{display:block;color:#617782;font-size:10px}.transaction-grid strong{display:block;margin-top:3px;color:#294d59}.transaction-grid .is-total{border-color:#a9d1d0;background:#eef8f7}.transaction-grid .is-total strong{color:#086f72;font-size:15px}.transaction-highlight{padding:20px;border:1px solid #add3d2;border-radius:10px;background:#eff9f7;text-align:center}.transaction-highlight strong{display:block;margin:5px 0;color:#087f83;font-size:24px}.payment-foot{display:flex;justify-content:space-between;gap:12px;margin-top:10px;padding:9px 11px;border-radius:8px;background:#f4f7f8;color:#526b76}.status-pill{padding:4px 8px;border-radius:999px;font-size:10px;font-weight:700}.status-pill.is-approved{background:#e2f4ea;color:#196747}.status-pill.is-pending{background:#fff1cd;color:#745618}.document-note{margin-top:10px;padding:10px 12px;border:1px solid #e0e8eb;border-radius:8px;background:#fafcfd;color:#526b76}.footer-note{margin-top:20px;padding-top:8px;border-top:1px solid #dce5e9;color:#71868f;font-size:9px;text-align:center}@media print{body{background:#fff}.toolbar{display:none}.sheet{width:auto;margin:0;padding:0;box-shadow:none}}@media(max-width:800px){.sheet{width:100%;min-height:0;margin:0;padding:22px 18px;box-shadow:none}.info-grid,.transaction-grid{grid-template-columns:1fr}.info-item:nth-child(odd){border-right:0}}
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button><button type="button" onclick="window.close()">Tutup</button></div>
<main class="sheet">
<header class="letterhead">
    <h1>{{ $cooperative->nama ?? 'Koperasi' }}</h1>
    <p>{{ $cooperative->alamat ?? '-' }}</p>
    <p>Telp. {{ $cooperative->telepon ?? '-' }} · {{ $cooperative->email ?? '-' }}</p>
</header>
<div class="document-title"><h2>{{ $judul }}</h2><p>Nomor: {{ $nomor }}</p></div>
@if ($pernyataan !== '')<p class="statement">{{ $pernyataan }}</p>@endif
<section class="info-grid" aria-label="Informasi dokumen">
    <div class="info-item"><span>Nama nasabah</span><strong>{{ $row->nama_lengkap }}</strong></div>
    <div class="info-item"><span>Kode anggota</span><strong>{{ $row->kode_anggota }}</strong></div>
    <div class="info-item"><span>Nomor identitas</span><strong>{{ $row->ktp ?? '-' }}</strong></div>
    <div class="info-item"><span>Kode pinjaman</span><strong>{{ $row->kode_pinjaman }}</strong></div>
    <div class="info-item"><span>Petugas</span><strong>{{ $row->petugas ?? '-' }}</strong></div>
    <div class="info-item"><span>Tanggal transaksi</span><strong>{{ $tanggalTampil }}</strong></div>
</section>

@if ($jenis === 'denda')
<section class="section">
    <h3>Rincian pembayaran denda</h3>
    <div class="transaction-grid">
        <div><span>Cicilan ke</span><strong>{{ (int) ($row->angsuran_ke ?? 0) }}</strong></div>
        <div><span>Keterlambatan</span><strong>{{ (int) ($row->hari_telat ?? 0) }} hari</strong></div>
        <div><span>Tarif denda</span><strong>Rp{{ number_format((float) ($row->tarif ?? 0), 0, ',', '.') }} / hari</strong></div>
        <div><span>Denda awal</span><strong>Rp{{ number_format((float) ($row->denda_awal ?? 0), 0, ',', '.') }}</strong></div>
        <div><span>Denda setelah kebijakan</span><strong>Rp{{ number_format((float) ($row->denda_akhir ?? 0), 0, ',', '.') }}</strong></div>
        <div class="is-total"><span>Jumlah dibayar</span><strong>Rp{{ number_format((float) $row->jumlah, 0, ',', '.') }}</strong></div>
    </div>
    <div class="payment-foot">
        <span>Metode: <strong>{{ ucfirst((string) ($row->metode ?? '-')) }}</strong></span>
        <span class="status-pill {{ ($row->approved_by ?? null) ? 'is-approved' : 'is-pending' }}">{{ ($row->approved_by ?? null) ? 'Sudah disetujui' : 'Menunggu persetujuan' }}</span>
    </div>
</section>
@elseif ($jenis === 'bayar')
<section class="section">
    <h3>Rincian pembayaran pinjaman</h3>
    <div class="transaction-highlight">
        <span>{{ ucwords(str_replace('_', ' ', (string) ($row->jenis ?? 'pembayaran'))) }}</span>
        <strong>Rp{{ number_format((float) $row->jumlah, 0, ',', '.') }}</strong>
        <small>Pembayaran melalui {{ ucfirst((string) ($row->metode ?? '-')) }}</small>
    </div>
    @if ((float) ($row->bunga_diskon ?? 0) > 0)
        <div class="document-note">Diskon bunga yang diterapkan: <strong>Rp{{ number_format((float) $row->bunga_diskon, 0, ',', '.') }}</strong></div>
    @endif
</section>
@elseif ($jenis === 'lelang')
<section class="section">
    <h3>Rincian hasil penjualan jaminan</h3>
    <div class="transaction-grid">
        <div class="is-total"><span>Harga jual diterima</span><strong>Rp{{ number_format((float) ($row->harga_jual ?? $row->jumlah ?? 0), 0, ',', '.') }}</strong></div>
        <div><span>Dialokasikan ke kewajiban</span><strong>Rp{{ number_format((float) ($row->terpakai ?? 0), 0, ',', '.') }}</strong></div>
        <div><span>Kredit pembayaran</span><strong>Rp{{ number_format((float) ($row->kredit ?? 0), 0, ',', '.') }}</strong></div>
        <div><span>Kelebihan milik nasabah</span><strong>Rp{{ number_format((float) ($row->kelebihan ?? 0), 0, ',', '.') }}</strong></div>
        <div><span>Sudah dikembalikan</span><strong>Rp{{ number_format((float) ($row->dikembalikan ?? 0), 0, ',', '.') }}</strong></div>
        <div><span>Metode penerimaan</span><strong>{{ ucfirst((string) ($row->metode ?? '-')) }}</strong></div>
    </div>
    @if (filled($row->catatan ?? null))
        <div class="document-note"><span>Catatan</span>{!! nl2br(e($row->catatan)) !!}</div>
    @endif
</section>
@elseif ($jenis === 'bonus')
<section class="section">
    <h3>Rincian bonus anggota</h3>
    <div class="transaction-grid">
        <div><span>Tahun pembagian</span><strong>{{ (int) ($row->tahun ?? now()->year) }}</strong></div>
        <div><span>Bunga dasar</span><strong>Rp{{ number_format((float) ($row->bunga_dasar ?? 0), 0, ',', '.') }}</strong></div>
        <div><span>Persentase bonus</span><strong>{{ rtrim(rtrim(number_format((float) ($row->persen ?? 0), 2, ',', '.'), '0'), ',') }}%</strong></div>
        <div class="is-total"><span>Bonus dibayarkan</span><strong>Rp{{ number_format((float) $row->jumlah, 0, ',', '.') }}</strong></div>
        <div><span>Metode pembayaran</span><strong>{{ ucfirst((string) ($row->metode ?? '-')) }}</strong></div>
    </div>
</section>
@endif

<footer class="footer-note">Dokumen ini diterbitkan oleh sistem SIMKEU dan menjadi bukti transaksi yang sah.</footer>
</main>
</body>
</html>
