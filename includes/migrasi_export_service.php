<?php
/**
 * Export database ke XLSX format template migrasi (bisa di-import ulang).
 */
require_once __DIR__ . '/migrasi_xlsx_writer.php';
require_once __DIR__ . '/pinjaman_helper.php';
require_once __DIR__ . '/migrasi_dokumen_cadangan.php';

function migrasiTabelAda(PDO $db, $tabel) {
    static $cache = [];
    if (!array_key_exists($tabel, $cache)) {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$tabel]);
        $cache[$tabel] = (int) $q->fetchColumn() > 0;
    }
    return $cache[$tabel];
}

function migrasiExportTanggal($value) {
    if ($value === null || $value === '') {
        return '';
    }
    $ts = strtotime((string) $value);
    return $ts ? date('d/m/Y', $ts) : (string) $value;
}

function migrasiExportAngka($value) {
    if ($value === null || $value === '') {
        return '';
    }
    return is_numeric($value) ? (0 + $value) : $value;
}

/**
 * @return array{path:string,name:string,counts:array}
 */
function migrasiExportBackupExcel(PDO $db, $backupDir) {
    $backupDir = rtrim($backupDir, '/\\');
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
        throw new RuntimeException('Gagal membuat direktori backups.');
    }
    if (!is_writable($backupDir)) {
        throw new RuntimeException('Direktori backups tidak dapat ditulis.');
    }

    $koperasi = ['nama' => defined('APP_NAME') ? APP_NAME : 'Koperasi', 'alamat' => '', 'telepon' => '', 'email' => '', 'rekening' => ''];
    if (migrasiTabelAda($db, 'koperasi')) {
        $row = $db->query('SELECT * FROM koperasi ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $koperasi['nama'] = $row['nama'] ?? $koperasi['nama'];
            $koperasi['alamat'] = $row['alamat'] ?? '';
            $koperasi['telepon'] = $row['telepon'] ?? '';
            $koperasi['email'] = $row['email'] ?? '';
            $koperasi['rekening'] = $row['rekening_bank'] ?? ($row['rekening'] ?? '');
        }
    }

    $dokumen = [];
    if (migrasiTabelAda($db, 'migrasi_anggota_dokumen')) {
        foreach ($db->query('SELECT anggota_id, foto, ttd FROM migrasi_anggota_dokumen') as $d) {
            $dokumen[(int) $d['anggota_id']] = $d;
        }
    }

    $nasabahRows = [];
    $anggotaCols = 'id, kode_anggota, ktp, nama_lengkap, alamat, telepon, status, foto_ktp';
    if (kolomAda($db, 'anggota', 'foto_kk')) {
        $anggotaCols .= ', foto_kk';
    }
    if (kolomAda($db, 'anggota', 'foto_ktp_pasangan')) {
        $anggotaCols .= ', foto_ktp_pasangan';
    }
    $anggotaStmt = $db->query("SELECT $anggotaCols FROM anggota ORDER BY id");
    foreach ($anggotaStmt as $a) {
        $doc = $dokumen[(int) $a['id']] ?? [];
        $ttdExport = (string) ($doc['ttd'] ?? '');
        if ($ttdExport !== '' && strpos($ttdExport, 'data:') === 0) {
            $ttdExport = ''; // data URL terlalu besar untuk Excel; file path saja
        }
        $nasabahRows[] = [
            'ID Nasabah Lama*' => $a['kode_anggota'],
            'NIK*' => $a['ktp'] ?? '',
            'Nama Lengkap*' => $a['nama_lengkap'],
            'Alamat*' => $a['alamat'] ?? '',
            'No. HP' => $a['telepon'] ?? '',
            'Status' => $a['status'] ?? 'aktif',
            'Catatan' => '',
            'Foto KTP (opsional)' => ($a['foto_ktp'] ?: ($doc['foto'] ?? '')) ?: '',
            'Foto KK (opsional)' => $a['foto_kk'] ?? '',
            'Foto KTP Pasangan (opsional)' => $a['foto_ktp_pasangan'] ?? '',
            'TTD (opsional)' => $ttdExport,
        ];
    }

    // Skema bunga ada di pinjaman, bukan master produk — ambil mode terbanyak per jenis.
    $skemaPerProduk = [];
    try {
        $qSkema = $db->query(
            "SELECT jenis_pinjaman_id, skema_bunga, COUNT(*) c
             FROM pinjaman
             WHERE skema_bunga IS NOT NULL AND skema_bunga <> ''
             GROUP BY jenis_pinjaman_id, skema_bunga
             ORDER BY jenis_pinjaman_id, c DESC"
        );
        foreach ($qSkema as $row) {
            $jid = (int) $row['jenis_pinjaman_id'];
            if (!isset($skemaPerProduk[$jid])) {
                $skemaPerProduk[$jid] = $row['skema_bunga'] === 'tahunan' ? 'tahunan' : 'flat';
            }
        }
    } catch (Throwable $e) {
        // kolom belum ada
    }

    $produkRows = [];
    foreach ($db->query('SELECT * FROM jenis_pinjaman ORDER BY id') as $p) {
        $freq = (string) ($p['frekuensi_diizinkan'] ?? 'bulanan');
        // SET bisa berisi beberapa nilai; ambil opsi pertama untuk template.
        if (strpos($freq, ',') !== false) {
            $freq = trim(explode(',', $freq)[0]);
        }
        $skema = !empty($p['skema_bunga'])
            ? $p['skema_bunga']
            : ($skemaPerProduk[(int) $p['id']] ?? 'flat');
        $produkRows[] = [
            'Kode Produk*' => $p['kode_jenis'],
            'Nama Produk*' => $p['nama_jenis'],
            'Plafon Maks. (Rp)*' => migrasiExportAngka($p['maksimal_pinjaman']),
            'Tenor Maks. (Bulan)*' => (int) $p['tenor_max'],
            'Bunga Nasabah (%)*' => migrasiExportAngka($p['bunga']),
            'Frekuensi*' => $freq !== '' ? $freq : 'bulanan',
            'Skema Bunga*' => $skema === 'tahunan' ? 'tahunan' : 'flat',
            'Perlu Jaminan?*' => !empty($p['memerlukan_jaminan']) ? 'Ya' : 'Tidak',
            'Status*' => $p['status'] ?: 'aktif',
            'Deskripsi' => $p['deskripsi'] ?? '',
        ];
    }

    $simpananRows = [];
    foreach ($db->query('SELECT * FROM jenis_simpanan ORDER BY id') as $s) {
        $simpananRows[] = [
            'Kode Simpanan*' => $s['kode_jenis'],
            'Nama Simpanan*' => $s['nama_jenis'],
            'Minimum (Rp)' => migrasiExportAngka($s['minimum'] ?? 0),
            'Maksimum (Rp)' => migrasiExportAngka($s['maksimum'] ?? 0),
            'Bagi Hasil (%)' => migrasiExportAngka($s['bagi_hasil'] ?? 0),
            'Status*' => $s['status'] ?: 'aktif',
            'Deskripsi' => $s['deskripsi'] ?? '',
        ];
    }

    $dendaRows = [];
    if (migrasiTabelAda($db, 'denda_tarif')) {
        foreach ($db->query('SELECT * FROM denda_tarif ORDER BY jumlah_pinjaman') as $d) {
            $dendaRows[] = [
                'Plafon Pinjaman (Rp)*' => migrasiExportAngka($d['jumlah_pinjaman']),
                'Denda per Hari (Rp)*' => migrasiExportAngka($d['denda_per_hari']),
                'Status*' => 'aktif',
                'Catatan' => '',
            ];
        }
    }
    // Jangan mengarang tarif denda saat backup; sheet boleh kosong (validasi = warning).

    $dapinRows = [];
    if (migrasiTabelAda($db, 'pendanaan_dapin')) {
        foreach ($db->query('SELECT * FROM pendanaan_dapin ORDER BY id') as $d) {
            $dapinRows[] = [
                'ID Dapin*' => $d['kode_dapin'],
                'Nama/Lembaga Dapin*' => $d['nama_pendana'],
                'Tanggal Dana Diterima*' => migrasiExportTanggal($d['tanggal_diterima']),
                'Modal Diterima (Rp)*' => migrasiExportAngka($d['modal_diterima']),
                'Tenor (Bulan)*' => (int) $d['tenor'],
                'Total Wajib Kembali (Rp)*' => migrasiExportAngka($d['total_wajib_kembali']),
                'Tanggal Jatuh Tempo' => migrasiExportTanggal($d['tanggal_jatuh_tempo']),
                'Status*' => $d['status'] ?: 'aktif',
                'Catatan' => $d['catatan'] ?? '',
            ];
        }
    }

    $pinjamanRows = [];
    $qPinjaman = $db->query(
        "SELECT p.*, a.kode_anggota, jp.kode_jenis
         FROM pinjaman p
         JOIN anggota a ON a.id = p.anggota_id
         JOIN jenis_pinjaman jp ON jp.id = p.jenis_pinjaman_id
         ORDER BY p.id"
    );
    foreach ($qPinjaman as $p) {
        $status = $p['status'] === 'lunas' ? 'lunas' : ($p['status'] === 'macet' ? 'macet' : 'aktif');
        $pinjamanRows[] = [
            'ID Pinjaman Lama*' => $p['kode_pinjaman'],
            'ID Nasabah Lama*' => $p['kode_anggota'],
            'Kode Produk*' => $p['kode_jenis'],
            'Tanggal Cair Asli*' => migrasiExportTanggal($p['tanggal_pencairan']),
            'Plafon (Rp)*' => migrasiExportAngka($p['jumlah_pinjaman']),
            'Tenor (Bulan)*' => (int) $p['tenor'],
            'Frekuensi*' => $p['frekuensi'] ?: 'bulanan',
            'Bunga (%)*' => migrasiExportAngka($p['bunga']),
            'Skema Bunga*' => $p['skema_bunga'] ?: 'flat',
            'Angsuran Normal (Rp)*' => migrasiExportAngka($p['angsuran_per_bulan']),
            'Total Tagihan (Rp)*' => migrasiExportAngka($p['total_pinjaman']),
            'Status Saat Ini*' => $status,
            'Tanggal Lunas' => migrasiExportTanggal($p['tanggal_lunas'] ?? ''),
            'Foto Pencairan (opsional)' => $p['foto_pencairan'] ?? '',
            'Catatan' => '',
        ];
    }

    // Gabungkan pembayaran migrasi + angsuran lunas setelah migrasi (teller/admin),
    // agar backup tidak kehilangan cicilan yang dibayar setelah go-live.
    $angsuranRows = [];
    $tercoverMigrasi = []; // pinjaman_id => max angsuran_ke yang sudah tercakup migrasi
    if (migrasiTabelAda($db, 'migrasi_pembayaran')) {
        $qBayar = $db->query(
            "SELECT mp.*, p.kode_pinjaman
             FROM migrasi_pembayaran mp
             JOIN pinjaman p ON p.id = mp.pinjaman_id
             ORDER BY mp.tanggal_bayar, mp.id"
        );
        foreach ($qBayar as $b) {
            $pid = (int) $b['pinjaman_id'];
            $ke = (int) $b['angsuran_ke'];
            $tercoverMigrasi[$pid] = max($tercoverMigrasi[$pid] ?? 0, $ke);
            $angsuranRows[] = [
                'ID Pembayaran' => $b['source_key'],
                'ID Pinjaman Lama*' => $b['kode_pinjaman'],
                'Tanggal Bayar*' => migrasiExportTanggal($b['tanggal_bayar']),
                'Angsuran Ke' => $ke,
                'Jenis Pembayaran*' => $b['jenis_pembayaran'] ?: 'cicilan',
                'Pokok (Rp)' => migrasiExportAngka($b['pokok']),
                'Bunga (Rp)' => migrasiExportAngka($b['bunga']),
                'Denda (Rp)' => migrasiExportAngka($b['denda']),
                'Total Diterima (Rp)*' => migrasiExportAngka($b['total_diterima']),
                'Status*' => $b['status'] ?: 'dibayar',
                'Keterangan' => $b['keterangan'] ?? '',
            ];
        }
    }
    $qAng = $db->query(
        "SELECT a.*, p.kode_pinjaman
         FROM angsuran a
         JOIN pinjaman p ON p.id = a.pinjaman_id
         WHERE a.status = 'lunas' AND a.tanggal_bayar IS NOT NULL
         ORDER BY a.tanggal_bayar, a.angsuran_ke, a.id"
    );
    foreach ($qAng as $a) {
        $pid = (int) $a['pinjaman_id'];
        $ke = (int) $a['angsuran_ke'];
        // Sudah tercakup baris migrasi (import menandai angsuran_ke <= N).
        if ($ke <= (int) ($tercoverMigrasi[$pid] ?? 0)) {
            continue;
        }
        // source_key max 100; jaga ke di akhir agar BYR-...-1 vs ...-10 tidak bertabrakan setelah truncate.
        $safeKode = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $a['kode_pinjaman']);
        $suffix = '-' . $ke;
        $prefix = 'BYR-' . $safeKode;
        $maxPrefix = 100 - strlen($suffix);
        if (strlen($prefix) > $maxPrefix) {
            $prefix = substr($prefix, 0, max(4, $maxPrefix - 7)) . '-' . substr(hash('crc32b', $safeKode), 0, 6);
        }
        $angsuranRows[] = [
            'ID Pembayaran' => $prefix . $suffix,
            'ID Pinjaman Lama*' => $a['kode_pinjaman'],
            'Tanggal Bayar*' => migrasiExportTanggal($a['tanggal_bayar']),
            'Angsuran Ke' => $ke,
            'Jenis Pembayaran*' => (($a['cara_bayar'] ?? '') === 'pelunasan') ? 'pelunasan' : 'cicilan',
            'Pokok (Rp)' => migrasiExportAngka($a['pokok']),
            'Bunga (Rp)' => migrasiExportAngka($a['bunga']),
            'Denda (Rp)' => migrasiExportAngka($a['denda']),
            'Total Diterima (Rp)*' => migrasiExportAngka(((float) $a['jumlah_angsuran']) + ((float) $a['denda'])),
            'Status*' => 'dibayar',
            'Keterangan' => 'Ekspor dari angsuran lunas (pasca-migrasi)',
        ];
    }

    $alokasiRows = [];
    if (migrasiTabelAda($db, 'alokasi_dapin') && migrasiTabelAda($db, 'pendanaan_dapin')) {
        $qAlok = $db->query(
            "SELECT ad.*, d.kode_dapin, p.kode_pinjaman
             FROM alokasi_dapin ad
             JOIN pendanaan_dapin d ON d.id = ad.dapin_id
             JOIN pinjaman p ON p.id = ad.pinjaman_id
             ORDER BY ad.id"
        );
        foreach ($qAlok as $a) {
            $alokasiRows[] = [
                'ID Alokasi' => $a['source_key'],
                'ID Pinjaman Lama*' => $a['kode_pinjaman'],
                'ID Dapin*' => $a['kode_dapin'],
                'Modal Dialokasikan (Rp)*' => migrasiExportAngka($a['modal_dialokasikan']),
                'Kewajiban Dapin (Rp)*' => migrasiExportAngka($a['kewajiban_dapin']),
                'Tanggal Alokasi*' => migrasiExportTanggal($a['tanggal_alokasi']),
                'Catatan' => $a['catatan'] ?? '',
            ];
        }
    }

    // Jangan isi saldo kas = saldo terakhir DB. Import akan membangun ulang kas
    // dari pencairan, pembayaran, dan modal Dapin. Mengisi saldo terakhir di sini
    // akan menggandakan kas saat import ulang.
    $saldoRows = [[
        'Tanggal Cut-off*' => date('d/m/Y'),
        'Saldo Kas Fisik (Rp)*' => 0,
        'Mutasi Historis Sudah Diinput?*' => 'Ya',
        'Dasar/Perhitungan Saldo*' => 'Backup lengkap: mutasi historis ada di sheet Pinjaman/Angsuran_Historis/Pendanaan_Dapin. Saldo awal Rp0 agar tidak dobel saat import.',
        'Disetujui Oleh' => '',
        'Catatan' => 'Ubah ke kas fisik nyata HANYA jika ada selisih yang belum tercatat di sheet mutasi.',
    ]];

    $sheets = [
        'Petunjuk' => [
            'title' => 'BACKUP EXCEL FORMAT MIGRASI',
            'headers' => ['Topik', 'Keterangan'],
            'rows' => [
                ['Tujuan', 'File ini diekspor dari database aktif dalam format template migrasi agar dapat diedit lalu di-import ulang.'],
                ['Import', 'Unggah file ini di menu Admin → Import Migrasi (pratinjau dulu, lalu Import Final).'],
                ['Foto/TTD', 'Kolom Foto KTP/KK/Pasangan/Pencairan berisi path relatif di folder uploads. Pakai Backup ZIP agar file gambar ikut.'],
                ['Denda', 'Cicilan belum lunas yang lewat jatuh tempo akan dihitung denda otomatis setelah import.'],
                ['Kas', 'Saldo_Awal_Kas di-export Rp0 karena mutasi sudah di sheet lain. Jangan isi saldo terakhir DB di sini (akan dobel saat import).'],
                ['Dapin', 'Pendanaan_Dapin boleh kosong jika usaha tidak memakai Dapin.'],
                ['Hosting', 'Di hosting: restore SQL ATAU import Excel + ekstrak folder uploads dari ZIP ke folder uploads aplikasi.'],
                ['SQL', 'Backup SQL tetap disarankan untuk restore penuh struktur+data di hosting.'],
            ],
        ],
        'Konfigurasi_Koperasi' => [
            'title' => 'KONFIGURASI KOPERASI',
            'headers' => ['Nama Koperasi*', 'Alamat', 'Telepon', 'Email', 'Rekening Bank', 'Catatan'],
            'rows' => [[
                'Nama Koperasi*' => $koperasi['nama'],
                'Alamat' => $koperasi['alamat'],
                'Telepon' => $koperasi['telepon'],
                'Email' => $koperasi['email'],
                'Rekening Bank' => $koperasi['rekening'],
                'Catatan' => 'Backup ' . date('Y-m-d H:i:s'),
            ]],
        ],
        'Master_Produk' => [
            'title' => 'MASTER PRODUK PINJAMAN',
            'headers' => ['Kode Produk*', 'Nama Produk*', 'Plafon Maks. (Rp)*', 'Tenor Maks. (Bulan)*', 'Bunga Nasabah (%)*', 'Frekuensi*', 'Skema Bunga*', 'Perlu Jaminan?*', 'Status*', 'Deskripsi'],
            'rows' => $produkRows,
        ],
        'Master_Simpanan' => [
            'title' => 'MASTER JENIS SIMPANAN',
            'headers' => ['Kode Simpanan*', 'Nama Simpanan*', 'Minimum (Rp)', 'Maksimum (Rp)', 'Bagi Hasil (%)', 'Status*', 'Deskripsi'],
            'rows' => $simpananRows,
        ],
        'Master_Denda' => [
            'title' => 'MASTER TARIF DENDA',
            'headers' => ['Plafon Pinjaman (Rp)*', 'Denda per Hari (Rp)*', 'Status*', 'Catatan'],
            'rows' => $dendaRows,
        ],
        'Pendanaan_Dapin' => [
            'title' => 'SUMBER PENDANAAN DAPIN',
            'headers' => ['ID Dapin*', 'Nama/Lembaga Dapin*', 'Tanggal Dana Diterima*', 'Modal Diterima (Rp)*', 'Tenor (Bulan)*', 'Total Wajib Kembali (Rp)*', 'Tanggal Jatuh Tempo', 'Status*', 'Catatan'],
            'rows' => $dapinRows,
        ],
        'Nasabah' => [
            'title' => 'DATA NASABAH',
            'headers' => ['ID Nasabah Lama*', 'NIK*', 'Nama Lengkap*', 'Alamat*', 'No. HP', 'Status', 'Catatan', 'Foto KTP (opsional)', 'Foto KK (opsional)', 'Foto KTP Pasangan (opsional)', 'TTD (opsional)'],
            'rows' => $nasabahRows,
        ],
        'Pinjaman' => [
            'title' => 'DATA PENCAIRAN / PINJAMAN',
            'headers' => ['ID Pinjaman Lama*', 'ID Nasabah Lama*', 'Kode Produk*', 'Tanggal Cair Asli*', 'Plafon (Rp)*', 'Tenor (Bulan)*', 'Frekuensi*', 'Bunga (%)*', 'Skema Bunga*', 'Angsuran Normal (Rp)*', 'Total Tagihan (Rp)*', 'Status Saat Ini*', 'Tanggal Lunas', 'Foto Pencairan (opsional)', 'Catatan'],
            'rows' => $pinjamanRows,
        ],
        'Angsuran_Historis' => [
            'title' => 'RIWAYAT ANGSURAN DAN PELUNASAN',
            'headers' => ['ID Pembayaran', 'ID Pinjaman Lama*', 'Tanggal Bayar*', 'Angsuran Ke', 'Jenis Pembayaran*', 'Pokok (Rp)', 'Bunga (Rp)', 'Denda (Rp)', 'Total Diterima (Rp)*', 'Status*', 'Keterangan'],
            'rows' => $angsuranRows,
        ],
        'Alokasi_Dapin' => [
            'title' => 'ALOKASI MODAL DAPIN KE PINJAMAN',
            'headers' => ['ID Alokasi', 'ID Pinjaman Lama*', 'ID Dapin*', 'Modal Dialokasikan (Rp)*', 'Kewajiban Dapin (Rp)*', 'Tanggal Alokasi*', 'Catatan'],
            'rows' => $alokasiRows,
        ],
        'Saldo_Awal_Kas' => [
            'title' => 'SALDO AWAL KAS / REKONSILIASI',
            'headers' => ['Tanggal Cut-off*', 'Saldo Kas Fisik (Rp)*', 'Mutasi Historis Sudah Diinput?*', 'Dasar/Perhitungan Saldo*', 'Disetujui Oleh', 'Catatan'],
            'rows' => $saldoRows,
        ],
    ];

    $name = 'backup_migrasi_' . date('Y-m-d_H-i-s') . '.xlsx';
    $path = $backupDir . DIRECTORY_SEPARATOR . $name;
    migrasiTulisXlsx($path, $sheets);

    return [
        'path' => $path,
        'name' => $name,
        'counts' => [
            'nasabah' => count($nasabahRows),
            'produk' => count($produkRows),
            'pinjaman' => count($pinjamanRows),
            'angsuran' => count($angsuranRows),
            'dapin' => count($dapinRows),
        ],
    ];
}

