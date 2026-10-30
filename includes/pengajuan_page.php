<?php
/**
 * Single implementation — Buat pengajuan pinjaman (Admin & Teller).
 * Persetujuan ada di includes/persetujuan_page.php.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}
if (!isAdmin() && !isTeller()) {
    http_response_code(403);
    exit('Akses ditolak.');
}

// Upload terlalu besar → pesan jelas, jangan lanjut POST kosong
redirectJikaPostMelebihiBatas('pengajuan.php');

// Handle form submission untuk pengajuan baru
if ($_POST && isset($_POST['ajukan_pinjaman'])) {
    $anggota_id = $_POST['anggota_id'];
    $jenis_pinjaman_id = $_POST['jenis_pinjaman_id'];
    $jumlah_pinjaman = $_POST['jumlah_pinjaman'];
    $tenor = (int) $_POST['tenor'];
    $frekuensi = isset($_POST['frekuensi']) ? $_POST['frekuensi'] : '';
    $tujuan_pinjaman = $_POST['tujuan_pinjaman'];
    $user_id = $_SESSION['user_id'];
    $kategori_shu_input = $_POST['kategori_shu'] ?? 'anggota';
    $ketua_kelompok_input = $_POST['ketua_kelompok_id'] ?? 0;

    try {
        layananValidasiToken();
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();

        // Get jenis pinjaman info for validation
        $query_jenis = "SELECT maksimal_pinjaman, tenor_max, frekuensi_diizinkan, memerlukan_jaminan FROM jenis_pinjaman WHERE id = :jenis_id AND status = 'aktif'";
        $stmt_jenis = $db->prepare($query_jenis);
        $stmt_jenis->bindParam(':jenis_id', $jenis_pinjaman_id);
        $stmt_jenis->execute();
        $jenis_info = $stmt_jenis->fetch(PDO::FETCH_ASSOC);
        
        if (!$jenis_info) {
            throw new Exception("Jenis pinjaman tidak valid atau tidak aktif");
        }
        $kategori_gadai = !empty($jenis_info['memerlukan_jaminan']) ? 1 : 0;
        // Hak SHU mengikuti jenis pinjaman, bukan awalan kode anggota:
        // pinjaman gadai tidak mendapat SHU, sedangkan pinjaman tanpa jaminan
        // tetap dapat memilih peran SHU/ketua termasuk untuk anggota AGTJ.
        if ($kategori_gadai) {
            $kategori_shu_input = 'anggota';
            $ketua_kelompok_input = 0;
        }

        // Kunci baris anggota agar dua pengajuan bersamaan tidak lolos validasi.
        $stmt_anggota_kunci = $db->prepare("SELECT id, kode_anggota, kode_anggota_jaminan, status, foto_kk, foto_ktp_pasangan
                                             FROM anggota WHERE id = ? FOR UPDATE");
        $stmt_anggota_kunci->execute([$anggota_id]);
        $anggota_info = $stmt_anggota_kunci->fetch(PDO::FETCH_ASSOC);
        if (!$anggota_info) {
            throw new Exception("Anggota tidak valid");
        }
        $dokumenAgtj = [];
        $agjtAjukanAgt = !$kategori_gadai
            && strpos((string) $anggota_info['kode_anggota'], 'AGTJ') === 0
            && (empty($anggota_info['foto_kk']) || empty($anggota_info['foto_ktp_pasangan']));
        if ($agjtAjukanAgt) {
            $dokumenAgtj = validasiDokumenAnggota($anggota_info);
            foreach (['foto_kk' => 'Foto Kartu Keluarga (KK)', 'foto_ktp_pasangan' => 'Foto KTP Suami/Istri'] as $field => $label) {
                if (empty($anggota_info[$field]) && empty($dokumenAgtj[$field])) {
                    throw new Exception($label . ' wajib diunggah untuk pengajuan AGT nasabah AGTJ');
                }
            }
        }
        list($kategori_shu, $ketua_kelompok_id) = shuValidasiProfil($db, $anggota_id, $kategori_shu_input, $ketua_kelompok_input);

        // Satu anggota maksimal satu pinjaman belum lunas untuk setiap kategori:
        // satu tanpa jaminan dan satu gadai boleh berjalan bersamaan.
        $stmt_aktif = $db->prepare("SELECT p.kode_pinjaman
            FROM pinjaman p
            JOIN jenis_pinjaman jp ON jp.id = p.jenis_pinjaman_id
            WHERE p.anggota_id = ? AND p.status <> 'lunas'
              AND (CASE WHEN jp.memerlukan_jaminan = 1 THEN 1 ELSE 0 END) = ?
            LIMIT 1");
        $stmt_aktif->execute([$anggota_id, $kategori_gadai]);
        $pinjaman_aktif = $stmt_aktif->fetchColumn();
        if ($pinjaman_aktif) {
            throw new Exception("Anggota masih mempunyai pinjaman " . ($kategori_gadai ? "gadai" : "tanpa jaminan") .
                " yang belum lunas ($pinjaman_aktif)");
        }

        // Pengajuan tetap berstatus dicairkan setelah pinjamannya lunas.
        // Riwayat tersebut tidak boleh menghalangi pengajuan berikutnya.
        $stmt_proses = $db->prepare("SELECT pp.kode_pengajuan
            FROM pengajuan_pinjaman pp
            JOIN jenis_pinjaman jp ON jp.id = pp.jenis_pinjaman_id
            WHERE pp.anggota_id = ? AND pp.status IN ('menunggu','disetujui','dicairkan')
              AND (CASE WHEN jp.memerlukan_jaminan = 1 THEN 1 ELSE 0 END) = ?
              AND NOT EXISTS (
                  SELECT 1 FROM pinjaman p_selesai
                  WHERE p_selesai.pengajuan_id = pp.id AND p_selesai.status = 'lunas'
              )
            LIMIT 1");
        $stmt_proses->execute([$anggota_id, $kategori_gadai]);
        $pengajuan_proses = $stmt_proses->fetchColumn();
        if ($pengajuan_proses) {
            throw new Exception("Anggota masih mempunyai pengajuan " . ($kategori_gadai ? "gadai" : "tanpa jaminan") .
                " yang sedang diproses ($pengajuan_proses)");
        }

        // Anggota yang sejak awal didaftarkan sebagai AGTJ memakai kode akun utamanya.
        // Anggota AGT lama baru memperoleh kode tambahan AGTJ saat pertama mengajukan gadai.
        if ($kategori_gadai && empty($anggota_info['kode_anggota_jaminan'])
            && strpos((string) $anggota_info['kode_anggota'], 'AGTJ') !== 0) {
            $terkunci = (int) $db->query("SELECT GET_LOCK('kode_anggota_jaminan_koperasi', 10)")->fetchColumn();
            if ($terkunci !== 1) {
                throw new Exception("Kode anggota gadai sedang dibuat oleh petugas lain. Silakan coba kembali");
            }
            try {
                $kode_gadai = buatKodeAnggotaJaminan($db);
                $stmt_kode_gadai = $db->prepare("UPDATE anggota SET kode_anggota_jaminan = ? WHERE id = ?");
                if (!$stmt_kode_gadai->execute([$kode_gadai, $anggota_id])) {
                    throw new Exception("Gagal menyimpan kode anggota gadai");
                }
                $anggota_info['kode_anggota_jaminan'] = $kode_gadai;
            } finally {
                $db->query("SELECT RELEASE_LOCK('kode_anggota_jaminan_koperasi')");
            }
        }
        if (!empty($jenis_info['memerlukan_jaminan'])) {
            // Gadai selalu menyediakan ketiga frekuensi dan tenor 1-6 bulan.
            // Ini juga menjaga jenis gadai lama tetap kompatibel tanpa disimpan ulang.
            $jenis_info['frekuensi_diizinkan'] = 'bulanan,mingguan,dua_mingguan';
        }
        
        $jumlah_pinjaman = parseRupiah($jumlah_pinjaman);
        if ($jumlah_pinjaman === false || $jumlah_pinjaman <= 0) {
            throw new Exception("Jumlah pinjaman harus berupa angka rupiah penuh lebih dari 0");
        }

        $fasilitas_prioritas=empty($_POST['fasilitas_prioritas'])?0:1;
        layananValidasiPrioritas($db,$anggota_id,$jumlah_pinjaman,$kategori_gadai,$fasilitas_prioritas);
        // Validate maksimal pinjaman
        if ($jumlah_pinjaman > $jenis_info['maksimal_pinjaman']) {
            throw new Exception("Jumlah pinjaman melebihi batas maksimal. Maksimal: " . formatRupiah($jenis_info['maksimal_pinjaman']));
        }
        
        // Validate tenor & bunga: hanya tenor yang punya tarif flat di jenis pinjaman ini
        $tarif = !empty($jenis_info['memerlukan_jaminan'])
            ? [1 => 15, 2 => 15, 3 => 15, 4 => 15, 5 => 15, 6 => 15]
            : getTarifJenisPinjaman($db, $jenis_pinjaman_id);
        if (!isset($tarif[$tenor])) {
            throw new Exception("Tenor $tenor bulan tidak tersedia untuk jenis pinjaman ini");
        }
        $bunga = $tarif[$tenor];

        // Validate frekuensi
        if (!in_array($frekuensi, explode(',', $jenis_info['frekuensi_diizinkan']), true)) {
            throw new Exception("Frekuensi angsuran tidak diizinkan untuk jenis pinjaman ini");
        }
        if (empty($jenis_info['memerlukan_jaminan']) && $frekuensi === 'dua_mingguan' && $tenor !== 6) {
            throw new Exception("Angsuran 2 minggu sekali tanpa jaminan hanya tersedia untuk tenor 6 bulan");
        }
        if (empty($jenis_info['memerlukan_jaminan']) && $frekuensi === 'dua_mingguan') {
            if (!in_array((int) $jumlah_pinjaman, [2000000, 3000000], true)) {
                throw new Exception("Tenor 6 bulan / 2 minggu sekali saat ini hanya tersedia untuk pinjaman Rp 2 juta atau Rp 3 juta");
            }
            // Sesuai tabel resmi: total kembali 145% dari pokok.
            $bunga = 45;
        }

        // Untuk gadai, tarif tenor adalah bunga dasar (umumnya 15%). Tambahan
        // Rp10/15/20 ribu per minggu dimasukkan ke bunga efektif yang disimpan.
        if (!empty($jenis_info['memerlukan_jaminan'])) {
            if ($tenor < 1 || $tenor > 6) {
                throw new Exception("Tenor pinjaman gadai harus antara 1 sampai 6 bulan");
            }
            $hitung_gadai = hitungPinjamanGadai($jumlah_pinjaman, $bunga, $tenor, $frekuensi);
            $bunga = $hitung_gadai['bunga_persen'];
        }

        $jaminan = null;
        if (!empty($jenis_info['memerlukan_jaminan'])) {
            $kategori = trim($_POST['kategori_jaminan'] ?? '');
            $wajib = function ($nama, $label) {
                $nilai = trim($_POST[$nama] ?? '');
                // Tolak kosong dan placeholder umum seperti "-" agar tidak lolos ke server.
                if ($nilai === '' || in_array($nilai, ['-', '–', '—', '.'], true)) {
                    throw new Exception("$label wajib diisi");
                }
                return $nilai;
            };
            if ($kategori === 'hp') {
                $jenis = $wajib('hp_jenis', 'Jenis HP');
                $merk = $wajib('hp_merk_tipe', 'Merk/tipe HP');
                $warna = $wajib('hp_warna', 'Warna HP');
                $imei = $wajib('hp_imei', 'IMEI');
                $nomor_seri = $wajib('hp_nomor_seri', 'Nomor seri HP');
                $nomor_model = $wajib('hp_nomor_model', 'Nomor model HP');
                $kelengkapan = $wajib('hp_kelengkapan', 'Kelengkapan HP');
                $kondisi = $wajib('hp_kondisi', 'Kondisi HP');
                $jenis_barang = 'HP - ' . $jenis;
                $identitas_barang = 'IMEI: ' . $imei;
                $deskripsi_barang = "Merk/tipe: $merk\nWarna: $warna\nNomor seri: $nomor_seri\nNomor model: $nomor_model\nKelengkapan: $kelengkapan\nKondisi: $kondisi";
            } elseif ($kategori === 'sepeda_motor') {
                $jenis = $wajib('motor_jenis_kendaraan', 'Jenis kendaraan');
                $merk = $wajib('motor_merk_tipe', 'Merk/tipe kendaraan');
                $tahun = $wajib('motor_tahun', 'Tahun kendaraan');
                $nopol = $wajib('motor_nopol', 'Nomor polisi');
                $rangka = $wajib('motor_no_rangka', 'Nomor rangka');
                $mesin = $wajib('motor_no_mesin', 'Nomor mesin');
                $warna = $wajib('motor_warna', 'Warna kendaraan');
                $atas_nama = $wajib('motor_atas_nama_bpkb', 'Atas nama BPKB');
                $no_bpkb = $wajib('motor_no_bpkb', 'Nomor BPKB');
                $kondisi = $wajib('motor_kondisi', 'Kondisi sepeda motor');
                $jenis_barang = 'Sepeda Motor - ' . $jenis;
                $identitas_barang = "Nopol: $nopol; BPKB: $no_bpkb";
                $deskripsi_barang = "Merk/tipe: $merk\nTahun: $tahun\nNo. rangka: $rangka\nNo. mesin: $mesin\nWarna: $warna\nAtas nama BPKB: $atas_nama\nKondisi: $kondisi";
            } elseif ($kategori === 'emas') {
                $jenis = $wajib('emas_jenis_barang', 'Jenis barang emas');
                $berat = $wajib('emas_berat', 'Berat emas');
                $kadar = $wajib('emas_kadar', 'Kadar emas');
                $ciri = $wajib('emas_bentuk_ciri', 'Bentuk/ciri emas');
                $surat = $wajib('emas_surat_nota', 'Surat/nota emas');
                $jenis_barang = 'Emas - ' . $jenis;
                $identitas_barang = 'Surat/nota: ' . $surat;
                $deskripsi_barang = "Berat: $berat\nKadar: $kadar\nBentuk/ciri: $ciri";
            } else {
                throw new Exception("Pilih kategori jaminan: HP, sepeda motor, atau emas");
            }
            $jaminan = [
                'jenis_barang' => $jenis_barang,
                'identitas_barang' => $identitas_barang,
                'deskripsi_barang' => $deskripsi_barang,
                // Kolom lama tetap diisi 0 agar kompatibel dengan database yang
                // mewajibkan nilai, tetapi tidak lagi dipakai oleh proses bisnis.
                'nilai_taksiran' => 0,
            ];
        }

        $hari_angsuran = !empty($jenis_info['memerlukan_jaminan'])
            ? null
            : (isset($_POST['hari_angsuran']) ? (int) $_POST['hari_angsuran'] : 0);
        if (empty($jenis_info['memerlukan_jaminan']) && ($hari_angsuran < 1 || $hari_angsuran > 7)) {
            throw new Exception("Hari angsuran wajib dipilih (Senin - Minggu)");
        }

        // Tanggal pengajuan (boleh lampau untuk data lama, tidak boleh masa depan)
        $tgl_ajuan = isset($_POST['tanggal_pengajuan']) ? trim($_POST['tanggal_pengajuan']) : date('Y-m-d');
        $dt = DateTime::createFromFormat('Y-m-d', $tgl_ajuan);
        if (!$dt || $dt->format('Y-m-d') !== $tgl_ajuan) {
            throw new Exception("Tanggal pengajuan tidak valid");
        }
        if ($tgl_ajuan > date('Y-m-d')) {
            throw new Exception("Tanggal pengajuan tidak boleh di masa depan");
        }
        $waktu_ajuan = $tgl_ajuan . ' ' . date('H:i:s');

        // Generate kode pengajuan
        // Maks. 20 karakter (= panjang kolom); format lama 22 karakter ditolak MySQL strict mode
        $kode_pengajuan = 'PJG-' . date('ymdHis') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

        // Insert pengajuan (skema flat)
        $query = "INSERT INTO pengajuan_pinjaman (kode_pengajuan, anggota_id, jenis_pinjaman_id,
                  jumlah_pinjaman, tenor, bunga, skema_bunga, frekuensi, tujuan_pinjaman, user_id, tanggal_pengajuan, hari_angsuran, fasilitas_prioritas, kategori_shu, ketua_kelompok_id)
                  VALUES (:kode_pengajuan, :anggota_id, :jenis_pinjaman_id,
                  :jumlah_pinjaman, :tenor, :bunga, 'flat', :frekuensi, :tujuan_pinjaman, :user_id, :tanggal_pengajuan, :hari_angsuran, :fasilitas_prioritas, :kategori_shu, :ketua_kelompok_id)";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':fasilitas_prioritas',$fasilitas_prioritas,PDO::PARAM_INT);
        $stmt->bindValue(':kategori_shu',$kategori_shu);
        $stmt->bindValue(':ketua_kelompok_id',$ketua_kelompok_id,$ketua_kelompok_id===null?PDO::PARAM_NULL:PDO::PARAM_INT);
        $stmt->bindParam(':tanggal_pengajuan', $waktu_ajuan);
        $stmt->bindValue(':hari_angsuran', $hari_angsuran, $hari_angsuran === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':kode_pengajuan', $kode_pengajuan);
        $stmt->bindParam(':anggota_id', $anggota_id);
        $stmt->bindParam(':jenis_pinjaman_id', $jenis_pinjaman_id);
        $stmt->bindParam(':jumlah_pinjaman', $jumlah_pinjaman);
        $stmt->bindParam(':tenor', $tenor);
        $stmt->bindParam(':bunga', $bunga);
        $stmt->bindParam(':frekuensi', $frekuensi);
        $stmt->bindParam(':tujuan_pinjaman', $tujuan_pinjaman);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $pengajuan_id = $db->lastInsertId();
        if ($jaminan !== null) {
            $stmt_jaminan = $db->prepare("INSERT INTO jaminan_pinjaman
                (pengajuan_id, jenis_barang, identitas_barang, deskripsi_barang, nilai_taksiran, status_jaminan)
                VALUES (?, ?, ?, ?, ?, 'dititipkan')");
            $stmt_jaminan->execute([$pengajuan_id, $jaminan['jenis_barang'], $jaminan['identitas_barang'],
                $jaminan['deskripsi_barang'], $jaminan['nilai_taksiran']]);
        }
        if ($dokumenAgtj) simpanDokumenAnggota($db, $anggota_id, $dokumenAgtj, $anggota_info);

        // Pengajuan baru mengaktifkan kembali akun lama; kode dan akun tetap sama.
        aktifkanKembaliAnggota($db, $anggota_id);
        
        $db->commit();
        $_SESSION['success'] = "Pengajuan pinjaman berhasil dibuat. Kode: " . $kode_pengajuan . " - Menunggu persetujuan admin";
        header("Location: pengajuan.php");
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "Gagal mengajukan pinjaman: " . $e->getMessage();
    }
}

// Get data for dropdowns
$query_anggota = "SELECT * FROM anggota ORDER BY status = 'aktif' DESC, nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);
$ketua_kelompok_list = array_values(array_filter($anggota_list, function($a){ return ($a['kategori_shu'] ?? 'anggota') === 'ketua_kelompok'; }));

$query_jenis = "SELECT * FROM jenis_pinjaman WHERE status = 'aktif' ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_pinjaman = [];
foreach ($stmt_jenis->fetchAll(PDO::FETCH_ASSOC) as $jenis) {
    // Jenis tanpa tarif flat (skema lama) tidak bisa diajukan sampai Admin mengisi tarifnya
    if (!empty($jenis['memerlukan_jaminan'])) {
        $jenis['tarif'] = [1 => 15, 2 => 15, 3 => 15, 4 => 15, 5 => 15, 6 => 15];
        $jenis['frekuensi_diizinkan'] = 'bulanan,mingguan,dua_mingguan';
    } else {
        $jenis['tarif'] = getTarifJenisPinjaman($db, $jenis['id']);
    }
    if (!empty($jenis['tarif'])) {
        $jenis_pinjaman[] = $jenis;
    }
}

// Riwayat: semua pengajuan milik petugas ini (filter/sort di DataTables client)
$query_pengajuan = "SELECT
                    pp.*,
                    a.nama_lengkap,
                    a.kode_anggota,
                    a.kode_anggota_jaminan,
                    jp.memerlukan_jaminan,
                    jp.nama_jenis,
                    jp.maksimal_pinjaman,
                    u.nama_lengkap as admin_name,
                    pp.tanggal_pengajuan,
                    pp.tanggal_persetujuan
                    FROM pengajuan_pinjaman pp
                    JOIN anggota a ON pp.anggota_id = a.id
                    JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
                    LEFT JOIN users u ON pp.admin_id = u.id
                    WHERE pp.user_id = :user_id
                    ORDER BY pp.tanggal_pengajuan DESC";
$stmt_pengajuan = $db->prepare($query_pengajuan);
$stmt_pengajuan->bindParam(':user_id', $_SESSION['user_id']);
$stmt_pengajuan->execute();
$pengajuan_list = $stmt_pengajuan->fetchAll(PDO::FETCH_ASSOC);

$query_stats = "SELECT
    COUNT(*) as total_pengajuan,
    COUNT(CASE WHEN status = 'menunggu' THEN 1 END) as menunggu,
    COUNT(CASE WHEN status = 'disetujui' THEN 1 END) as disetujui,
    COUNT(CASE WHEN status = 'ditolak' THEN 1 END) as ditolak
    FROM pengajuan_pinjaman
    WHERE user_id = :user_id";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->bindParam(':user_id', $_SESSION['user_id']);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$statusClassMap = [
    'menunggu' => 'warning',
    'disetujui' => 'success',
    'ditolak' => 'danger',
    'batal_cair' => 'secondary',
    'dicairkan' => 'info',
];
$prefillAnggotaId = isset($_GET['anggota_id']) && is_numeric($_GET['anggota_id']) ? (int)$_GET['anggota_id'] : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengajuan Pinjaman - <?php echo APP_NAME; ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-pinjaman" aria-hidden="true"><i class="ti ti-file-text"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Pinjaman</p>
                        <h1 class="ui-dash-title">Pengajuan Pinjaman</h1>
                        <p class="ui-dash-meta mb-0">Buat pengajuan pinjaman anggota — menunggu persetujuan admin</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <button type="button" class="btn ui-tx-btn-pinjaman" data-bs-toggle="modal" data-bs-target="#modalPengajuan">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Pengajuan baru</span><span class="d-sm-none">Baru</span>
                        </button>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-pj-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total pengajuan', 'value' => (int)$stats['total_pengajuan'], 'tone' => 'primary', 'icon' => 'ti ti-file-text', 'hint' => 'Semua'],
                    ['label' => 'Menunggu', 'value' => (int)$stats['menunggu'], 'tone' => 'warning', 'icon' => 'ti ti-clock', 'hint' => 'Perlu review'],
                    ['label' => 'Disetujui', 'value' => (int)$stats['disetujui'], 'tone' => 'success', 'icon' => 'ti ti-circle-check', 'hint' => 'Lanjut cair'],
                    ['label' => 'Ditolak', 'value' => (int)$stats['ditolak'], 'tone' => 'danger', 'icon' => 'ti ti-circle-x', 'hint' => 'Ditolak'],
                ]); ?>
                </div>

                <div class="card mt-3" id="riwayat">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-history" aria-hidden="true"></i>Riwayat Pengajuan Saya</span>
                        <span class="ui-dash-sec-note"><?= (int)count($pengajuan_list) ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,asc;1,desc"
                                   data-dt-filter="6"
                                   data-dt-date-col="1">
                                <thead>
                                    <tr>
                                        <th class="dt-prio">Prio</th>
                                        <th>Tanggal</th>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Jumlah</th>
                                        <th>Status</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pengajuan_list as $pengajuan):
                                        $ts = strtotime((string)($pengajuan['tanggal_pengajuan'] ?? ''));
                                        $orderTs = $ts ? date('Y-m-d H:i:s', $ts) : '';
                                        $day = $ts ? date('Y-m-d', $ts) : '';
                                        $statusTampil = $pengajuan['status'] === 'dicairkan' ? 'disetujui' : $pengajuan['status'];
                                        $badge = $statusClassMap[$statusTampil] ?? 'secondary';
                                        $kodeAnggota = !empty($pengajuan['memerlukan_jaminan'])
                                            ? ($pengajuan['kode_anggota_jaminan'] ?: $pengajuan['kode_anggota'])
                                            : $pengajuan['kode_anggota'];
                                        $statusLabel = $statusTampil === 'batal_cair' ? 'Batal Cair' : ucfirst((string)$statusTampil);
                                        // Teller: menunggu (lacak) & ditolak (bisa ajukan ulang) di atas
                                        if ($pengajuan['status'] === 'menunggu') {
                                            $prio = 0;
                                        } elseif ($pengajuan['status'] === 'ditolak' || $pengajuan['status'] === 'batal_cair') {
                                            $prio = 1;
                                        } elseif ($statusTampil === 'disetujui') {
                                            $prio = 2;
                                        } else {
                                            $prio = 9;
                                        }
                                    ?>
                                    <tr<?= $prio < 9 ? ' class="ui-row-action"' : '' ?>>
                                        <td><?= (int)$prio ?></td>
                                        <td data-order="<?= htmlspecialchars($orderTs) ?>" data-date="<?= htmlspecialchars($day) ?>">
                                            <?= htmlspecialchars(formatTanggalUi($pengajuan['tanggal_pengajuan'], true, true)) ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($pengajuan['kode_pengajuan']) ?></code></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($pengajuan['nama_lengkap']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars((string)$kodeAnggota) ?></small>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($pengajuan['nama_jenis']) ?></div>
                                            <small class="text-muted"><?= (int)$pengajuan['tenor'] ?> bln · <?= htmlspecialchars(labelHariAngsuran($pengajuan['hari_angsuran'] ?? null)) ?></small>
                                        </td>
                                        <td class="text-end fw-semibold"><?= formatRupiah($pengajuan['jumlah_pinjaman']) ?></td>
                                        <td data-filter="<?= htmlspecialchars($statusLabel) ?>">
                                            <span class="badge bg-<?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                            <?php if (!empty($pengajuan['admin_name'])): ?>
                                            <div class="small text-muted mt-1">Oleh: <?= htmlspecialchars($pengajuan['admin_name']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end table-actions">
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#detailModal"
                                                    data-kode="<?= htmlspecialchars($pengajuan['kode_pengajuan'], ENT_QUOTES) ?>"
                                                    data-nama="<?= htmlspecialchars($pengajuan['nama_lengkap'], ENT_QUOTES) ?>"
                                                    data-kode_anggota="<?= htmlspecialchars((string)$kodeAnggota, ENT_QUOTES) ?>"
                                                    data-jenis="<?= htmlspecialchars($pengajuan['nama_jenis'], ENT_QUOTES) ?>"
                                                    data-jumlah="<?= htmlspecialchars((string)$pengajuan['jumlah_pinjaman'], ENT_QUOTES) ?>"
                                                    data-tenor="<?= htmlspecialchars($pengajuan['tenor'] . ' bulan, ' . ringkasanAngsuran(hitungDariPinjaman($pengajuan)), ENT_QUOTES) ?>"
                                                    data-bunga="<?= htmlspecialchars(labelBunga($pengajuan['bunga'], $pengajuan['skema_bunga']), ENT_QUOTES) ?>"
                                                    data-tujuan="<?= htmlspecialchars((string)$pengajuan['tujuan_pinjaman'], ENT_QUOTES) ?>"
                                                    data-status="<?= htmlspecialchars($statusTampil, ENT_QUOTES) ?>"
                                                    data-tanggal="<?= htmlspecialchars(formatTanggalUi($pengajuan['tanggal_pengajuan'], true), ENT_QUOTES) ?>"
                                                    data-catatan="<?= htmlspecialchars((string)($pengajuan['catatan_admin'] ?? ''), ENT_QUOTES) ?>"
                                                    data-admin="<?= htmlspecialchars((string)($pengajuan['admin_name'] ?? ''), ENT_QUOTES) ?>"
                                                    data-pengaju="<?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Petugas', ENT_QUOTES) ?>"
                                                    data-tanggal_persetujuan="<?= htmlspecialchars($pengajuan['tanggal_persetujuan'] ? formatTanggalUi($pengajuan['tanggal_persetujuan'], true) : '', ENT_QUOTES) ?>"
                                                    title="Detail">
                                                <i class="ti ti-eye" aria-hidden="true"></i>
                                                <span class="d-none d-md-inline ms-1">Detail</span>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php include __DIR__ . '/layout/shell_end.php'; ?>

    <div class="modal fade ui-tx-modal ui-tx-modal-pinjaman" id="modalPengajuan" tabindex="-1" aria-labelledby="modalPengajuanLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalPengajuanLabel">Pengajuan baru</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formPengajuan" novalidate>
                    <?php echo layananToken(); ?>
                    <input type="hidden" name="ajukan_pinjaman" value="1">
                    <div class="modal-body">
                        <div class="ui-pj-form">
                            <section class="ui-form-section ui-pj-section">
                                <h6 class="ui-form-section-title"><i class="ti ti-user" aria-hidden="true"></i>Nasabah &amp; produk</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="anggotaSelect">Anggota *</label>
                                        <select name="anggota_id" class="form-select" required id="anggotaSelect" data-prefill="<?= (int)$prefillAnggotaId ?>">
                                            <option value="">-- Pilih anggota --</option>
                                            <?php foreach ($anggota_list as $anggota): ?>
                                            <option value="<?= (int)$anggota['id'] ?>"
                                                    data-kategori-shu="<?= htmlspecialchars($anggota['kategori_shu'] ?? 'anggota') ?>"
                                                    data-ketua-id="<?= (int)($anggota['ketua_kelompok_id'] ?? 0) ?>"
                                                    data-kode-utama="<?= htmlspecialchars($anggota['kode_anggota'] ?? '') ?>"
                                                    data-kode-jaminan="<?= htmlspecialchars($anggota['kode_anggota_jaminan'] ?? '') ?>"
                                                    data-foto-kk="<?= empty($anggota['foto_kk']) ? '0' : '1' ?>"
                                                    data-foto-ktp-pasangan="<?= empty($anggota['foto_ktp_pasangan']) ? '0' : '1' ?>">
                                                <?= htmlspecialchars((!empty($anggota['kode_anggota_jaminan']) ? $anggota['kode_anggota_jaminan'] : $anggota['kode_anggota']) . ' - ' . $anggota['nama_lengkap']) ?>
                                                <?= $anggota['status'] !== 'aktif' ? ' (Nonaktif — diaktifkan saat pengajuan)' : '' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="jenisPinjamanSelect">Jenis pinjaman *</label>
                                        <select name="jenis_pinjaman_id" class="form-select" required id="jenisPinjamanSelect">
                                            <option value="">-- Pilih jenis --</option>
                                            <?php foreach ($jenis_pinjaman as $jenis): ?>
                                            <option value="<?= (int)$jenis['id'] ?>"
                                                    data-maksimal="<?= htmlspecialchars((string)$jenis['maksimal_pinjaman']) ?>"
                                                    data-tarif="<?= htmlspecialchars(json_encode((object)$jenis['tarif']), ENT_QUOTES) ?>"
                                                    data-frekuensi="<?= htmlspecialchars($jenis['frekuensi_diizinkan']) ?>"
                                                    data-jaminan="<?= !empty($jenis['memerlukan_jaminan']) ? '1' : '0' ?>">
                                                <?= htmlspecialchars($jenis['nama_jenis'] . (!empty($jenis['memerlukan_jaminan']) ? ' — Gadai' : ' — Tanpa jaminan') . ' — Maks ' . formatRupiah($jenis['maksimal_pinjaman']), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="ui-pj-prioritas">
                                            <input class="form-check-input" type="checkbox" name="fasilitas_prioritas" value="1" id="fasilitasPrioritas">
                                            <span class="ui-pj-prioritas-body">
                                                <span class="ui-pj-prioritas-title"><i class="ti ti-star me-1" aria-hidden="true"></i>Fasilitas anggota prioritas</span>
                                                <span class="ui-pj-prioritas-note">Tanpa jaminan · maks. Rp 1 juta · wajib persetujuan Admin</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </section>

                            <div id="dokumenAgtjAgt" class="ui-form-section ui-pj-section ui-pj-docs d-none">
                                <h6 class="ui-form-section-title ui-pj-docs-title"><i class="ti ti-photo" aria-hidden="true"></i>Upload dokumen AGT</h6>
                                <p class="ui-pj-section-note mb-3">Nasabah AGTJ belum punya dokumen lengkap. Unggah foto yang hilang sebelum pengajuan dikirim. Format JPG/PNG/WEBP · otomatis dikompres di bawah 1 MB sebelum diunggah.</p>
                                <div class="row g-3 dokumen-anggota" data-prefix="pengajuan">
                                    <div class="col-md-6 mb-0" id="wrapFotoKkPengajuan" hidden>
                                        <div class="ui-pj-upload">
                                            <label class="form-label label-dokumen" for="foto_kk_pengajuan" data-field="foto_kk" data-label="Foto Kartu Keluarga (KK)">
                                                Foto Kartu Keluarga (KK) <span class="text-danger">*</span>
                                            </label>
                                            <input type="file" name="foto_kk" id="foto_kk_pengajuan"
                                                   class="form-control form-control-sm input-dokumen"
                                                   data-field="foto_kk"
                                                   data-label="Foto Kartu Keluarga (KK)"
                                                   data-wajib="1"
                                                   accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                            <div class="preview-dokumen ui-pj-upload-preview mt-2" id="pengajuan_preview_foto_kk" aria-live="polite"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-0" id="wrapFotoKtpPasanganPengajuan" hidden>
                                        <div class="ui-pj-upload">
                                            <label class="form-label label-dokumen" for="foto_ktp_pasangan_pengajuan" data-field="foto_ktp_pasangan" data-label="Foto KTP Suami/Istri">
                                                Foto KTP Suami/Istri <span class="text-danger">*</span>
                                            </label>
                                            <input type="file" name="foto_ktp_pasangan" id="foto_ktp_pasangan_pengajuan"
                                                   class="form-control form-control-sm input-dokumen"
                                                   data-field="foto_ktp_pasangan"
                                                   data-label="Foto KTP Suami/Istri"
                                                   data-wajib="1"
                                                   accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                            <div class="preview-dokumen ui-pj-upload-preview mt-2" id="pengajuan_preview_foto_ktp_pasangan" aria-live="polite"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <section class="ui-form-section ui-pj-section" id="shuPengajuanWrap">
                                <h6 class="ui-form-section-title"><i class="ti ti-percentage" aria-hidden="true"></i>Peran SHU</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="kategori_shu_pengajuan">Peran pada pengajuan *</label>
                                        <select name="kategori_shu" id="kategori_shu_pengajuan" class="form-select" required>
                                            <option value="anggota">Anggota (SHU 1% saat lunas)</option>
                                            <option value="ketua_kelompok">Ketua Kelompok (SHU 2% saat lunas)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6" id="ketua_kelompok_wrap">
                                        <label class="form-label" for="ketua_kelompok_id">Ketua yang mengajak</label>
                                        <select name="ketua_kelompok_id" id="ketua_kelompok_id" class="form-select">
                                            <option value="">Tidak ada</option>
                                            <?php foreach ($ketua_kelompok_list as $ketua): ?>
                                            <option value="<?= (int)$ketua['id'] ?>"><?= htmlspecialchars($ketua['nama_lengkap'] . ' · ' . $ketua['kode_anggota']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Rp2.500 / Rp1 juta jika seluruh pembayaran tepat waktu</small>
                                    </div>
                                </div>
                            </section>

                            <section class="ui-form-section ui-pj-section ui-pj-amount">
                                <h6 class="ui-form-section-title"><i class="ti ti-cash" aria-hidden="true"></i>Nominal &amp; tenor</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="jumlahPinjamanDisplay">Jumlah pinjaman *</label>
                                        <div class="ui-pj-money">
                                            <span class="ui-pj-money-prefix" aria-hidden="true">Rp</span>
                                            <input type="text" class="form-control ui-pj-money-input" id="jumlahPinjamanDisplay"
                                                   inputmode="numeric" autocomplete="off" placeholder="2.000.000" required>
                                        </div>
                                        <input type="hidden" name="jumlah_pinjaman" id="jumlahPinjaman" value="">
                                        <small class="text-muted" id="maxInfo"></small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="tenorSelect">Tenor *</label>
                                        <select name="tenor" class="form-select" required id="tenorSelect">
                                            <option value="">-- Pilih jenis pinjaman dulu --</option>
                                        </select>
                                        <small class="text-muted" id="tenorInfo"></small>
                                    </div>
                                </div>
                            </section>

                            <section id="jaminanFields" class="ui-form-section ui-pj-section ui-pj-jaminan" hidden>
                                <h6 class="ui-form-section-title"><i class="ti ti-box" aria-hidden="true"></i>Barang jaminan</h6>
                                <p class="ui-pj-section-note mb-3">Pilih kategori HP / motor / emas, lalu isi <strong>semua</strong> kolom bertanda * (tidak boleh kosong atau hanya “-”).</p>
                                <div class="ui-pj-choices ui-pj-choices-3 mb-3" role="radiogroup" aria-label="Kategori jaminan">
                                    <div class="ui-pj-choice">
                                        <input class="btn-check kategori-jaminan" type="radio" name="kategori_jaminan" value="hp" id="jaminanHp">
                                        <label class="ui-pj-choice-btn" for="jaminanHp"><i class="ti ti-device-mobile" aria-hidden="true"></i>HP</label>
                                    </div>
                                    <div class="ui-pj-choice">
                                        <input class="btn-check kategori-jaminan" type="radio" name="kategori_jaminan" value="sepeda_motor" id="jaminanMotor">
                                        <label class="ui-pj-choice-btn" for="jaminanMotor"><i class="ti ti-motorbike" aria-hidden="true"></i>Motor</label>
                                    </div>
                                    <div class="ui-pj-choice">
                                        <input class="btn-check kategori-jaminan" type="radio" name="kategori_jaminan" value="emas" id="jaminanEmas">
                                        <label class="ui-pj-choice-btn" for="jaminanEmas"><i class="ti ti-diamond" aria-hidden="true"></i>Emas</label>
                                    </div>
                                </div>
                                <div class="jaminan-detail ui-pj-jaminan-detail" data-kategori="hp" hidden>
                                    <div class="row g-2">
                                        <div class="col-md-6"><label class="form-label">Jenis *</label><input class="form-control detail-required" name="hp_jenis"></div>
                                        <div class="col-md-6"><label class="form-label">Merk/tipe HP *</label><input class="form-control detail-required" name="hp_merk_tipe"></div>
                                        <div class="col-md-6"><label class="form-label">Warna *</label><input class="form-control detail-required" name="hp_warna"></div>
                                        <div class="col-md-6"><label class="form-label">IMEI *</label><input class="form-control detail-required" name="hp_imei"></div>
                                        <div class="col-md-6"><label class="form-label">Nomor seri *</label><input class="form-control detail-required" name="hp_nomor_seri"></div>
                                        <div class="col-md-6"><label class="form-label">Nomor model *</label><input class="form-control detail-required" name="hp_nomor_model"></div>
                                        <div class="col-md-6"><label class="form-label">Kelengkapan *</label><input class="form-control detail-required" name="hp_kelengkapan"></div>
                                        <div class="col-md-6"><label class="form-label">Kondisi *</label><input class="form-control detail-required" name="hp_kondisi"></div>
                                    </div>
                                </div>
                                <div class="jaminan-detail ui-pj-jaminan-detail" data-kategori="sepeda_motor" hidden>
                                    <div class="row g-2">
                                        <div class="col-md-6"><label class="form-label">Jenis kendaraan *</label><input class="form-control detail-required" name="motor_jenis_kendaraan"></div>
                                        <div class="col-md-6"><label class="form-label">Merk/tipe *</label><input class="form-control detail-required" name="motor_merk_tipe"></div>
                                        <div class="col-md-4"><label class="form-label">Tahun *</label><input type="number" min="1900" max="<?= date('Y') + 1 ?>" class="form-control detail-required" name="motor_tahun"></div>
                                        <div class="col-md-4"><label class="form-label">Nopol *</label><input class="form-control detail-required" name="motor_nopol"></div>
                                        <div class="col-md-4"><label class="form-label">Warna *</label><input class="form-control detail-required" name="motor_warna"></div>
                                        <div class="col-md-6"><label class="form-label">No. rangka *</label><input class="form-control detail-required" name="motor_no_rangka"></div>
                                        <div class="col-md-6"><label class="form-label">No. mesin *</label><input class="form-control detail-required" name="motor_no_mesin"></div>
                                        <div class="col-md-6"><label class="form-label">Atas nama BPKB *</label><input class="form-control detail-required" name="motor_atas_nama_bpkb"></div>
                                        <div class="col-md-6"><label class="form-label">No. BPKB *</label><input class="form-control detail-required" name="motor_no_bpkb"></div>
                                        <div class="col-12"><label class="form-label">Kondisi *</label><input class="form-control detail-required" name="motor_kondisi"></div>
                                    </div>
                                </div>
                                <div class="jaminan-detail ui-pj-jaminan-detail" data-kategori="emas" hidden>
                                    <div class="row g-2">
                                        <div class="col-md-6"><label class="form-label">Jenis barang *</label><input class="form-control detail-required" name="emas_jenis_barang"></div>
                                        <div class="col-md-6"><label class="form-label">Berat *</label><input class="form-control detail-required" name="emas_berat" placeholder="Contoh: 10 gram"></div>
                                        <div class="col-md-6"><label class="form-label">Kadar *</label><input class="form-control detail-required" name="emas_kadar" placeholder="Contoh: 22 karat"></div>
                                        <div class="col-md-6"><label class="form-label">Bentuk/ciri *</label><input class="form-control detail-required" name="emas_bentuk_ciri"></div>
                                        <div class="col-12"><label class="form-label">Surat/nota *</label><input class="form-control detail-required" name="emas_surat_nota"></div>
                                    </div>
                                </div>
                            </section>

                            <section class="ui-form-section ui-pj-section">
                                <h6 class="ui-form-section-title"><i class="ti ti-calendar-event" aria-hidden="true"></i>Jadwal angsuran</h6>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label d-block">Frekuensi *</label>
                                        <div class="ui-pj-choices ui-pj-choices-3" role="radiogroup" aria-label="Frekuensi angsuran">
                                            <div class="ui-pj-choice">
                                                <input class="btn-check" type="radio" name="frekuensi" id="frekBulanan" value="bulanan" required>
                                                <label class="ui-pj-choice-btn" for="frekBulanan"><i class="ti ti-calendar-month" aria-hidden="true"></i>Bulanan</label>
                                            </div>
                                            <div class="ui-pj-choice">
                                                <input class="btn-check" type="radio" name="frekuensi" id="frekMingguan" value="mingguan" required>
                                                <label class="ui-pj-choice-btn" for="frekMingguan"><i class="ti ti-calendar-week" aria-hidden="true"></i>Mingguan</label>
                                            </div>
                                            <div class="ui-pj-choice">
                                                <input class="btn-check" type="radio" name="frekuensi" id="frekDuaMingguan" value="dua_mingguan" required>
                                                <label class="ui-pj-choice-btn" for="frekDuaMingguan"><i class="ti ti-calendar-time" aria-hidden="true"></i>2 minggu</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6" id="hariAngsuranGroup">
                                        <label class="form-label" for="hariAngsuranSelect">Hari angsuran *</label>
                                        <select name="hari_angsuran" class="form-select" id="hariAngsuranSelect" required>
                                            <option value="">-- Pilih hari --</option>
                                            <?php foreach (daftarHariAngsuran() as $no => $nama_hari): ?>
                                            <option value="<?= (int)$no ?>"><?= htmlspecialchars($nama_hari) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Untuk filter menu Angsuran</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="tanggalPengajuan">Tanggal pengajuan *</label>
                                        <input type="date" name="tanggal_pengajuan" id="tanggalPengajuan" class="form-control" required
                                               value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                                        <small class="text-muted">Boleh tanggal lampau untuk data lama</small>
                                    </div>
                                </div>
                            </section>

                            <section class="ui-form-section ui-pj-section mb-0">
                                <h6 class="ui-form-section-title"><i class="ti ti-notes" aria-hidden="true"></i>Tujuan &amp; ringkasan</h6>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label" for="tujuanPinjaman">Tujuan pinjaman *</label>
                                        <textarea name="tujuan_pinjaman" id="tujuanPinjaman" class="form-control" rows="2" required
                                                  placeholder="Jelaskan tujuan penggunaan pinjaman…"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <div class="ui-pj-calc" id="calculationInfo" hidden>
                                            <div class="ui-pj-calc-head">
                                                <span class="ui-pj-calc-ico" aria-hidden="true"><i class="ti ti-calculator"></i></span>
                                                <div>
                                                    <div class="ui-pj-calc-title">Perhitungan pinjaman</div>
                                                    <div class="ui-pj-calc-sub">Estimasi berdasarkan jumlah, tenor, dan frekuensi</div>
                                                </div>
                                            </div>
                                            <div class="ui-pj-calc-grid">
                                                <div class="ui-pj-calc-card">
                                                    <span class="ui-pj-calc-label">Bunga (flat)</span>
                                                    <div class="ui-pj-calc-value" id="displayBunga">0%</div>
                                                </div>
                                                <div class="ui-pj-calc-card">
                                                    <span class="ui-pj-calc-label">Total kembali</span>
                                                    <div class="ui-pj-calc-value is-total" id="displayTotal">Rp 0</div>
                                                </div>
                                                <div class="ui-pj-calc-card is-accent">
                                                    <span class="ui-pj-calc-label" id="labelAngsuran">Angsuran/bulan</span>
                                                    <div class="ui-pj-calc-value is-pay" id="displayAngsuran">Rp 0</div>
                                                    <div class="ui-pj-calc-note" id="displayCicilan"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="ui-pj-hint mb-0"><i class="ti ti-info-circle" aria-hidden="true"></i>Pengajuan dikirim untuk persetujuan admin (biasanya 1–2 hari kerja).</p>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary" id="btnAjukanPinjaman">
                            <i class="ti ti-send me-1" aria-hidden="true"></i>Lanjut konfirmasi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalKonfirmasiPengajuan" tabindex="-1" aria-labelledby="modalKonfirmasiPengajuanLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalKonfirmasiPengajuanLabel">Konfirmasi pengajuan</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <dl class="ui-tx-confirm-dl">
                        <div><dt>Anggota</dt><dd id="cfmAnggota">—</dd></div>
                        <div><dt>Jenis</dt><dd id="cfmJenis">—</dd></div>
                        <div><dt>Jumlah</dt><dd id="cfmJumlah">—</dd></div>
                        <div><dt>Tenor</dt><dd id="cfmTenor">—</dd></div>
                        <div><dt>Frekuensi</dt><dd id="cfmFrekuensi">—</dd></div>
                        <div><dt>Angsuran</dt><dd id="cfmAngsuran">—</dd></div>
                    </dl>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Periksa lagi</button>
                    <button type="button" class="btn btn-primary" id="btnKonfirmasiPengajuan">
                        <i class="ti ti-check me-1" aria-hidden="true"></i>Ya, ajukan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tx-modal ui-tx-modal-pinjaman" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="detailModalLabel">Detail pengajuan</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="ui-pj-detail">
                        <div class="ui-pj-detail-hero">
                            <div class="ui-pj-detail-hero-main min-w-0">
                                <div class="ui-pj-detail-kode" id="detail_kode">—</div>
                                <div class="ui-pj-detail-nama text-truncate" id="detail_nama">—</div>
                                <div class="ui-pj-detail-meta" id="detail_kode_anggota">—</div>
                            </div>
                            <div class="ui-pj-detail-hero-status" id="detail_status">—</div>
                        </div>

                        <div class="ui-pj-detail-amount">
                            <div class="ui-pj-detail-amount-item">
                                <span class="ui-pj-detail-amount-label">Jumlah pinjaman</span>
                                <strong class="ui-pj-detail-amount-value" id="detail_jumlah">—</strong>
                            </div>
                            <div class="ui-pj-detail-amount-item">
                                <span class="ui-pj-detail-amount-label">Jenis</span>
                                <strong class="ui-pj-detail-amount-sub" id="detail_jenis">—</strong>
                            </div>
                        </div>

                        <div class="ui-pj-detail-grid">
                            <section class="ui-pj-detail-card">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-percentage" aria-hidden="true"></i>Syarat pinjaman</h3>
                                <dl class="ui-pj-detail-dl">
                                    <div><dt>Tenor</dt><dd id="detail_tenor">—</dd></div>
                                    <div><dt>Bunga</dt><dd id="detail_bunga">—</dd></div>
                                </dl>
                            </section>
                            <section class="ui-pj-detail-card">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-send" aria-hidden="true"></i>Pengajuan</h3>
                                <dl class="ui-pj-detail-dl">
                                    <div><dt>Tanggal</dt><dd id="detail_tanggal">—</dd></div>
                                    <div><dt>Diajukan oleh</dt><dd id="detail_pengaju">—</dd></div>
                                </dl>
                            </section>
                            <section class="ui-pj-detail-card">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-gavel" aria-hidden="true"></i>Persetujuan</h3>
                                <dl class="ui-pj-detail-dl">
                                    <div><dt>Admin</dt><dd id="detail_admin">—</dd></div>
                                    <div><dt>Tanggal putusan</dt><dd id="detail_tanggal_persetujuan">—</dd></div>
                                    <div><dt>Catatan</dt><dd id="detail_catatan">—</dd></div>
                                </dl>
                            </section>
                            <section class="ui-pj-detail-card ui-pj-detail-card-wide">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-notes" aria-hidden="true"></i>Tujuan pinjaman</h3>
                                <p class="ui-pj-detail-tujuan mb-0" id="detail_tujuan">—</p>
                            </section>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/pinjaman.js?v=20261017g"></script>
    <script>
    (function () {
        const form = document.getElementById('formPengajuan');
        const anggotaSelect = document.getElementById('anggotaSelect');
        const jenisSelect = document.getElementById('jenisPinjamanSelect');
        const jumlahDisplay = document.getElementById('jumlahPinjamanDisplay');
        const jumlahHidden = document.getElementById('jumlahPinjaman');
        const tenorSelect = document.getElementById('tenorSelect');
        const calculationInfo = document.getElementById('calculationInfo');
        const jaminanFields = document.getElementById('jaminanFields');
        let allowSubmit = false;

        function formatRp(n) {
            n = Math.max(0, Math.floor(Number(n) || 0));
            return 'Rp ' + n.toLocaleString('id-ID');
        }
        function parseRp(str) {
            return parseInt(String(str || '').replace(/\D/g, ''), 10) || 0;
        }
        function setAmount(n) {
            n = Math.max(0, Math.floor(Number(n) || 0));
            jumlahHidden.value = n > 0 ? String(n) : '';
            jumlahDisplay.value = n ? n.toLocaleString('id-ID') : '';
            updateCalculation();
        }
        function getTarifTerpilih() {
            const opt = jenisSelect.selectedOptions[0];
            return opt && opt.value ? JSON.parse(opt.getAttribute('data-tarif') || '{}') : {};
        }
        function getFrekuensiTerpilih() {
            const r = document.querySelector('input[name="frekuensi"]:checked');
            return r ? r.value : '';
        }
        function frekuensiLabel(f) {
            return f === 'mingguan' ? 'Mingguan' : (f === 'dua_mingguan' ? '2 minggu sekali' : 'Bulanan');
        }
        function selectedAnggotaLabel() {
            const o = anggotaSelect.options[anggotaSelect.selectedIndex];
            return o && o.value ? o.textContent.trim() : '—';
        }
        function selectedJenisLabel() {
            const o = jenisSelect.options[jenisSelect.selectedIndex];
            if (!o || !o.value) return '—';
            return o.textContent.split('—')[0].trim();
        }

        function sesuaikanPeranShu(ambilProfil) {
            const opt = anggotaSelect.selectedOptions[0];
            const kategori = document.getElementById('kategori_shu_pengajuan');
            const ketua = document.getElementById('ketua_kelompok_id');
            const shuWrap = document.getElementById('shuPengajuanWrap');
            const jenisOpt = jenisSelect.selectedOptions[0];
            const gadai = !!(jenisOpt && jenisOpt.value && jenisOpt.getAttribute('data-jaminan') === '1');
            if (ambilProfil && opt && opt.value) {
                kategori.value = opt.getAttribute('data-kategori-shu') || 'anggota';
                ketua.value = opt.getAttribute('data-ketua-id') || '';
            }
            if (shuWrap) shuWrap.style.display = gadai ? 'none' : '';
            if (gadai) { kategori.value = 'anggota'; ketua.value = ''; }
            kategori.disabled = gadai;
            const sebagaiKetua = !gadai && kategori.value === 'ketua_kelompok';
            document.getElementById('ketua_kelompok_wrap').style.display = sebagaiKetua ? 'none' : '';
            ketua.disabled = gadai || sebagaiKetua;
            if (sebagaiKetua) ketua.value = '';
        }

        function clearFotoPreview(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);
            if (input) {
                input.value = '';
                input.required = false;
                const status = input.parentNode && input.parentNode.querySelector('.foto-upload-status');
                if (status) {
                    status.textContent = 'Belum ada foto dipilih';
                    status.classList.remove('has-file');
                }
            }
            if (preview) preview.innerHTML = '';
        }

        function renderFotoPreview(input, previewId) {
            const preview = document.getElementById(previewId);
            if (!preview) return;
            preview.innerHTML = '';
            const file = input.files && input.files[0];
            if (!file || !/^image\//i.test(file.type)) return;
            const url = URL.createObjectURL(file);
            const img = document.createElement('img');
            img.src = url;
            img.alt = 'Pratinjau ' + (input.getAttribute('data-label') || 'foto');
            img.className = 'img-thumbnail thumb-dokumen ui-pj-upload-thumb';
            img.onload = function () { URL.revokeObjectURL(url); };
            preview.appendChild(img);
            const meta = document.createElement('div');
            meta.className = 'small text-muted mt-1';
            meta.textContent = 'Siap diunggah — ganti file jika perlu';
            preview.appendChild(meta);
        }

        function sesuaikanDokumenAgtjAgt() {
            const anggota = anggotaSelect.selectedOptions[0];
            const jenis = jenisSelect.selectedOptions[0];
            const agtj = anggota && (anggota.getAttribute('data-kode-utama') || '').indexOf('AGTJ') === 0;
            const tanpaJaminan = jenis && jenis.value && jenis.getAttribute('data-jaminan') !== '1';
            const butuhKk = !!(agtj && tanpaJaminan && anggota.getAttribute('data-foto-kk') === '0');
            const butuhPasangan = !!(agtj && tanpaJaminan && anggota.getAttribute('data-foto-ktp-pasangan') === '0');
            const tampil = butuhKk || butuhPasangan;

            const wrapKk = document.getElementById('wrapFotoKkPengajuan');
            const wrapPasangan = document.getElementById('wrapFotoKtpPasanganPengajuan');
            const inputKk = document.getElementById('foto_kk_pengajuan');
            const inputPasangan = document.getElementById('foto_ktp_pasangan_pengajuan');

            document.getElementById('dokumenAgtjAgt').classList.toggle('d-none', !tampil);
            if (wrapKk) wrapKk.hidden = !butuhKk;
            if (wrapPasangan) wrapPasangan.hidden = !butuhPasangan;

            if (inputKk) {
                inputKk.required = butuhKk;
                inputKk.disabled = !butuhKk;
                if (!butuhKk) clearFotoPreview('foto_kk_pengajuan', 'pengajuan_preview_foto_kk');
            }
            if (inputPasangan) {
                inputPasangan.required = butuhPasangan;
                inputPasangan.disabled = !butuhPasangan;
                if (!butuhPasangan) clearFotoPreview('foto_ktp_pasangan_pengajuan', 'pengajuan_preview_foto_ktp_pasangan');
            }
            if (!tampil) {
                clearFotoPreview('foto_kk_pengajuan', 'pengajuan_preview_foto_kk');
                clearFotoPreview('foto_ktp_pasangan_pengajuan', 'pengajuan_preview_foto_ktp_pasangan');
            }
        }

        document.getElementById('foto_kk_pengajuan').addEventListener('change', function () {
            renderFotoPreview(this, 'pengajuan_preview_foto_kk');
        });
        document.getElementById('foto_ktp_pasangan_pengajuan').addEventListener('change', function () {
            renderFotoPreview(this, 'pengajuan_preview_foto_ktp_pasangan');
        });

        function isBlankJaminan(nilai) {
            const v = String(nilai || '').trim();
            return v === '' || v === '-' || v === '–' || v === '—' || v === '.';
        }

        function labelFieldJaminan(el) {
            const wrap = el.closest('[class*="col"]');
            const lab = wrap && wrap.querySelector('label');
            return ((lab && lab.textContent) || el.getAttribute('name') || 'Kolom')
                .replace(/\*/g, '')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function aturKategoriJaminan() {
            const dipilih = document.querySelector('input[name="kategori_jaminan"]:checked');
            const kategori = dipilih ? dipilih.value : '';
            document.querySelectorAll('.jaminan-detail').forEach(function (panel) {
                const aktif = panel.getAttribute('data-kategori') === kategori;
                panel.hidden = !aktif;
                panel.querySelectorAll('.detail-required').forEach(function (el) {
                    el.required = aktif;
                    if (!aktif) {
                        el.classList.remove('is-invalid');
                    }
                });
            });
        }

        function validasiDetailJaminan() {
            const dipilih = document.querySelector('input[name="kategori_jaminan"]:checked');
            if (!dipilih) {
                window.uiToast && uiToast('warning', 'Pilih kategori jaminan: HP, motor, atau emas');
                return false;
            }
            const panel = document.querySelector('.jaminan-detail[data-kategori="' + dipilih.value + '"]');
            if (!panel) return false;
            let firstBad = null;
            let jumlahKosong = 0;
            panel.querySelectorAll('.detail-required').forEach(function (el) {
                const kosong = isBlankJaminan(el.value);
                el.classList.toggle('is-invalid', kosong);
                if (kosong) {
                    jumlahKosong += 1;
                    if (!firstBad) firstBad = el;
                }
            });
            if (!firstBad) return true;
            const nama = labelFieldJaminan(firstBad);
            const pesan = jumlahKosong > 1
                ? nama + ' dan ' + (jumlahKosong - 1) + ' kolom lain masih kosong — isi semua kolom bertanda *'
                : nama + ' wajib diisi';
            window.uiToast && uiToast('warning', pesan);
            try {
                firstBad.focus({ preventScroll: true });
            } catch (err) {
                firstBad.focus();
            }
            firstBad.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
            return false;
        }

        function updateJenisMeta() {
            const selectedOption = jenisSelect.options[jenisSelect.selectedIndex];
            tenorSelect.innerHTML = '<option value="">-- Pilih tenor --</option>';
            if (!selectedOption.value) {
                setAmount(0);
                jumlahHidden.removeAttribute('max');
                jaminanFields.hidden = true;
                document.querySelectorAll('.kategori-jaminan, .detail-required').forEach(function (el) { el.required = false; });
                document.getElementById('hariAngsuranGroup').style.display = '';
                document.getElementById('hariAngsuranSelect').required = true;
                document.getElementById('maxInfo').textContent = '';
                document.getElementById('tenorInfo').textContent = '';
                sesuaikanPeranShu(true);
                sesuaikanDokumenAgtjAgt();
                updateCalculation();
                return;
            }
            const maksimal = parseInt(selectedOption.getAttribute('data-maksimal'), 10) || 0;
            const tarif = getTarifTerpilih();
            const frekuensi = (selectedOption.getAttribute('data-frekuensi') || 'bulanan').split(',');

            document.getElementById('maxInfo').textContent = 'Maksimal: ' + formatRp(maksimal);
            document.getElementById('tenorInfo').textContent = 'Tenor tersedia: ' + Object.keys(tarif).join(', ') + ' bulan';
            jumlahHidden.setAttribute('max', String(maksimal));
            setAmount(maksimal);

            Object.keys(tarif).forEach(function (t) {
                tenorSelect.innerHTML += '<option value="' + t + '">' + t + ' bulan (bunga ' + tarif[t] + '%)</option>';
            });

            const ids = { bulanan: 'frekBulanan', mingguan: 'frekMingguan', dua_mingguan: 'frekDuaMingguan' };
            ['bulanan', 'mingguan', 'dua_mingguan'].forEach(function (f) {
                const radio = document.getElementById(ids[f]);
                const allowed = frekuensi.indexOf(f) !== -1;
                radio.disabled = !allowed;
                radio.parentElement.style.display = allowed ? '' : 'none';
                if (!allowed) radio.checked = false;
            });
            if (!getFrekuensiTerpilih()) {
                document.getElementById(ids[frekuensi[0]] || 'frekBulanan').checked = true;
            }

            const wajibJaminan = selectedOption.getAttribute('data-jaminan') === '1';
            jaminanFields.hidden = !wajibJaminan;
            document.querySelectorAll('.kategori-jaminan').forEach(function (el) { el.required = wajibJaminan; });
            if (!wajibJaminan) {
                document.querySelectorAll('.jaminan-detail').forEach(function (panel) {
                    panel.hidden = true;
                    panel.querySelectorAll('.detail-required').forEach(function (el) {
                        el.required = false;
                        el.classList.remove('is-invalid');
                    });
                });
                document.querySelectorAll('.kategori-jaminan').forEach(function (el) { el.checked = false; });
            } else {
                aturKategoriJaminan();
            }
            const hariGroup = document.getElementById('hariAngsuranGroup');
            const hariSelect = document.getElementById('hariAngsuranSelect');
            hariGroup.style.display = wajibJaminan ? 'none' : '';
            hariSelect.required = !wajibJaminan;
            if (wajibJaminan) hariSelect.value = '';
            sesuaikanPeranShu(true);
            sesuaikanDokumenAgtjAgt();
            updateCalculation();
        }

        function updateCalculation() {
            const jumlah = parseRp(jumlahHidden.value);
            const tenor = parseInt(tenorSelect.value, 10) || 0;
            const frekuensi = getFrekuensiTerpilih();
            const tarif = getTarifTerpilih();

            if (jumlah > 0 && tenor > 0 && frekuensi && tarif[tenor] !== undefined && typeof hitungPinjaman === 'function') {
                const opt = jenisSelect.selectedOptions[0];
                const gadai = opt && opt.getAttribute('data-jaminan') === '1';
                const h = gadai
                    ? hitungPinjamanGadai(jumlah, tarif[tenor], tenor, frekuensi)
                    : hitungPinjaman(jumlah, frekuensi === 'dua_mingguan' ? 45 : tarif[tenor], tenor, frekuensi, 'flat');
                if (!h) {
                    calculationInfo.hidden = true;
                    return;
                }
                const akhir = h.rincian[h.rincian.length - 1].jumlah;
                document.getElementById('displayBunga').textContent = gadai
                    ? tarif[tenor] + '% + Rp ' + h.biayaMingguanGadai.toLocaleString('id-ID') + '/minggu'
                    : (frekuensi === 'dua_mingguan' ? '45%' : tarif[tenor] + '%');
                document.getElementById('displayTotal').textContent = formatRp(h.total);
                document.getElementById('labelAngsuran').textContent = 'Angsuran/' + labelPeriode(frekuensi);
                document.getElementById('displayAngsuran').textContent = formatRp(h.angsuran);
                document.getElementById('displayCicilan').textContent = h.jumlahCicilan + '× cicilan' +
                    (akhir !== h.angsuran ? ' (terakhir ' + formatRp(akhir) + ')' : '');
                calculationInfo.hidden = false;
            } else {
                calculationInfo.hidden = true;
            }
        }

        function validateForm() {
            if (!anggotaSelect.value) { window.uiToast && uiToast('warning', 'Pilih anggota terlebih dahulu'); return false; }
            if (!jenisSelect.value) { window.uiToast && uiToast('warning', 'Pilih jenis pinjaman terlebih dahulu'); return false; }
            const selectedOption = jenisSelect.options[jenisSelect.selectedIndex];
            const maksimal = selectedOption ? parseInt(selectedOption.getAttribute('data-maksimal'), 10) : 0;
            const jumlah = parseRp(jumlahHidden.value);
            const tenor = parseInt(tenorSelect.value, 10) || 0;
            if (jumlah <= 0) { window.uiToast && uiToast('warning', 'Jumlah pinjaman harus lebih dari 0'); jumlahDisplay.focus(); return false; }
            if (jumlah > maksimal) { window.uiToast && uiToast('warning', 'Jumlah melebihi maksimal ' + formatRp(maksimal)); jumlahDisplay.focus(); return false; }
            if (!tenor) { window.uiToast && uiToast('warning', 'Pilih tenor'); return false; }
            const gadai = selectedOption && selectedOption.getAttribute('data-jaminan') === '1';
            if (!gadai && getFrekuensiTerpilih() === 'dua_mingguan' && tenor !== 6) {
                window.uiToast && uiToast('warning', 'Angsuran 2 minggu sekali tanpa jaminan hanya untuk tenor 6 bulan');
                return false;
            }
            if (!gadai && getFrekuensiTerpilih() === 'dua_mingguan' && [2000000, 3000000].indexOf(jumlah) === -1) {
                window.uiToast && uiToast('warning', 'Tenor 6 bulan / 2 minggu sekali hanya untuk Rp 2 juta atau Rp 3 juta');
                return false;
            }
            if (gadai && typeof biayaMingguanGadai === 'function' && biayaMingguanGadai(jumlah) === null) {
                window.uiToast && uiToast('warning', 'Nominal pinjaman gadai harus kelipatan Rp 1 juta');
                return false;
            }
            if (getTarifTerpilih()[tenor] === undefined) {
                window.uiToast && uiToast('warning', 'Tenor tidak tersedia untuk jenis pinjaman ini');
                return false;
            }
            if (!getFrekuensiTerpilih()) {
                window.uiToast && uiToast('warning', 'Pilih frekuensi angsuran');
                return false;
            }
            if (!gadai && !document.getElementById('hariAngsuranSelect').value) {
                window.uiToast && uiToast('warning', 'Pilih hari angsuran');
                return false;
            }
            if (gadai && !validasiDetailJaminan()) {
                return false;
            }
            if (!(document.getElementById('tujuanPinjaman').value || '').trim()) {
                window.uiToast && uiToast('warning', 'Isi tujuan pinjaman');
                document.getElementById('tujuanPinjaman').focus();
                return false;
            }
            const docsVisible = !document.getElementById('dokumenAgtjAgt').classList.contains('d-none');
            if (docsVisible) {
                const kk = document.getElementById('foto_kk_pengajuan');
                const pasangan = document.getElementById('foto_ktp_pasangan_pengajuan');
                if (kk && kk.required && !(kk.files && kk.files.length)) {
                    window.uiToast && uiToast('warning', 'Unggah foto Kartu Keluarga (KK)');
                    kk.focus();
                    return false;
                }
                if (pasangan && pasangan.required && !(pasangan.files && pasangan.files.length)) {
                    window.uiToast && uiToast('warning', 'Unggah foto KTP suami/istri');
                    pasangan.focus();
                    return false;
                }
            }
            return true;
        }

        function openConfirm() {
            if (!validateForm()) return;
            const jumlah = parseRp(jumlahHidden.value);
            const tenor = tenorSelect.value;
            const frekuensi = getFrekuensiTerpilih();
            document.getElementById('cfmAnggota').textContent = selectedAnggotaLabel();
            document.getElementById('cfmJenis').textContent = selectedJenisLabel();
            document.getElementById('cfmJumlah').textContent = formatRp(jumlah);
            document.getElementById('cfmTenor').textContent = tenor + ' bulan';
            document.getElementById('cfmFrekuensi').textContent = frekuensiLabel(frekuensi);
            document.getElementById('cfmAngsuran').textContent = document.getElementById('displayAngsuran').textContent || '—';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiPengajuan')).show();
        }

        jumlahDisplay.addEventListener('input', function () { setAmount(parseRp(this.value)); });
        anggotaSelect.addEventListener('change', function () { sesuaikanPeranShu(true); sesuaikanDokumenAgtjAgt(); });
        document.getElementById('kategori_shu_pengajuan').addEventListener('change', function () { sesuaikanPeranShu(false); });
        jenisSelect.addEventListener('change', updateJenisMeta);
        tenorSelect.addEventListener('change', updateCalculation);
        document.querySelectorAll('input[name="frekuensi"]').forEach(function (r) {
            r.addEventListener('change', updateCalculation);
        });
        document.querySelectorAll('.kategori-jaminan').forEach(function (radio) {
            radio.addEventListener('change', aturKategoriJaminan);
        });
        document.querySelectorAll('.detail-required').forEach(function (el) {
            el.addEventListener('input', function () {
                if (!isBlankJaminan(this.value)) this.classList.remove('is-invalid');
            });
            el.addEventListener('blur', function () {
                if (this.required) this.classList.toggle('is-invalid', isBlankJaminan(this.value));
            });
        });

        form.addEventListener('submit', function (e) {
            if (allowSubmit) {
                // Cadangan terakhir sebelum kirim (form memakai novalidate).
                if (!validateForm()) {
                    e.preventDefault();
                    allowSubmit = false;
                }
                return;
            }
            e.preventDefault();
            openConfirm();
        });
        document.getElementById('btnKonfirmasiPengajuan').addEventListener('click', function () {
            if (!validateForm()) {
                allowSubmit = false;
                const confirmModal = bootstrap.Modal.getInstance(document.getElementById('modalKonfirmasiPengajuan'));
                if (confirmModal) confirmModal.hide();
                return;
            }
            allowSubmit = true;
            const confirmModal = bootstrap.Modal.getInstance(document.getElementById('modalKonfirmasiPengajuan'));
            if (confirmModal) confirmModal.hide();
            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        });

        document.getElementById('detailModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            document.getElementById('detail_kode').textContent = button.getAttribute('data-kode') || '—';
            document.getElementById('detail_nama').textContent = button.getAttribute('data-nama') || '—';
            document.getElementById('detail_kode_anggota').textContent = button.getAttribute('data-kode_anggota') || '—';
            document.getElementById('detail_jenis').textContent = button.getAttribute('data-jenis') || '—';
            document.getElementById('detail_jumlah').textContent = formatRp(button.getAttribute('data-jumlah'));
            document.getElementById('detail_tenor').textContent = button.getAttribute('data-tenor') || '—';
            document.getElementById('detail_bunga').textContent = button.getAttribute('data-bunga') || '—';
            const status = button.getAttribute('data-status') || '-';
            const statusClass = {
                menunggu: 'warning text-dark',
                disetujui: 'success',
                ditolak: 'danger',
                batal_cair: 'secondary',
                dicairkan: 'info'
            };
            const statusLabel = status.replace(/_/g, ' ');
            document.getElementById('detail_status').innerHTML = '<span class="badge bg-' + (statusClass[status] || 'secondary') + '">' +
                statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1) + '</span>';
            document.getElementById('detail_tujuan').textContent = button.getAttribute('data-tujuan') || '—';
            document.getElementById('detail_tanggal').textContent = button.getAttribute('data-tanggal') || '—';
            document.getElementById('detail_pengaju').textContent = button.getAttribute('data-pengaju') || '—';
            document.getElementById('detail_catatan').textContent = button.getAttribute('data-catatan') || '—';
            document.getElementById('detail_admin').textContent = button.getAttribute('data-admin') || 'Belum ada putusan';
            document.getElementById('detail_tanggal_persetujuan').textContent = button.getAttribute('data-tanggal_persetujuan') || '—';
        });

        function syncComboboxLabel(sel) {
            const box = sel.closest('.ui-combobox');
            if (!box) return;
            const input = box.querySelector('.ui-combobox-input');
            const opt = sel.options[sel.selectedIndex];
            if (input) input.value = opt && opt.value ? opt.textContent.trim() : '';
        }
        function clearSelect(sel) {
            sel.value = '';
            Array.prototype.forEach.call(sel.options, function (o) { o.selected = false; });
            if (sel.options[0]) sel.options[0].selected = true;
            syncComboboxLabel(sel);
        }
        function resetPengajuanForm() {
            allowSubmit = false;
            clearSelect(anggotaSelect);
            clearSelect(jenisSelect);
            setAmount(0);
            tenorSelect.innerHTML = '<option value="">-- Pilih jenis pinjaman dulu --</option>';
            document.getElementById('maxInfo').textContent = '';
            document.getElementById('tenorInfo').textContent = '';
            document.getElementById('tujuanPinjaman').value = '';
            document.getElementById('tanggalPengajuan').value = '<?= date('Y-m-d') ?>';
            document.getElementById('hariAngsuranSelect').value = '';
            document.getElementById('fasilitasPrioritas').checked = false;
            document.querySelectorAll('input[name="frekuensi"]').forEach(function (r) {
                r.checked = false;
                r.disabled = false;
                r.parentElement.style.display = '';
            });
            document.querySelectorAll('.kategori-jaminan').forEach(function (el) {
                el.checked = false;
                el.required = false;
            });
            jaminanFields.hidden = true;
            document.querySelectorAll('.jaminan-detail').forEach(function (panel) {
                panel.hidden = true;
                panel.querySelectorAll('input').forEach(function (el) { el.value = ''; el.required = false; });
            });
            document.getElementById('dokumenAgtjAgt').classList.add('d-none');
            document.getElementById('wrapFotoKkPengajuan').hidden = true;
            document.getElementById('wrapFotoKtpPasanganPengajuan').hidden = true;
            clearFotoPreview('foto_kk_pengajuan', 'pengajuan_preview_foto_kk');
            clearFotoPreview('foto_ktp_pasangan_pengajuan', 'pengajuan_preview_foto_ktp_pasangan');
            const kkInput = document.getElementById('foto_kk_pengajuan');
            const pasanganInput = document.getElementById('foto_ktp_pasangan_pengajuan');
            if (kkInput) kkInput.disabled = true;
            if (pasanganInput) pasanganInput.disabled = true;
            document.getElementById('shuPengajuanWrap').style.display = '';
            document.getElementById('kategori_shu_pengajuan').disabled = false;
            document.getElementById('kategori_shu_pengajuan').value = 'anggota';
            document.getElementById('ketua_kelompok_id').disabled = false;
            document.getElementById('ketua_kelompok_id').value = '';
            document.getElementById('ketua_kelompok_wrap').style.display = '';
            document.getElementById('hariAngsuranGroup').style.display = '';
            document.getElementById('hariAngsuranSelect').required = true;
            calculationInfo.hidden = true;
        }

        let prefillAnggota = anggotaSelect.getAttribute('data-prefill') || '';
        const modalEl = document.getElementById('modalPengajuan');
        modalEl.addEventListener('show.bs.modal', function () {
            if (prefillAnggota) {
                anggotaSelect.value = prefillAnggota;
                syncComboboxLabel(anggotaSelect);
                prefillAnggota = '';
                anggotaSelect.removeAttribute('data-prefill');
                sesuaikanPeranShu(true);
                sesuaikanDokumenAgtjAgt();
            }
        });
        modalEl.addEventListener('shown.bs.modal', function () {
            const combo = anggotaSelect.closest('.ui-combobox');
            const focusEl = combo ? combo.querySelector('.ui-combobox-input') : (anggotaSelect.value ? jenisSelect : anggotaSelect);
            if (focusEl && focusEl.focus) focusEl.focus();
        });
        modalEl.addEventListener('hidden.bs.modal', resetPengajuanForm);

        document.addEventListener('DOMContentLoaded', function () {
            resetPengajuanForm();
        });
    })();
    </script>
</body>
</html>
