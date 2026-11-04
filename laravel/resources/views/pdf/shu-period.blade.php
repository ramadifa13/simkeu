<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;margin:20px}h1{font-size:16px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #ccc;padding:5px}th{background:#f3f3f3}.right{text-align:right}</style></head><body>
<h1>{{ $cooperative->nama ?? 'Koperasi' }} — Laporan {{ $detail->nama_periode }}</h1>
<p>Status {{ $detail->status }} · Total pool Rp {{ number_format((float) $detail->total_shu, 0, ',', '.') }}</p>
<table>
    <thead><tr><th>Kode</th><th>Nama</th><th class="right">Jasa simpanan</th><th class="right">Jasa pinjaman</th><th class="right">Jasa transaksi</th><th class="right">Total</th><th>Bayar</th></tr></thead>
    <tbody>
        @foreach($members as $row)
            <tr>
                <td>{{ $row->kode_snapshot }}</td>
                <td>{{ $row->nama_snapshot }}</td>
                <td class="right">{{ number_format((float) $row->jasa_simpanan, 0, ',', '.') }}</td>
                <td class="right">{{ number_format((float) $row->jasa_pinjaman, 0, ',', '.') }}</td>
                <td class="right">{{ number_format((float) $row->jasa_transaksi, 0, ',', '.') }}</td>
                <td class="right">{{ number_format((float) $row->total_shu, 0, ',', '.') }}</td>
                <td>{{ $row->status_bayar }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
</body></html>
