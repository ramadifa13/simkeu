@php
    use App\Support\DocumentText as T;
    $nomor = 'SHU-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Kwitansi {{ $nomor }}</title>
<style>
@page{size:A5 portrait;margin:12mm}
body{margin:0;background:#eee;font-family:DejaVu Sans,Arial,sans-serif;color:#0f172a}
.toolbar{padding:12px;text-align:center}button{padding:8px 16px;cursor:pointer}
.sheet{width:148mm;margin:0 auto 20px;padding:12mm;background:#fff;border:1px solid #e2e8f0}
h1{font-size:14px;margin:0} .muted{font-size:9px;color:#64748b}
.title{border-top:2px solid #b45309;padding-top:10px;margin-top:8px;font-size:13px;font-weight:bold;color:#b45309}
table{width:100%;border-collapse:collapse;margin-top:12px;font-size:10px;border:1px solid #e2e8f0}
td{padding:6px;border-bottom:1px solid #f1f5f9;vertical-align:top}
td:first-child{width:38%;color:#64748b}
.amount{font-size:14px;font-weight:bold;color:#b45309}
@media print{body{background:#fff}.toolbar{display:none}.sheet{width:auto;margin:0;padding:0;border:0}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Cetak / Simpan PDF</button> <button onclick="window.close()">Tutup</button></div>
<main class="sheet">
<h1>{{ $cooperative->nama ?? config('app.name') }}</h1>
<p class="muted">{{ $cooperative->alamat ?? '' }}</p>
<div class="title">KWITANSI PEMBAYARAN SHU</div>
<p class="muted">No. {{ $nomor }}</p>
<table>
<tr bgcolor="#fffbeb"><td>Penerima</td><td><strong>{{ $row->nama_snapshot }}</strong><br><span class="muted">{{ $row->kode_snapshot }}</span></td></tr>
<tr><td>Periode</td><td>{{ $row->nama_periode }} ({{ $row->tahun }})</td></tr>
<tr bgcolor="#f8fafc"><td>Jumlah diterima</td><td class="amount">{{ T::rupiah($row->total_shu) }}</td></tr>
<tr><td>Status</td><td>{{ $row->status_bayar }}</td></tr>
<tr bgcolor="#f8fafc"><td>Metode / referensi</td><td>{{ ($row->metode ?? '-').' / '.($row->referensi ?? '-') }}</td></tr>
<tr><td>Tanggal bayar</td><td>{{ $row->dibayar_at ? T::dateId($row->dibayar_at) : '—' }}</td></tr>
</table>
<p class="muted" style="margin-top:14px">Dokumen ini sah sebagai bukti pembayaran SHU. Dicetak {{ T::dateId(now()) }}.</p>
</main>
</body>
</html>
