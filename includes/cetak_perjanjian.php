<?php
require_once __DIR__ . '/../tcpdf/tcpdf.php';

$pinjamanId = (int)$id;
$kodeCek = trim((string)($_GET['kode'] ?? ''));

// Ambil pinjaman hidup dulu — cegah salah cetak karena bingung pengajuan_id vs pinjaman_id
// (kode PJN-…-0021 = pengajuan 21, nomor 021/SPHP = pinjaman 21; sering beda orang).
$stmtPinjaman = $db->prepare("SELECT p.*, a.nama_lengkap, a.kode_anggota, a.kode_anggota_jaminan, a.ktp, a.alamat, a.telepon,
        a.tempat_lahir, a.tanggal_lahir, jp.nama_jenis, jp.memerlukan_jaminan, pp.tujuan_pinjaman
    FROM pinjaman p
    JOIN anggota a ON a.id = p.anggota_id
    JOIN jenis_pinjaman jp ON jp.id = p.jenis_pinjaman_id
    LEFT JOIN pengajuan_pinjaman pp ON pp.id = p.pengajuan_id
    WHERE p.id = ?");
$stmtPinjaman->execute([$pinjamanId]);
$pinjamanLive = $stmtPinjaman->fetch(PDO::FETCH_ASSOC);
if (!$pinjamanLive) {
    http_response_code(404);
    die('Pinjaman tidak ditemukan.');
}
if ($kodeCek !== '' && strcasecmp($kodeCek, (string)$pinjamanLive['kode_pinjaman']) !== 0) {
    http_response_code(400);
    die('Kode pinjaman tidak cocok dengan ID pinjaman. Buka ulang PDF dari menu aksi baris yang benar.');
}

$stmt = $db->prepare('SELECT pr.* FROM perjanjian_pinjaman pr WHERE pr.pinjaman_id = ?');
$stmt->execute([$pinjamanId]);
$surat = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$surat) {
    http_response_code(404);
    die('Surat perjanjian belum tersedia untuk pinjaman ' . htmlspecialchars((string)$pinjamanLive['kode_pinjaman']) . '.');
}
$data = json_decode($surat['snapshot_json'], true);
if (!is_array($data)) die('Arsip perjanjian tidak valid.');
$e = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$rp = function($v){ return 'Rp ' . number_format((float)$v, 0, ',', '.'); };

