<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;margin:24px}h1{font-size:16px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #ccc;padding:5px;text-align:left}th{background:#f3f3f3}.right{text-align:right}</style></head><body>
<h1>{{ $cooperative->nama ?? 'Koperasi' }} — Buku angsuran</h1>
<p>{{ $loan->kode_pinjaman }} · {{ $loan->nama_lengkap }}</p>
<table>
    <thead><tr><th>Ke</th><th>Jatuh tempo</th><th class="right">Pokok</th><th class="right">Bunga</th><th class="right">Total</th><th>Status</th><th>Bayar</th></tr></thead>
    <tbody>
    @foreach($installments as $row)
        <tr>
            <td>{{ $row->angsuran_ke }}</td>
            <td>{{ $row->tanggal_jatuh_tempo }}</td>
            <td class="right">{{ number_format((float) $row->pokok, 0, ',', '.') }}</td>
            <td class="right">{{ number_format((float) $row->bunga, 0, ',', '.') }}</td>
            <td class="right">{{ number_format((float) $row->jumlah_angsuran, 0, ',', '.') }}</td>
            <td>{{ $row->status }}</td>
            <td>{{ $row->tanggal_bayar ?? '-' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body></html>