/**
 * Backup lengkap: Excel format migrasi + seluruh folder uploads/.
 * Di hosting: ekstrak ZIP, letakkan isi uploads/ ke folder uploads aplikasi, lalu import Excel.
 *
 * @return array{path:string,name:string,counts:array,files:int}
 */
function migrasiExportBackupZip(PDO $db, $backupDir, $uploadsDir = null) {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi ZIP PHP belum aktif.');
    }
    // Cadangkan dokumen dulu agar reset+import berikutnya tetap bisa memulihkan foto/TTD.
    migrasiSimpanCadanganDokumen($db);
    $excel = migrasiExportBackupExcel($db, $backupDir);
    $uploadsDir = $uploadsDir ?: (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
    $name = 'backup_lengkap_' . date('Y-m-d_H-i-s') . '.zip';
    $path = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Gagal membuat file ZIP backup.');
    }
    $zip->addFile($excel['path'], 'migrasi/' . $excel['name']);
    $zip->addFromString(
        'README_RESTORE.txt',
        "RESTORE BACKUP LENGKAP SIMKEU\r\n"
        . "1. Ekstrak ZIP ini.\r\n"
        . "2. Salin seluruh isi folder uploads/ ke folder uploads/ di server aplikasi (timpa bila perlu).\r\n"
        . "3. Login Admin → Import Migrasi → unggah file di folder migrasi/*.xlsx → Pratinjau → Import Final.\r\n"
        . "4. Opsional: Backup SQL terpisah untuk restore struktur penuh via phpMyAdmin.\r\n"
        . "5. TTD Admin (pengaturan perjanjian) dipertahankan saat Reset Total; cek menu Pengaturan bila perlu.\r\n"
    );

    $fileCount = 0;
    if (is_dir($uploadsDir)) {
        $root = realpath($uploadsDir);
        if ($root !== false) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $fileInfo) {
                /** @var SplFileInfo $fileInfo */
                $full = $fileInfo->getPathname();
                $rel = substr($full, strlen($root) + 1);
                $rel = str_replace('\\', '/', $rel);
                if ($rel === '' || strpos($rel, '..') !== false) {
                    continue;
                }
                $entry = 'uploads/' . $rel;
                if ($fileInfo->isDir()) {
                    $zip->addEmptyDir(rtrim($entry, '/') . '/');
                } else {
                    $zip->addFile($full, $entry);
                    $fileCount++;
                }
            }
        }
    }
    $zip->close();

    if (!@rename($tmp, $path)) {
        @unlink($path);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Gagal menyimpan file ZIP backup.');
        }
    }

    $counts = $excel['counts'];
    $counts['upload_files'] = $fileCount;
    return ['path' => $path, 'name' => $name, 'counts' => $counts, 'files' => $fileCount, 'excel' => $excel['name']];
}