// Nama koperasi dari profil live; pihak pertama & TTD pengurus dari pengaturan surat.
$profilKoperasi = [];
try {
    $profilKoperasi = $db->query('SELECT nama, alamat, telepon FROM koperasi WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $ex) {
    $profilKoperasi = [];
}
$namaKoperasi = trim((string)($profilKoperasi['nama'] ?? ''));
if ($namaKoperasi === '') {
    $namaKoperasi = trim((string)($data['koperasi']['nama'] ?? 'Koperasi'));
}

$aturanSurat = pengaturanPerjanjian($db);
$ppSnap = is_array($data['pihak_pertama'] ?? null) ? $data['pihak_pertama'] : [];
$pp = [
    'nama' => trim((string)($aturanSurat['nama_pihak_pertama'] ?? ''))
        ?: trim((string)($ppSnap['nama'] ?? '')),
    'nik' => trim((string)($aturanSurat['nik_pihak_pertama'] ?? ''))
        ?: trim((string)($ppSnap['nik'] ?? '')),
    'alamat' => trim((string)($aturanSurat['alamat_pihak_pertama'] ?? ''))
        ?: trim((string)($ppSnap['alamat'] ?? '')),
    'telepon' => trim((string)($aturanSurat['telepon_pihak_pertama'] ?? ''))
        ?: trim((string)($ppSnap['telepon'] ?? '')),
];
if ($pp['nama'] === '') {
    $pp['nama'] = $namaKoperasi;
}

// Identitas & rincian dari data pinjaman/anggota LIVE (sumber kebenaran),
// supaya PDF tidak menampilkan nasabah/skema orang lain dari snapshot salah.
$kodeAnggotaLive = !empty($pinjamanLive['memerlukan_jaminan'])
    ? (string)($pinjamanLive['kode_anggota_jaminan'] ?: $pinjamanLive['kode_anggota'])
    : (string)$pinjamanLive['kode_anggota'];
$a = [
    'nama' => $pinjamanLive['nama_lengkap'],
    'kode' => $kodeAnggotaLive,
    'nik' => $pinjamanLive['ktp'],
    'alamat' => $pinjamanLive['alamat'],
    'telepon' => $pinjamanLive['telepon'],
    'tempat_lahir' => $pinjamanLive['tempat_lahir'],
    'tanggal_lahir' => $pinjamanLive['tanggal_lahir'],
];
$pSnap = is_array($data['pinjaman'] ?? null) ? $data['pinjaman'] : [];
$p = [
    'id' => (int)$pinjamanLive['id'],
    'jenis' => $pinjamanLive['nama_jenis'],
    'jumlah' => $pinjamanLive['jumlah_pinjaman'],
    'tenor' => $pinjamanLive['tenor'],
    'bunga' => $pinjamanLive['bunga'],
    'total' => $pinjamanLive['total_pinjaman'],
    'angsuran' => $pinjamanLive['angsuran_per_bulan'],
    'frekuensi' => $pinjamanLive['frekuensi'],
    'jumlah_cicilan' => $pinjamanLive['jumlah_cicilan'],
    'tanggal_pencairan' => $pinjamanLive['tanggal_pencairan'],
    'jatuh_tempo' => $pinjamanLive['jatuh_tempo'],
    'tujuan' => $pinjamanLive['tujuan_pinjaman'] ?: ($pSnap['tujuan'] ?? '-'),
    'hari_angsuran' => $pSnap['hari_angsuran'] ?? null,
];
// Jadwal lampiran: utamakan angsuran live agar tidak ikut snapshot orang lain.
$stmtJadwalLive = $db->prepare('SELECT angsuran_ke, pokok, bunga, jumlah_angsuran, tanggal_jatuh_tempo FROM angsuran WHERE pinjaman_id = ? ORDER BY angsuran_ke');
$stmtJadwalLive->execute([$pinjamanId]);
$jadwalLive = $stmtJadwalLive->fetchAll(PDO::FETCH_ASSOC);
if ($jadwalLive) {
    $data['jadwal_angsuran'] = $jadwalLive;
}
$k = $data['koperasi'] ?? [];
$penandatangan = $data['penandatangan'] ?? [];
$penandatangan['nasabah'] = $a['nama'];

// Nama saksi: kolom perjanjian, fallback snapshot pencairan.
$namaSaksi1 = trim((string)($surat['nama_saksi_1'] ?? ''));
$namaSaksi2 = trim((string)($surat['nama_saksi_2'] ?? ''));
if ($namaSaksi1 === '') $namaSaksi1 = trim((string)($penandatangan['saksi_1'] ?? ''));
if ($namaSaksi2 === '') $namaSaksi2 = trim((string)($penandatangan['saksi_2'] ?? ''));
if ($namaSaksi1 === '') $namaSaksi1 = '...............................';
if ($namaSaksi2 === '') $namaSaksi2 = '...............................';

$jabatanAdmin = trim((string)($aturanSurat['jabatan_admin'] ?? ''));
if ($jabatanAdmin === '') {
    $jabatanAdmin = trim((string)($penandatangan['jabatan_admin'] ?? 'Pengurus'));
}
if ($jabatanAdmin === '') $jabatanAdmin = 'Pengurus';

// Nama di bawah TTD pengurus = nama pihak pertama (bukan nama koperasi).
$namaPengurus = $pp['nama'];

// TTD pengurus: utamakan TTD di pengaturan/profil surat; fallback arsip.
$ttdAdminLive = trim((string)($aturanSurat['ttd_admin'] ?? ''));
if ($ttdAdminLive !== '') {
    $surat['ttd_admin'] = $ttdAdminLive;
}

// Lebar label tetap (mm) agar titik dua sejajar antar blok pihak/rincian.
$labelW = 48;
$nilaiW = 126;
$barisPdf = function ($label, $nilai) use ($e, $labelW, $nilaiW) {
    return '<tr><td width="' . $labelW . '">' . $e($label) . '</td><td width="' . $nilaiW . '">: ' . $nilai . '</td></tr>';
};
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('SIMKEU');
$pdf->SetAuthor($namaKoperasi);
$pdf->SetTitle('Surat Perjanjian ' . $surat['nomor_perjanjian']);
$pdf->SetMargins(18, 16, 18);
$pdf->SetAutoPageBreak(true, 16);
$pdf->setPrintHeader(false);
$pdf->SetFooterMargin(8);
$pdf->AddPage();
$pdf->SetFont('dejavusans', '', 9.5);

$html = '<div style="text-align:center"><b style="font-size:15px">SURAT PERJANJIAN PINJAMAN</b><br>'
    . $e($namaKoperasi)
    . '<br><small>No. ' . $e($surat['nomor_perjanjian']) . ' · Versi ' . ucfirst($e($surat['versi'])) . '</small></div><hr>';

$html .= '<h4>PIHAK PERTAMA — PEMBERI PINJAMAN</h4>'
    . '<table cellpadding="2" width="174">'
    . $barisPdf('Nama', $e($pp['nama']))
    . $barisPdf('NIK', $e($pp['nik'] ?? ''))
    . $barisPdf('Alamat', $e($pp['alamat'] ?? ''))
    . $barisPdf('No. HP', $e($pp['telepon'] ?? ''))
    . '</table>';

$lahir = trim(($a['tempat_lahir'] ?? '') . (!empty($a['tanggal_lahir']) ? ', ' . date('d/m/Y', strtotime($a['tanggal_lahir'])) : ''));
$html .= '<h4>PIHAK KEDUA — PENERIMA PINJAMAN</h4>'
    . '<table cellpadding="2" width="174">'
    . $barisPdf('Nama', '<b>' . $e($a['nama']) . '</b>')
    . $barisPdf('Kode', $e($a['kode']))
    . $barisPdf('NIK', $e($a['nik']))
    . $barisPdf('Tempat, Tgl Lahir', $e($lahir))
    . $barisPdf('Alamat', $e($a['alamat'] ?? ''))
    . $barisPdf('No. HP', $e($a['telepon'] ?? ''))
    . '</table>';

$html .= '<h4>RINCIAN PINJAMAN</h4>'
    . '<table cellpadding="2" width="174">'
    . $barisPdf('Jenis Pinjaman', $e($p['jenis']))
    . $barisPdf('Nilai Pinjaman', '<b>' . $rp($p['jumlah']) . '</b>')
    . $barisPdf('Tujuan Pinjaman', $e($p['tujuan'] ?? '-'))
    . $barisPdf('Tenor', $e($p['tenor']) . ' bulan')
    . $barisPdf('Frekuensi', $e($p['frekuensi']) . ' (' . $e($p['jumlah_cicilan'] ?? '') . ' kali)')
    . $barisPdf('Bunga', $e($p['bunga']) . '%')
    . $barisPdf('Total', $rp($p['total']))
    . $barisPdf('Angsuran', $rp($p['angsuran']))
    . $barisPdf('Pencairan', $e(date('d/m/Y', strtotime($p['tanggal_pencairan']))))
    . $barisPdf('Jatuh Tempo', '<b>' . $e(date('d/m/Y', strtotime($p['jatuh_tempo']))) . '</b>')
    . '</table>';
if (!empty($data['jaminan']['ada'])) {
    $html .= '<h4>PASAL 3 - JAMINAN</h4><p>Sebagai jaminan atas hutang tersebut, PIHAK KEDUA menyerahkan jaminan berupa:<br><b>Jenis:</b> '
        . $e($data['jaminan']['jenis']) . '<br>' . nl2br($e($data['jaminan']['detail']))
        . '<br>Jaminan dikembalikan sepenuhnya setelah seluruh hutang dan bunga lunas 100%.</p>';
    $html .= '<h4>PASAL 4 - WANPRESTASI</h4><p>Apabila PIHAK KEDUA tidak melunasi pada tanggal jatuh tempo, PIHAK PERTAMA berhak menjual atau mengalihkan jaminan untuk pelunasan hutang. Kelebihan hasil penjualan dikembalikan kepada PIHAK KEDUA; kekurangannya tetap menjadi kewajiban PIHAK KEDUA.</p>';
    $html .= '<h4>PASAL 5 - PENYELESAIAN SENGKETA</h4><p>Perselisihan diselesaikan terlebih dahulu secara musyawarah mufakat. Jika tidak tercapai, diselesaikan melalui jalur hukum yang berlaku.</p>';
} else {
    $html .= '<h4>Ketentuan Perjanjian</h4><div style="line-height:1.5">' . nl2br($e($data['isi_perjanjian'])) . '</div>';
}
$html .= '<p>Dengan membubuhkan tanda tangan di bawah ini, para pihak menyatakan data telah dibaca dan disetujui.</p>';
$tanggalSurat = !empty($p['tanggal_pencairan']) ? date('d/m/Y', strtotime($p['tanggal_pencairan'])) : date('d/m/Y');
$tempatSurat = trim((string)($profilKoperasi['alamat'] ?? ($k['alamat'] ?? 'Mojokerto')));
if ($tempatSurat === '') $tempatSurat = 'Mojokerto';
$html .= '<p style="text-align:right">Dibuat di ' . $e($tempatSurat) . '<br>Pada tanggal: ' . $e($tanggalSurat) . '</p>';
$pdf->writeHTML($html, true, false, true, false, '');

// Blok tanda tangan: baris 1 nasabah/teller/pengurus, baris 2 saksi.
// Nama & TTD pengurus = pihak pertama / TTD di pengaturan surat.
$blokTtd = [
    [
        ['field' => 'ttd_nasabah', 'label' => 'Nasabah', 'name' => (string)($penandatangan['nasabah'] ?? $a['nama'] ?? ''), 'meterai' => true],
        ['field' => 'ttd_teller', 'label' => 'Teller', 'name' => (string)($penandatangan['teller'] ?? ''), 'meterai' => false],
        ['field' => 'ttd_admin', 'label' => $jabatanAdmin, 'name' => $namaPengurus, 'meterai' => false],
    ],
    [
        ['field' => 'ttd_saksi_1', 'label' => 'Saksi 1', 'name' => $namaSaksi1, 'meterai' => false],
        ['field' => 'ttd_saksi_2', 'label' => 'Saksi 2', 'name' => $namaSaksi2, 'meterai' => false],
    ],
];
$temps = [];
$y = $pdf->GetY() + 6;
if ($y > 175) {
    $pdf->AddPage();
    $y = 25;
}

foreach ($blokTtd as $barisKe => $baris) {
    $n = count($baris);
    $colW = $n === 3 ? 58 : 70;
    $gap = $n === 3 ? 4 : 12;
    $totalW = ($colW * $n) + ($gap * ($n - 1));
    $startX = 18 + max(0, (174 - $totalW) / 2);
    if ($barisKe > 0) {
        $y += 48;
        if ($y > 220) {
            $pdf->AddPage();
            $y = 25;
        }
    }
    foreach ($baris as $i => $slot) {
        $tx = $startX + ($i * ($colW + $gap));
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->SetXY($tx, $y);
        $pdf->Cell($colW, 5, $slot['label'], 0, 0, 'C');

        $tmp = dataUrlKeFileSementara($surat[$slot['field']] ?? '');
        if ($tmp) {
            $temps[] = $tmp;
            $pdf->Image($tmp, $tx + (($colW - 35) / 2), $y + 7, 35, 17, dataUrlTipeTcpdf($surat[$slot['field']]));
        }
        if (!empty($slot['meterai'])) {
            $pdf->SetFont('dejavusans', '', 6);
            $pdf->SetXY($tx, $y + 25);
            $pdf->Cell($colW, 3, 'Meterai Rp10.000', 0, 0, 'C');
        }
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetXY($tx, $y + 36);
        $pdf->MultiCell($colW, 4, $slot['name'], 0, 'C', false, 0);
    }
}

$pdf->SetY($y + 48);
$pdf->SetFont('dejavusans', '', 7);
$pdf->MultiCell(
    0,
    4,
    'Arsip ditandatangani: ' . date('d/m/Y H:i', strtotime($surat['ditandatangani_at'])) . ' WIB | SHA-256: ' . $surat['hash_dokumen'],
    0,
    'L'
);

if (!empty($data['jadwal_angsuran'])) {
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->Cell(0, 8, 'LAMPIRAN JADWAL ANGSURAN', 0, 1, 'C');
    $pdf->SetFont('dejavusans', '', 8);
    $t = '<table border="1" cellpadding="4"><tr style="font-weight:bold;background-color:#eeeeee"><th width="7%">Ke</th><th width="19%">Jatuh Tempo</th><th width="18%">Pokok</th><th width="17%">Bunga</th><th width="20%">Total Bayar</th><th width="19%">Sisa</th></tr>';
    $sisa = (float)$p['total'];
    foreach ($data['jadwal_angsuran'] as $j) {
        $sisa = max(0, $sisa - (float)$j['jumlah_angsuran']);
        $t .= '<tr><td>' . $e($j['angsuran_ke']) . '</td><td>' . $e(date('d/m/Y', strtotime($j['tanggal_jatuh_tempo']))) . '</td><td>' . $rp($j['pokok']) . '</td><td>' . $rp($j['bunga']) . '</td><td>' . $rp($j['jumlah_angsuran']) . '</td><td>' . ($sisa > 0 ? $rp($sisa) : 'LUNAS') . '</td></tr>';
    }
    $t .= '</table>';
    $pdf->writeHTML($t, true, false, true, false, '');
}

$lampiran = [];
foreach (['pencairan', 'barang', 'nasabah_barang'] as $label) {
    $path = $data['foto'][$label] ?? '';
    if ($path) $lampiran[] = ['path' => $path, 'judul' => ucwords(str_replace('_', ' ', $label))];
}
$st = $db->prepare('SELECT file_path, urutan FROM lampiran_perjanjian WHERE perjanjian_id=? ORDER BY urutan');
$st->execute([$surat['id']]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $lampiran[] = ['path' => $l['file_path'], 'judul' => 'Lampiran Surat Halaman ' . $l['urutan']];
}
foreach ($lampiran as $l) {
    $file = realpath(__DIR__ . '/../uploads/' . $l['path']);
    $root = realpath(__DIR__ . '/../uploads');
    if (!$file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) continue;
    $size = @getimagesize($file);
    if (!$size) continue;
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->Cell(0, 8, $l['judul'], 0, 1, 'C');
    $maxW = 174;
    $maxH = 245;
    $ratio = min($maxW / $size[0], $maxH / $size[1]);
    $w = $size[0] * $ratio;
    $h = $size[1] * $ratio;
    $pdf->Image($file, (210 - $w) / 2, 28, $w, $h);
}
foreach ($temps as $tmp) @unlink($tmp);
$namaFile = 'perjanjian-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $surat['nomor_perjanjian']) . '.pdf';
$pdf->Output($namaFile, 'I');
exit;
