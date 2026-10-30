<?php
/**
 * Single implementation — Pembayaran Angsuran (Admin & Teller).
 * Entry: admin|teller/angsuran.php dan detail_angsuran.php (thin wrappers).
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
require_can_page(basename(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? 'angsuran.php')));

$page_title = "Pembayaran Angsuran";

$angsuranMode = $angsuranMode ?? 'list';
if ($angsuranMode === 'list' && isset($_GET['pinjaman_id']) && (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')) {
    $qs = $_GET;
    header('Location: detail_angsuran.php?' . http_build_query($qs));
    exit;
}
if ($angsuranMode === 'detail' && empty($_GET['pinjaman_id'])) {
    header('Location: angsuran.php');
    exit;
}

/**
 * Mencatat satu item keranjang. Pemanggil wajib sudah membuka transaksi database;
 * dengan begitu checkout banyak angsuran bersifat all-or-nothing.
 */
function checkoutItemAngsuran(PDO $db, int $id, string $denda_input, string $metode, int $user_id): array {
    if (!in_array($metode, ['cash', 'transfer'], true)) throw new Exception('Metode pembayaran tidak valid.');
    $pid = $db->prepare('SELECT pinjaman_id FROM angsuran WHERE id=? FOR UPDATE');
    $pid->execute([$id]);
    $pinjaman_kunci = layananPinjaman($db, $pid->fetchColumn());
    layananSegarkanDenda($db, $pinjaman_kunci);
    if ($pinjaman_kunci['status'] === 'lunas') throw new Exception('Histori pinjaman lunas terkunci.');

    $stmt = $db->prepare("SELECT a.*, p.anggota_id, p.kode_pinjaman, p.jumlah_pinjaman, p.sisa_pokok
        FROM angsuran a JOIN pinjaman p ON a.pinjaman_id=p.id WHERE a.id=? FOR UPDATE");
    $stmt->execute([$id]); $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new Exception('Data angsuran tidak ditemukan.');
    pastikanBulanBelumTerkunci($db, date('Y-m-d'));
    if ($a['status'] === 'lunas') throw new Exception('Angsuran ke-'.$a['angsuran_ke'].' sudah dibayar.');

    $otomatis = hitungDendaAngsuran($db, $a, $a['jumlah_pinjaman']);
    $nilai = dendaUntukPembayaran($denda_input, $otomatis['denda'], $a['denda'], !empty($a['denda_manual']), isAdmin());
    $denda = (float) $nilai['denda'];
    $ketetapan = !empty($a['denda_manual']) ? (float)$a['denda'] : (float)$otomatis['denda'];
    if (isAdmin() && $denda < $ketetapan && !$db->query('SELECT pemutihan FROM layanan_pengaturan WHERE id=1')->fetchColumn()) {
        throw new Exception('Pemutihan sedang dinonaktifkan.');
    }
    $cek = $db->prepare('SELECT COALESCE(SUM(jumlah),0) FROM layanan_bayar_denda WHERE angsuran_id=?');
    $cek->execute([$id]);
    $denda_sudah = (float) $cek->fetchColumn();
    if ($denda - $denda_sudah > 0.005) throw new Exception('Bayar sisa denda melalui menu Denda terlebih dahulu.');

    $manual = kolomAda($db, 'angsuran', 'denda_manual') ? ', denda_manual='.(int)$nilai['manual'] : '';
    $db->prepare("UPDATE angsuran SET tanggal_bayar=CURDATE(), status='lunas', denda=?$manual WHERE id=?")->execute([$denda, $id]);
    $db->prepare("UPDATE pinjaman SET sisa_pokok=?, status='aktif' WHERE id=?")
        ->execute([max(0, (float)$a['sisa_pokok']-(float)$a['pokok']), $a['pinjaman_id']]);
    tandaiLunasBilaSelesai($db, $a['pinjaman_id']);

    $total = (float)$a['jumlah_angsuran'] + max(0, $denda-$denda_sudah);
    $kredit = layananPakaiKredit($db, $a['pinjaman_id'], $id, $total);
    $total -= $kredit;
    $jenis = $db->query("SELECT id FROM jenis_simpanan WHERE kode_jenis='ANG' LIMIT 1")->fetchColumn();
    if (!$jenis) {
        $db->exec("INSERT INTO jenis_simpanan (kode_jenis,nama_jenis,deskripsi,status) VALUES ('ANG','Pembayaran Angsuran','Pembayaran angsuran pinjaman','aktif')");
        $jenis = $db->lastInsertId();
    }
    $kode = 'ANG-'.date('Ymd').'-'.random_int(100000,999999);
    $ket = 'Pembayaran angsuran ke-'.$a['angsuran_ke'].' - '.$a['kode_pinjaman'];
    $db->prepare("INSERT INTO transaksi (kode_transaksi,anggota_id,jenis_simpanan_id,jenis_transaksi,jumlah,saldo_sebelum,saldo_sesudah,keterangan,user_id,tanggal_transaksi) VALUES (?,?,?,'setoran',?,0,?,?,?,NOW())")
        ->execute([$kode,$a['anggota_id'],$jenis,$total,$total,$ket,$user_id]);
    $transaksi_id = $db->lastInsertId();
    $db->query('SELECT id FROM koperasi WHERE id=1 FOR UPDATE')->fetchColumn();
    $saldo = (float)$db->query('SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
    $db->prepare("INSERT INTO kas (transaksi_id,jenis,jumlah,saldo_sebelum,saldo_sesudah,keterangan,tanggal) VALUES (?,'masuk',?,?,?,?,NOW())")
        ->execute([$transaksi_id,$total,$saldo,$saldo+$total,$ket]);
    $s = $db->prepare('SELECT id,saldo FROM simpanan WHERE anggota_id=? AND jenis_simpanan_id=? FOR UPDATE'); $s->execute([$a['anggota_id'],$jenis]); $simpanan=$s->fetch(PDO::FETCH_ASSOC);
    if ($simpanan) $db->prepare('UPDATE simpanan SET saldo=? WHERE id=?')->execute([(float)$simpanan['saldo']+$total,$simpanan['id']]);
    else $db->prepare('INSERT INTO simpanan (anggota_id,jenis_simpanan_id,saldo) VALUES (?,?,?)')->execute([$a['anggota_id'],$jenis,$total]);
    $db->prepare("INSERT INTO layanan_pembayaran (angsuran_id,pinjaman_id,user_id,jenis,jumlah,metode,bukti) VALUES (?,?,?,'angsuran',?,?, '')")
        ->execute([$id,$a['pinjaman_id'],$user_id,$total,$metode]);
    layananAudit($db,$a['pinjaman_id'],'bayar_angsuran','Checkout keranjang; angsuran '.$id.'; Rp '.$total.'; '.$metode);
    return ['nomor' => $a['angsuran_ke'], 'total' => $total];
}

// Denda otomatis (sekali sehari); denda yang diinput manual tidak ditimpa
// Paksa hitung ulang saat halaman pembayaran dibuka agar perubahan jatuh tempo
// langsung terlihat tanpa menunggu proses harian berikutnya.
perbaruiDendaOtomatis($db, true);

// Handle payment
if ($_POST) {
    try { layananValidasiToken(); } catch (Exception $e) { $_SESSION['error']=$e->getMessage();header('Location: angsuran.php');exit; }
    $cartKey = 'keranjang_angsuran_' . (int)$_SESSION['user_id'];
    if (!isset($_SESSION[$cartKey]) || !is_array($_SESSION[$cartKey])) $_SESSION[$cartKey] = [];
    $redirectAngsuran = isset($_GET['pinjaman_id'])
        ? ('detail_angsuran.php?pinjaman_id=' . (int)$_GET['pinjaman_id'])
        : (isset($_POST['pinjaman_id']) && (int)$_POST['pinjaman_id'] > 0
            ? ('detail_angsuran.php?pinjaman_id=' . (int)$_POST['pinjaman_id'])
            : 'angsuran.php');
    // Kembali ke jadwal/tabel agar sort DataTables (stateSave) tetap terlihat setelah reload.
    $redirectAngsuranJadwal = $redirectAngsuran . '#tabelJadwalAngsuran';
    $redirectAngsuranKeranjang = $redirectAngsuran . '#keranjangAngsuran';
    if (isset($_POST['tambah_keranjang']) || isset($_POST['bayar_langsung'])) {
        try {
            $id = (int)($_POST['id'] ?? 0); $metode = $_POST['metode'] ?? 'cash';
            if (!in_array($metode, ['cash','transfer'], true)) throw new Exception('Metode pembayaran tidak valid.');
            $q=$db->prepare("SELECT a.id,a.angsuran_ke,a.jumlah_angsuran,a.denda,a.status,p.kode_pinjaman,ag.nama_lengkap FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id WHERE a.id=?");
            $q->execute([$id]); $item=$q->fetch(PDO::FETCH_ASSOC);
            if (!$item || $item['status']==='lunas') throw new Exception('Angsuran tidak tersedia atau sudah dibayar.');
            $dendaInput = (string)($_POST['denda'] ?? '');
            if (isset($_POST['bayar_langsung'])) {
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $db->beginTransaction();
                $hasil = checkoutItemAngsuran($db, $id, $dendaInput, $metode, (int)$_SESSION['user_id']);
                $db->commit();
                unset($_SESSION[$cartKey][$id]);
                $_SESSION['success'] = 'Pembayaran angsuran ke-' . (int)$item['angsuran_ke'] . ' (' . $item['nama_lengkap'] . ') berhasil diproses. Total ' . formatRupiah((float)$hasil['total']) . '.';
            } else {
                if (isset($_SESSION[$cartKey][$id])) {
                    throw new Exception('Angsuran ini sudah ada di daftar pembayaran.');
                }
                $_SESSION[$cartKey][$id] = ['id'=>$id,'nama'=>$item['nama_lengkap'],'kode'=>$item['kode_pinjaman'],'angsuran_ke'=>$item['angsuran_ke'],'jumlah'=>(float)$item['jumlah_angsuran'],'denda_input'=>$dendaInput,'denda_tampil'=>is_numeric($dendaInput) ? (float)$dendaInput : (float)$item['denda'],'metode'=>$metode];
                $_SESSION['success']='Angsuran '.$item['nama_lengkap'].' ditambahkan ke daftar pembayaran. Proses kas saat checkout.';
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = (isset($_POST['bayar_langsung']) ? 'Gagal memproses: ' : 'Gagal menambahkan: ') . $e->getMessage();
        }
        header('Location: ' . (isset($_POST['tambah_keranjang']) ? $redirectAngsuranKeranjang : $redirectAngsuranJadwal)); exit;
    }
    if (isset($_POST['hapus_keranjang'])) {
        unset($_SESSION[$cartKey][(int)($_POST['id'] ?? 0)]);
        $_SESSION['success']='Item dihapus dari daftar pembayaran.';
        header('Location: ' . $redirectAngsuranKeranjang); exit;
    }
    if (isset($_POST['checkout_keranjang'])) {
        try {
            if (!$_SESSION[$cartKey]) throw new Exception('Daftar pembayaran masih kosong.');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->beginTransaction();
            $hasil=[]; foreach ($_SESSION[$cartKey] as $item) $hasil[]=checkoutItemAngsuran($db,(int)$item['id'],(string)$item['denda_input'],(string)$item['metode'],(int)$_SESSION['user_id']);
            $db->commit(); $_SESSION[$cartKey]=[];
            $_SESSION['success']=count($hasil).' pembayaran berhasil diproses sekaligus. Total: '.formatRupiah(array_sum(array_column($hasil,'total')));
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); $_SESSION['error']='Checkout dibatalkan. Tidak ada pembayaran yang dicatat: '.$e->getMessage(); }
        header('Location: ' . $redirectAngsuranKeranjang); exit;
    }
    // Koreksi langsung (HANYA Admin; fungsi memeriksa peran di server)
    if (isset($_POST['batalkan_bayar'])) {
        if (!can('angsuran.batal_langsung')) {
            $_SESSION['error'] = 'Hanya Admin yang dapat membatalkan pembayaran secara langsung.';
            header('Location: ' . (isset($_GET['pinjaman_id']) ? ('detail_angsuran.php?pinjaman_id=' . (int)$_GET['pinjaman_id']) : 'angsuran.php'));
            exit;
        }
        try {
            $_SESSION['success'] = batalkanPembayaranAngsuran(
                $db,
                (int) $_POST['id'],
                isset($_POST['alasan']) ? $_POST['alasan'] : '',
                $_SESSION['user_id']
            );
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal membatalkan pembayaran: " . $e->getMessage();
        }
        header("Location: " . (isset($_GET['pinjaman_id']) ? ("detail_angsuran.php?pinjaman_id=" . (int)$_GET['pinjaman_id']) : "angsuran.php"));
        exit();
    }

    // Teller mengajukan pembatalan (pembayaran belum dibalik sampai Admin memutuskan)
    if (isset($_POST['ajukan_pembatalan'])) {
        try {
            $_SESSION['success'] = ajukanPembatalanAngsuran(
                $db,
                (int) $_POST['id'],
                isset($_POST['alasan']) ? $_POST['alasan'] : '',
                $_SESSION['user_id']
            );
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal mengajukan pembatalan: " . $e->getMessage();
        }
        header("Location: " . (isset($_GET['pinjaman_id']) ? ("detail_angsuran.php?pinjaman_id=" . (int)$_GET['pinjaman_id']) : "angsuran.php"));
        exit();
    }

    // Admin menyetujui / menolak pengajuan pembatalan (Teller ditolak di server oleh fungsi)
    if (isset($_POST['putuskan_pembatalan'])) {
        if (!can('angsuran.putuskan_batal')) {
            $_SESSION['error'] = 'Hanya Admin yang dapat memutuskan pengajuan pembatalan.';
            header('Location: ' . (isset($_GET['pinjaman_id']) ? ('detail_angsuran.php?pinjaman_id=' . (int)$_GET['pinjaman_id']) : 'angsuran.php'));
            exit;
        }
        try {
            $_SESSION['success'] = putuskanPembatalanAngsuran(
                $db,
                (int) $_POST['pengajuan_id'],
                isset($_POST['keputusan']) && $_POST['keputusan'] === 'setuju',
                isset($_POST['catatan']) ? $_POST['catatan'] : '',
                $_SESSION['user_id']
            );
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal memproses pengajuan pembatalan: " . $e->getMessage();
        }
        header("Location: " . (isset($_GET['pinjaman_id']) ? ("detail_angsuran.php?pinjaman_id=" . (int)$_GET['pinjaman_id']) : "angsuran.php"));
        exit();
    }

    // Jalur lama sengaja dinonaktifkan: pembayaran harus melewati daftar sementara.
    if (isset($_POST['bayar_legacy_nonaktif'])) {
        $bukti_path = null;
        $id = $_POST['id'];
        // Denda: kosong = otomatis (tarif per hari x hari terlambat); diisi = manual
        $denda_input = $_POST['denda'] ?? '';
        $denda = 0;
        $denda_manual = 0;
        $user_id = $_SESSION['user_id'];
        
        try {
            layananValidasiToken();
            if (!layananSiap($db)) throw new Exception('Jalankan migrasi layanan terlebih dahulu.');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $metode = $_POST['metode'] ?? '';
            if (!in_array($metode, ['cash','transfer'], true)) throw new Exception('Metode pembayaran tidak valid.');
            $db->beginTransaction();
            $pid = $db->prepare('SELECT pinjaman_id FROM angsuran WHERE id=?');
            $pid->execute([(int)$id]);
            $pinjaman_kunci = layananPinjaman($db, $pid->fetchColumn());
            layananSegarkanDenda($db,$pinjaman_kunci);
            if ($pinjaman_kunci['status'] === 'lunas') throw new Exception('Histori pinjaman lunas terkunci.');
            
            // Get data angsuran lengkap sebelum diupdate
            $query_angsuran = "SELECT a.*, p.anggota_id, p.kode_pinjaman, p.jumlah_pinjaman, p.sisa_pokok,
                                      jp.memerlukan_jaminan
                              FROM angsuran a
                              JOIN pinjaman p ON a.pinjaman_id = p.id
                              JOIN jenis_pinjaman jp ON jp.id = p.jenis_pinjaman_id
                              WHERE a.id = :id FOR UPDATE";
            $stmt_angsuran = $db->prepare($query_angsuran);
            $stmt_angsuran->execute([':id' => (int) $id]);
            $angsuran_data = $stmt_angsuran->fetch(PDO::FETCH_ASSOC);
            
            if (!$angsuran_data) {
                throw new Exception("Data angsuran tidak ditemukan");
            }
            if ($angsuran_data['status'] === 'lunas') {
                throw new Exception("Angsuran ini sudah dibayar");
            }

            // Hitung denda otomatis; nilai berbeda dari hitungan = manual
            $denda_auto = hitungDendaAngsuran($db, $angsuran_data, $angsuran_data['jumlah_pinjaman']);
            $nilaiDenda=dendaUntukPembayaran($denda_input,$denda_auto['denda'],$angsuran_data['denda'],!empty($angsuran_data['denda_manual']),isAdmin());
            $denda=$nilaiDenda['denda'];
            $denda_manual=$nilaiDenda['manual'];
            $ketetapanDenda=!empty($angsuran_data['denda_manual'])?(float)$angsuran_data['denda']:$denda_auto['denda'];
            if (isAdmin() && $denda<$ketetapanDenda && !$db->query('SELECT pemutihan FROM layanan_pengaturan WHERE id=1')->fetchColumn()) {
                throw new Exception('Pemutihan sedang dinonaktifkan.');
            }
            $cekDenda=$db->prepare('SELECT COALESCE(SUM(jumlah),0) FROM layanan_bayar_denda WHERE angsuran_id=?');
            $cekDenda->execute([$id]);
            if ($denda-(float)$cekDenda->fetchColumn()>0.005) {
                throw new Exception('Bayar sisa denda melalui menu Denda terlebih dahulu. Foto bukti pembayaran denda wajib dicatat.');
            }


            // Update angsuran
            $set_manual = kolomAda($db, 'angsuran', 'denda_manual') ? ", denda_manual = " . (int) $denda_manual : '';
            $query = "UPDATE angsuran SET tanggal_bayar = CURDATE(), status = 'lunas', denda = :denda" . $set_manual . " WHERE id = :id";
            $stmt = $db->prepare($query);
            if (!$stmt->execute([':id' => (int) $id, ':denda' => $denda])) {
                throw new Exception("Gagal mengupdate status angsuran");
            }
            
            // Update sisa pokok di pinjaman
            $sisa_pokok_baru = max(0, $angsuran_data['sisa_pokok'] - $angsuran_data['pokok']);
            $status_pinjaman = 'aktif'; // Status lunas hanya setelah seluruh jadwal selesai.
            
            $query_update_pinjaman = "UPDATE pinjaman SET 
                                      sisa_pokok = :sisa_pokok,
                                      status = :status 
                                      WHERE id = :pinjaman_id";
            $stmt_update = $db->prepare($query_update_pinjaman);
            $stmt_update->execute([':sisa_pokok' => $sisa_pokok_baru, ':status' => $status_pinjaman, ':pinjaman_id' => (int) $angsuran_data['pinjaman_id']]);

            // Semua cicilan lunas -> pinjaman lunas + catat tanggal lunas
            $pinjaman_selesai = tandaiLunasBilaSelesai($db, $angsuran_data['pinjaman_id']);
            
            // Hitung total pembayaran
            $dibayar_denda = $db->prepare('SELECT COALESCE(SUM(jumlah),0) FROM layanan_bayar_denda WHERE angsuran_id=?');
            $dibayar_denda->execute([$id]);
            $total_bayar = $angsuran_data['jumlah_angsuran'] + max(0, $denda - (float)$dibayar_denda->fetchColumn());
            $kredit_lelang = layananPakaiKredit($db,$angsuran_data['pinjaman_id'],$id,$total_bayar);
            $total_bayar -= $kredit_lelang;
            
            // ============================================
            // CEK APAKAH JENIS SIMPANAN 'ANG' SUDAH ADA
            // ============================================
            $query_cek_jenis = "SELECT id FROM jenis_simpanan WHERE kode_jenis = 'ANG'";
            $stmt_cek = $db->prepare($query_cek_jenis);
            $stmt_cek->execute();
            $jenis_angsuran = $stmt_cek->fetch(PDO::FETCH_ASSOC);
            
            // Jika belum ada, buat dulu
            if (!$jenis_angsuran) {
                $query_insert_jenis = "INSERT INTO jenis_simpanan (kode_jenis, nama_jenis, deskripsi, status) 
                                      VALUES ('ANG', 'Pembayaran Angsuran', 'Pembayaran angsuran pinjaman', 'aktif')";
                $stmt_insert = $db->prepare($query_insert_jenis);
                $stmt_insert->execute();
                
                // Ambil ID yang baru dibuat
                $jenis_angsuran_id = $db->lastInsertId();
            } else {
                $jenis_angsuran_id = $jenis_angsuran['id'];
            }
            
            // ============================================
            // TAMBAHKAN KE TABEL TRANSAKSI (PAKAI ID YANG BENAR)
            // ============================================
            // Generate kode transaksi
            $kode_transaksi = 'ANG-' . date('Ymd') . '-' . str_pad(random_int(100000, 999999), 3, '0', STR_PAD_LEFT);
            
            // Insert ke tabel transaksi - GUNAKAN JENIS_SIMPANAN_ID YANG BENAR
            $query_transaksi = "INSERT INTO transaksi 
                               (kode_transaksi, anggota_id, jenis_simpanan_id, jenis_transaksi, 
                                jumlah, saldo_sebelum, saldo_sesudah, keterangan, user_id, tanggal_transaksi) 
                               VALUES 
                               (:kode_transaksi, :anggota_id, :jenis_angsuran_id, 'setoran', 
                                 :jumlah, 0, :saldo_sesudah, :keterangan, :user_id, NOW())";
            $stmt_transaksi = $db->prepare($query_transaksi);
            $keterangan_transaksi = "Pembayaran angsuran ke-" . $angsuran_data['angsuran_ke'] . " - " . $angsuran_data['kode_pinjaman'];
            $stmt_transaksi->execute([':kode_transaksi' => $kode_transaksi, ':anggota_id' => (int) $angsuran_data['anggota_id'], ':jenis_angsuran_id' => (int) $jenis_angsuran_id, ':jumlah' => $total_bayar, ':saldo_sesudah' => $total_bayar, ':keterangan' => $keterangan_transaksi, ':user_id' => (int) $user_id]);
            
            // Dapatkan ID transaksi yang baru diinsert
            $transaksi_id = $db->lastInsertId();
            
            // ============================================
            // TAMBAHKAN KE TABEL KAS (WAJIB!)
            // ============================================
            // Get saldo kas terakhir
            $db->query('SELECT id FROM koperasi WHERE id=1 FOR UPDATE')->fetchColumn();
            $query_saldo_kas = "SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1 FOR UPDATE";
            $stmt_saldo_kas = $db->prepare($query_saldo_kas);
            $stmt_saldo_kas->execute();
            $saldo_kas = $stmt_saldo_kas->fetch(PDO::FETCH_ASSOC);
            $saldo_sebelum = $saldo_kas ? $saldo_kas['saldo_sesudah'] : 0;
            $saldo_sesudah = $saldo_sebelum + $total_bayar;
            
            // Insert ke tabel kas
            $keterangan_kas = "Pembayaran angsuran ke-" . $angsuran_data['angsuran_ke'] . " - " . $angsuran_data['kode_pinjaman'];
            
            $query_kas = "INSERT INTO kas (transaksi_id, jenis, jumlah, saldo_sebelum, saldo_sesudah, keterangan, tanggal) 
                         VALUES (:transaksi_id, 'masuk', :jumlah, :saldo_sebelum, :saldo_sesudah, :keterangan, NOW())";
            $stmt_kas = $db->prepare($query_kas);
            $stmt_kas->execute([':transaksi_id' => (int) $transaksi_id, ':jumlah' => $total_bayar, ':saldo_sebelum' => $saldo_sebelum, ':saldo_sesudah' => $saldo_sesudah, ':keterangan' => $keterangan_kas]);
            
            // ============================================
            // TAMBAHKAN KE TABEL SIMPANAN (UNTUK JENIS 'ANG')
            // ============================================
            // Cek apakah sudah ada record simpanan untuk jenis 'ANG'
            $query_cek_simpanan = "SELECT id, saldo FROM simpanan 
                                  WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_angsuran_id";
            $stmt_cek_simpanan = $db->prepare($query_cek_simpanan);
            $stmt_cek_simpanan->execute([':anggota_id' => (int) $angsuran_data['anggota_id'], ':jenis_angsuran_id' => (int) $jenis_angsuran_id]);
            $simpanan = $stmt_cek_simpanan->fetch(PDO::FETCH_ASSOC);
            
            if ($simpanan) {
                // Update saldo yang sudah ada
                $saldo_baru = $simpanan['saldo'] + $total_bayar;
                $query_update_simpanan = "UPDATE simpanan SET saldo = :saldo WHERE id = :id";
                $stmt_update_simpanan = $db->prepare($query_update_simpanan);
                $stmt_update_simpanan->execute([':saldo' => $saldo_baru, ':id' => (int) $simpanan['id']]);
            } else {
                // Insert record baru
                $query_insert_simpanan = "INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo) 
                                         VALUES (:anggota_id, :jenis_angsuran_id, :saldo)";
                $stmt_insert_simpanan = $db->prepare($query_insert_simpanan);
                $stmt_insert_simpanan->execute([':anggota_id' => (int) $angsuran_data['anggota_id'], ':jenis_angsuran_id' => (int) $jenis_angsuran_id, ':saldo' => $total_bayar]);
            }
            
            $db->prepare("INSERT INTO layanan_pembayaran (angsuran_id,pinjaman_id,user_id,jenis,jumlah,metode,bukti) VALUES (?,?,?,'angsuran',?,?,?)")->execute([$id,$angsuran_data['pinjaman_id'],$user_id,$total_bayar,$metode,'']);
            layananAudit($db,$angsuran_data['pinjaman_id'],'bayar_angsuran','Angsuran '.$id.'; Rp '.$total_bayar.'; '.$metode.'; denda '.$denda.'; manual '.$denda_manual);
            $db->commit();
            $_SESSION['success'] = "Pembayaran angsuran ke-" . $angsuran_data['angsuran_ke'] . " berhasil dicatat. Kas bertambah " . formatRupiah($total_bayar);
            
        } catch (Throwable $e) {
            $pinjaman_selesai = false;
            if ($db->inTransaction()) $db->rollBack();
            if ($bukti_path) hapusFotoUpload($bukti_path);
            error_log('Gagal mencatat pembayaran angsuran: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $_SESSION['error'] = "Gagal mencatat pembayaran: " . $e->getMessage() . ' (baris ' . $e->getLine() . ')';
        }
        
        // Redirect untuk menghindari resubmission
        header("Location: " . (empty($pinjaman_selesai) && isset($_GET['pinjaman_id']) ? "detail_angsuran.php?pinjaman_id=" . (int)$_GET['pinjaman_id'] : "angsuran.php"));
        exit();
    }
}

// Debug: Cek data di database
$query_debug_pinjaman = "SELECT p.*, a.nama_lengkap, a.kode_anggota, jp.nama_jenis
                         FROM pinjaman p
                         JOIN anggota a ON p.anggota_id = a.id
                         JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                         ORDER BY p.tanggal_pencairan DESC";
$stmt_debug = $db->prepare($query_debug_pinjaman);
$stmt_debug->execute();
$all_pinjaman = $stmt_debug->fetchAll(PDO::FETCH_ASSOC);

// Hari filter harus mengikuti jadwal jatuh tempo nyata. Ini juga membuat pinjaman lama
// yang belum mempunyai pengajuan_pinjaman.hari_angsuran tetap muncul pada hari yang benar.
$fallback_hari = kolomAda($db, 'pengajuan_pinjaman', 'hari_angsuran')
    ? "(SELECT pp.hari_angsuran FROM pengajuan_pinjaman pp WHERE pp.id = p.pengajuan_id),"
    : "NULL,";
$kolom_hari = "COALESCE(
        (SELECT WEEKDAY(ah.tanggal_jatuh_tempo) + 1 FROM angsuran ah
          WHERE ah.pinjaman_id = p.id AND ah.status <> 'lunas'
          ORDER BY ah.tanggal_jatuh_tempo, ah.angsuran_ke LIMIT 1),
        $fallback_hari
        (SELECT WEEKDAY(ah2.tanggal_jatuh_tempo) + 1 FROM angsuran ah2
          WHERE ah2.pinjaman_id = p.id ORDER BY ah2.tanggal_jatuh_tempo DESC LIMIT 1)
    ) AS hari_angsuran,";
$kolom_tanggal_belum = "(SELECT GROUP_CONCAT(DISTINCT DATE(ah3.tanggal_jatuh_tempo) ORDER BY ah3.tanggal_jatuh_tempo)
                           FROM angsuran ah3 WHERE ah3.pinjaman_id = p.id AND ah3.status <> 'lunas') AS tanggal_angsuran_belum,";
$query_pinjaman = "SELECT p.*, a.nama_lengkap, a.kode_anggota, a.ktp, jp.nama_jenis, jp.memerlukan_jaminan, $kolom_hari $kolom_tanggal_belum
                          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) as total_angsuran,
                          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as angsuran_lunas
                   FROM pinjaman p
                   JOIN anggota a ON p.anggota_id = a.id
                   JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                   WHERE p.status IN ('aktif', 'macet')
                   AND EXISTS (SELECT 1 FROM angsuran ax WHERE ax.pinjaman_id = p.id AND ax.status <> 'lunas')
                   ORDER BY p.tanggal_pencairan DESC";

$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->execute();
$pinjaman_list = $stmt_pinjaman->fetchAll(PDO::FETCH_ASSOC);

// Penanda pengajuan pembatalan per pinjaman: menunggu (kuning), disetujui (hijau), ditolak (merah)
$jumlah_filter = array('menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0);
foreach ($pinjaman_list as $i => $pj) {
    $pinjaman_list[$i]['n_pb'] = array('menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0);
}
if (tabelPengajuanPembatalanAda($db) && !empty($pinjaman_list)) {
    $rows = $db->query("SELECT x.pinjaman_id, pb.status, x.status AS status_angsuran
                        FROM pengajuan_pembatalan_angsuran pb JOIN angsuran x ON pb.angsuran_id = x.id
                        WHERE pb.id = (SELECT MAX(id) FROM pengajuan_pembatalan_angsuran WHERE angsuran_id = pb.angsuran_id)")
                 ->fetchAll(PDO::FETCH_ASSOC);
    $per_pinjaman = array();
    foreach ($rows as $r) {
        $st = $r['status'];
        if (($st === 'disetujui' && $r['status_angsuran'] === 'lunas') || ($st === 'ditolak' && $r['status_angsuran'] !== 'lunas')) {
            continue; // keputusan lama yang sudah tidak relevan (cicilan dibayar lagi)
        }
        $per_pinjaman[$r['pinjaman_id']][$st] = (isset($per_pinjaman[$r['pinjaman_id']][$st]) ? $per_pinjaman[$r['pinjaman_id']][$st] : 0) + 1;
    }
    foreach ($pinjaman_list as $i => $pj) {
        if (isset($per_pinjaman[$pj['id']])) {
            foreach ($per_pinjaman[$pj['id']] as $st => $n) {
                $pinjaman_list[$i]['n_pb'][$st] = $n;
                $jumlah_filter[$st]++;
            }
        }
    }
}
// Filter daftar pinjaman: Admin hanya "menunggu"; Teller menunggu / disetujui / ditolak
$filter_pb = isset($_GET['filter']) ? $_GET['filter'] : '';
if (!in_array($filter_pb, isAdmin() ? array('menunggu') : array('menunggu', 'disetujui', 'ditolak'), true)) {
    $filter_pb = '';
}
if ($filter_pb !== '') {
    $pinjaman_list = array_values(array_filter($pinjaman_list, function ($pj) use ($filter_pb) {
        return $pj['n_pb'][$filter_pb] > 0;
    }));
}
// Filter hari memakai TANGGAL jatuh tempo aktual pada minggu berjalan, bukan sekadar
// nama hari. Contoh: memilih Selasa hanya menampilkan angsuran yang benar-benar jatuh
// tempo pada Selasa minggu ini; Selasa bulan depan tidak ikut muncul.
$awal_minggu = new DateTimeImmutable('monday this week');
$tanggal_per_hari = [];
for ($nomor_hari = 1; $nomor_hari <= 7; $nomor_hari++) {
    $tanggal_per_hari[$nomor_hari] = $awal_minggu->modify('+' . ($nomor_hari - 1) . ' days')->format('Y-m-d');
}
$jumlah_hari = array_fill(1, 7, 0);
foreach ($pinjaman_list as $pj) {
    $tanggal_belum = empty($pj['tanggal_angsuran_belum']) ? [] : explode(',', $pj['tanggal_angsuran_belum']);
    foreach ($tanggal_per_hari as $nomor_hari => $tanggal_filter) {
        if (in_array($tanggal_filter, $tanggal_belum, true)) {
            $jumlah_hari[$nomor_hari]++;
        }
    }
}
$filter_hari = isset($_GET['hari']) ? (int) $_GET['hari'] : 0;
if ($filter_hari < 1 || $filter_hari > 7) {
    $filter_hari = 0;
} else {
    $tanggal_filter = $tanggal_per_hari[$filter_hari];
    $pinjaman_list = array_values(array_filter($pinjaman_list, function ($pj) use ($tanggal_filter) {
        $tanggal_belum = empty($pj['tanggal_angsuran_belum']) ? [] : explode(',', $pj['tanggal_angsuran_belum']);
        return in_array($tanggal_filter, $tanggal_belum, true);
    }));
}
$qs_filter = ($filter_pb !== '' ? '&filter=' . $filter_pb : '') . ($filter_hari ? '&hari=' . $filter_hari : '');
$qs_hari = $filter_hari ? '&hari=' . $filter_hari : '';
$qs_pb = $filter_pb !== '' ? '&filter=' . $filter_pb : '';
$ui_ang = ui_query_params([]);
$q_ang = $ui_ang['q'];
if ($q_ang !== '') {
    $pinjaman_list = array_values(array_filter($pinjaman_list, static function ($pj) use ($q_ang) {
        return stripos(($pj['nama_lengkap'] ?? '') . ' ' . ($pj['kode_anggota'] ?? '') . ' ' . ($pj['kode_pinjaman'] ?? ''), $q_ang) !== false;
    }));
}
$pager_pinjaman = ui_paginate(count($pinjaman_list), $ui_ang['page'], $ui_ang['per_page']);
$pinjaman_list_page = array_slice($pinjaman_list, $pager_pinjaman['offset'], $pager_pinjaman['per_page']);
$pinjaman_list_full = $pinjaman_list;
$pinjaman_list = $pinjaman_list_page;

// Get installments for selected loan
$angsuran_list = [];
$pembatalan = [];
$jumlah_menunggu = jumlahPengajuanPembatalan($db);
if (isset($_GET['pinjaman_id'])) {
    $kolom_denda_dibayar = layananSiap($db) ? '(SELECT COALESCE(SUM(b.jumlah),0) FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id)' : '0';
    $query_angsuran = "SELECT a.*, p.kode_pinjaman, p.jumlah_pinjaman,
                              $kolom_denda_dibayar AS denda_dibayar
                       FROM angsuran a
                       JOIN pinjaman p ON a.pinjaman_id = p.id
                       WHERE a.pinjaman_id = :pinjaman_id
                         AND p.status <> 'lunas'
                         AND EXISTS (SELECT 1 FROM angsuran ax WHERE ax.pinjaman_id=p.id AND ax.status <> 'lunas')
                       ORDER BY a.angsuran_ke";
    $stmt_angsuran = $db->prepare($query_angsuran);
    $stmt_angsuran->bindParam(':pinjaman_id', $_GET['pinjaman_id']);
    $stmt_angsuran->execute();
    $angsuran_list = $stmt_angsuran->fetchAll(PDO::FETCH_ASSOC);
    $pembatalan = pengajuanPembatalanTerakhir($db, array_column($angsuran_list, 'id'));
}

$pinjaman_detail = null;
if (!empty($_GET['pinjaman_id'])) {
    $stmtPd = $db->prepare("SELECT p.*, a.nama_lengkap, a.kode_anggota, a.ktp, jp.nama_jenis, jp.memerlukan_jaminan,
        (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) AS total_angsuran,
        (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') AS angsuran_lunas
        FROM pinjaman p
        JOIN anggota a ON p.anggota_id = a.id
        JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
        WHERE p.id = ? LIMIT 1");
    $stmtPd->execute([(int) $_GET['pinjaman_id']]);
    $pinjaman_detail = $stmtPd->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Bangun item menu aksi satu baris angsuran (desktop & mobile). */
if (!function_exists('angsuranItemAksiMenu')) {
function angsuranItemAksiMenu(PDO $db, array $angsuran, array $keranjangIds, $pb): array {
    $items = [];
    if ($angsuran['status'] != 'lunas') {
        $d_auto = hitungDendaAngsuran($db, $angsuran, $angsuran['jumlah_pinjaman']);
        $d_nilai = !empty($angsuran['denda_manual']) ? $angsuran['denda'] : $d_auto['denda'];
        $d_info = $d_auto['hari_dihitung'] > 0
            ? 'Otomatis: Rp ' . number_format($d_auto['per_hari'], 0, ',', '.') . '/hari x ' . $d_auto['hari_dihitung'] . ' hari terlambat = Rp ' . number_format($d_auto['denda'], 0, ',', '.')
            : 'Belum terlambat, denda otomatis Rp 0';
        $inCart = in_array((int) $angsuran['id'], $keranjangIds, true);
        $payAttrs = [
            'data-bs-toggle' => 'modal',
            'data-bs-target' => '#bayarModal',
            'data-id' => (int) $angsuran['id'],
            'data-angsuran_ke' => (int) $angsuran['angsuran_ke'],
            'data-total' => $angsuran['jumlah_angsuran'],
            'data-denda_auto' => $d_auto['denda'],
            'data-denda' => $d_nilai,
            'data-lelang' => layananSiap($db) ? layananKreditLelang($db, $angsuran['pinjaman_id']) : 0,
            'data-kredit' => (float) $angsuran['denda_dibayar'],
            'data-denda_info' => $d_info,
            'data-pinjaman_id' => (int) $angsuran['pinjaman_id'],
        ];
        if ($d_nilai - (float) $angsuran['denda_dibayar'] > 0.005) {
            $items[] = ['label' => 'Bayar denda dulu', 'href' => 'detail_layanan.php?tab=denda&id=' . (int) $angsuran['pinjaman_id'], 'icon' => 'ti ti-alert-triangle', 'class' => 'text-warning'];
        } elseif ($inCart) {
            $items[] = ['label' => 'Proses langsung', 'icon' => 'ti ti-bolt', 'attrs' => $payAttrs + ['data-mode' => 'langsung']];
            $items[] = ['label' => 'Lihat keranjang', 'icon' => 'ti ti-basket', 'onclick' => "document.getElementById('keranjangAngsuran')?.scrollIntoView({behavior:'smooth',block:'start'})"];
        } else {
            $items[] = ['label' => 'Proses langsung', 'icon' => 'ti ti-bolt', 'attrs' => $payAttrs + ['data-mode' => 'langsung']];
            $items[] = ['label' => 'Masukkan ke keranjang', 'icon' => 'ti ti-shopping-cart-plus', 'attrs' => $payAttrs + ['data-mode' => 'keranjang']];
        }
        return ['items' => $items, 'in_cart' => $inCart, 'denda_nilai' => $d_nilai];
    }

    $menunggu = $pb && $pb['status'] === 'menunggu';
    $items[] = ['label' => 'Cetak bukti', 'href' => 'cetak_bukti_angsuran.php?id=' . (int) $angsuran['id'], 'target' => '_blank', 'icon' => 'ti ti-printer'];
    $barisAttrs = [
        'data-angsuran_ke' => (int) $angsuran['angsuran_ke'],
        'data-kode' => $angsuran['kode_pinjaman'],
        'data-total' => $angsuran['jumlah_angsuran'] + $angsuran['denda'],
    ];
    if (isAdmin() && $menunggu) {
        $items[] = ['label' => 'Approve pembatalan', 'icon' => 'ti ti-check', 'class' => 'text-success', 'attrs' => $barisAttrs + [
            'data-bs-toggle' => 'modal', 'data-bs-target' => '#putusModal', 'data-keputusan' => 'setuju',
            'data-pengajuan' => (int) $pb['id'], 'data-teller' => $pb['teller_nama'],
            'data-tanggal' => formatTanggalUi($pb['tanggal_pengajuan'], true), 'data-alasan' => $pb['alasan'],
        ]];
        $items[] = ['label' => 'Tolak pengajuan', 'icon' => 'ti ti-x', 'class' => 'text-danger', 'attrs' => $barisAttrs + [
            'data-bs-toggle' => 'modal', 'data-bs-target' => '#putusModal', 'data-keputusan' => 'tolak',
            'data-pengajuan' => (int) $pb['id'], 'data-teller' => $pb['teller_nama'],
            'data-tanggal' => formatTanggalUi($pb['tanggal_pengajuan'], true), 'data-alasan' => $pb['alasan'],
        ]];
    } elseif (isTeller() && !$menunggu && bisaDiajukanPembatalan($angsuran['tanggal_bayar'])) {
        $items[] = ['label' => 'Ajukan pembatalan', 'icon' => 'ti ti-arrow-back-up', 'attrs' => $barisAttrs + [
            'data-bs-toggle' => 'modal', 'data-bs-target' => '#ajukanModal', 'data-id' => (int) $angsuran['id'],
        ]];
    }
    if (isAdmin() && !$menunggu) {
        $items[] = ['label' => 'Batalkan (koreksi)', 'icon' => 'ti ti-arrow-back-up', 'class' => 'text-danger', 'attrs' => [
            'data-bs-toggle' => 'modal', 'data-bs-target' => '#batalModal',
            'data-id' => (int) $angsuran['id'],
            'data-angsuran_ke' => (int) $angsuran['angsuran_ke'],
            'data-kode' => $angsuran['kode_pinjaman'],
            'data-total' => $angsuran['jumlah_angsuran'] + $angsuran['denda'],
        ]];
    }
    return ['items' => $items, 'in_cart' => false, 'denda_nilai' => 0];
}
}

if (!function_exists('angsuranPrioBaris')) {
function angsuranPrioBaris(array $angsuran, $pb): int {
    $menunggu = $pb && ($pb['status'] ?? '') === 'menunggu';
    if ($menunggu) {
        return 1;
    }
    if ($angsuran['status'] != 'lunas') {
        if (!empty($angsuran['tanggal_jatuh_tempo']) && $angsuran['tanggal_jatuh_tempo'] < date('Y-m-d')) {
            return 2;
        }
        return 3;
    }
    if (isAdmin() || (isTeller() && bisaDiajukanPembatalan($angsuran['tanggal_bayar'] ?? null))) {
        return 5;
    }
    return 9;
}
}

// Get statistics
$query_stats = "SELECT 
    COUNT(*) as total_pinjaman,
    COUNT(CASE WHEN status = 'aktif' THEN 1 END) as aktif,
    COUNT(CASE WHEN status = 'lunas' THEN 1 END) as lunas,
    COUNT(CASE WHEN status = 'macet' THEN 1 END) as macet,
    (SELECT COUNT(DISTINCT a.pinjaman_id)
       FROM angsuran a
       JOIN pinjaman p2 ON p2.id = a.pinjaman_id
      WHERE a.status <> 'lunas'
        AND a.tanggal_jatuh_tempo < CURDATE()
        AND p2.status <> 'lunas') as telat_bayar
    FROM pinjaman";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran Angsuran - <?php echo APP_NAME; ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-angsuran" aria-hidden="true"><i class="ti ti-calendar-check"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Pinjaman</p>
                        <h1 class="ui-dash-title"><?php echo ($angsuranMode ?? 'list') === 'detail' ? 'Detail Angsuran' : 'Pembayaran Angsuran'; ?></h1>
                        <p class="ui-dash-meta mb-0"><?php echo ($angsuranMode ?? 'list') === 'detail' ? 'Jadwal cicilan &amp; proses pembayaran' : 'Terima angsuran dan proses daftar pembayaran'; ?></p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <?php if (($angsuranMode ?? 'list') === 'detail'): ?>
                        <a href="angsuran.php<?php echo $qs_filter ? '?' . ltrim($qs_filter, '&') : ''; ?>" class="btn ui-tx-btn-angsuran-ghost">
                            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Daftar pinjaman</span><span class="d-sm-none">Kembali</span>
                        </a>
                        <?php else: ?>
                        <a href="#keranjangAngsuran" class="btn ui-tx-btn-angsuran-ghost">
                            <i class="ti ti-basket me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Keranjang</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <?php
                $keranjang = $_SESSION['keranjang_angsuran_' . (int)$_SESSION['user_id']] ?? [];
                if (!is_array($keranjang)) {
                    $keranjang = [];
                }
                $keranjangIds = array_map('intval', array_keys($keranjang));
                $totalKeranjang = 0;
                foreach ($keranjang as $keranjangItem) {
                    $totalKeranjang += (float)$keranjangItem['jumlah'] + max(0, (float)$keranjangItem['denda_tampil']);
                }
                ?>

                <div class="card ui-ag-cart mb-3<?php echo $keranjang ? ' has-items' : ''; ?>" id="keranjangAngsuran">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-basket" aria-hidden="true"></i>Keranjang pembayaran</span>
                        <?php if ($keranjang): ?>
                        <span class="badge bg-success"><?php echo count($keranjang); ?> item · siap proses</span>
                        <?php else: ?>
                        <span class="ui-dash-sec-note">Kosong — proses langsung atau masukkan ke keranjang</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($keranjang): ?>
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Nasabah</th>
                                        <th>Pinjaman</th>
                                        <th>Ke</th>
                                        <th>Metode</th>
                                        <th class="text-end">Nominal</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($keranjang as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['nama']); ?></td>
                                        <td><code><?php echo htmlspecialchars($item['kode']); ?></code></td>
                                        <td><?php echo (int)$item['angsuran_ke']; ?></td>
                                        <td class="text-capitalize"><?php echo htmlspecialchars($item['metode']); ?></td>
                                        <td class="text-end fw-semibold"><?php echo formatRupiah((float)$item['jumlah'] + max(0,(float)$item['denda_tampil'])); ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline"><?php echo layananToken(); ?>
                                                <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                                <?php if (!empty($_GET['pinjaman_id'])): ?><input type="hidden" name="pinjaman_id" value="<?php echo (int)$_GET['pinjaman_id']; ?>"><?php endif; ?>
                                                <button name="hapus_keranjang" class="btn btn-sm btn-outline-danger" title="Hapus dari keranjang"><i class="ti ti-x"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ui-ag-cart-foot">
                            <strong>Total: <?php echo formatRupiah($totalKeranjang); ?></strong>
                            <form method="POST"><?php echo layananToken(); ?>
                                <button type="submit" name="checkout_keranjang" class="btn btn-success" onclick="return confirm('Proses seluruh item di keranjang? Kas &amp; laporan akan diperbarui.');">
                                    <i class="ti ti-cash-register me-1"></i>Proses semua
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="p-3 text-muted small mb-0">Keranjang kosong. Dari jadwal angsuran, buka menu ⋮ → <em>Masukkan ke keranjang</em> atau <em>Proses langsung</em>.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($jumlah_menunggu > 0): ?>
                <div class="alert alert-warning ui-ag-info py-2" role="status">
                    <i class="ti ti-hourglass me-2" aria-hidden="true"></i>
                    <strong><?php echo $jumlah_menunggu; ?> pengajuan pembatalan</strong>
                    <?php echo isAdmin() ? 'menunggu keputusan Anda (Approve / Tolak pada kolom Aksi).' : 'menunggu keputusan Admin.'; ?>
                </div>
                <?php endif; ?>

                <div class="ui-ag-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total pinjaman', 'value' => (int)$stats['total_pinjaman'], 'tone' => 'primary', 'icon' => 'ti ti-moneybag', 'hint' => 'Semua'],
                    ['label' => 'Aktif', 'value' => (int)$stats['aktif'], 'tone' => 'success', 'icon' => 'ti ti-player-play', 'hint' => 'Berjalan'],
                    ['label' => 'Lunas', 'value' => (int)$stats['lunas'], 'tone' => 'info', 'icon' => 'ti ti-flag-check', 'hint' => 'Selesai'],
                    ['label' => 'Telat bayar', 'value' => (int)$stats['telat_bayar'], 'tone' => 'warning', 'icon' => 'ti ti-clock', 'hint' => 'Lewat tempo'],
                    ['label' => 'Macet', 'value' => (int)$stats['macet'], 'tone' => 'danger', 'icon' => 'ti ti-alert-triangle', 'hint' => 'Perlu tindakan'],
                ]); ?>
                </div>

                <?php if (($angsuranMode ?? 'list') === 'list'): ?>
                <div class="card mt-3 ui-ag-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-users" aria-hidden="true"></i>Daftar Pinjaman</span>
                        <span class="ui-dash-sec-note"><?php echo (int)count($pinjaman_list); ?> pinjaman ditampilkan</span>
                    </div>
                    <div class="card-body">
                        <div class="ui-ag-toolbar">
                            <div class="ui-ag-toolbar-block">
                                <span class="ui-ag-toolbar-label">Status pembatalan</span>
                                <div class="ui-loan-filters ui-ag-chips d-flex flex-wrap gap-2">
                                    <?php
                                    $chips = isAdmin()
                                        ? array('menunggu' => array('Pengajuan Pembatalan', 'warning'))
                                        : array('menunggu' => array('Pengajuan', 'warning'), 'disetujui' => array('Approve', 'success'), 'ditolak' => array('Tolak', 'danger'));
                                    ?>
                                    <a href="angsuran.php<?php echo $filter_hari ? '?hari=' . $filter_hari : ''; ?>" class="ui-ag-chip<?php echo $filter_pb === '' ? ' is-active' : ''; ?>">Semua</a>
                                    <?php foreach ($chips as $kunci => $c): ?>
                                    <a href="?filter=<?php echo $kunci . $qs_hari; ?>" class="ui-ag-chip tone-<?php echo $c[1]; ?><?php echo $filter_pb === $kunci ? ' is-active' : ''; ?>">
                                        <?php echo $c[0]; ?> <span class="ui-ag-chip-count"><?php echo (int)$jumlah_filter[$kunci]; ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="ui-ag-toolbar-block">
                                <span class="ui-ag-toolbar-label">Hari angsuran minggu ini</span>
                                <div class="ui-loan-days ui-ag-chips d-flex flex-wrap gap-2">
                                    <a href="angsuran.php<?php echo $qs_pb ? '?' . ltrim($qs_pb, '&') : ''; ?>" class="ui-ag-chip<?php echo !$filter_hari ? ' is-active' : ''; ?>">Semua hari</a>
                                    <?php foreach (daftarHariAngsuran() as $no => $nama_hari): ?>
                                    <a href="?hari=<?php echo $no . $qs_pb; ?>" class="ui-ag-chip<?php echo $filter_hari === $no ? ' is-active' : ''; ?>">
                                        <span class="ui-ag-chip-day"><?php echo $nama_hari; ?></span>
                                        <span class="ui-ag-chip-date"><?php echo date('d/m', strtotime($tanggal_per_hari[$no])); ?></span>
                                        <span class="ui-ag-chip-count"><?php echo (int)$jumlah_hari[$no]; ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="ui-ag-toolbar-search ui-loan-search">
                                <label for="cariPinjaman" class="visually-hidden">Cari nasabah</label>
                                <div class="ui-search-control ui-ag-search">
                                    <i class="ti ti-search" aria-hidden="true"></i>
                                    <input id="cariPinjaman" type="search" class="form-control" placeholder="Cari NIK, nama, atau kode anggota…">
                                    <button type="button" class="ui-search-clear" id="clearCariPinjaman" aria-label="Hapus pencarian" hidden><i class="ti ti-x"></i></button>
                                </div>
                            </div>
                        </div>

                        <?php if (empty($pinjaman_list)): ?>
                        <div class="ui-ag-empty">
                            <i class="ti ti-folder-off" aria-hidden="true"></i>
                            <strong>Tidak ada pinjaman</strong>
                            <p><?php echo $filter_hari ? 'Tidak ada angsuran yang jatuh tempo pada tanggal yang dipilih.' : 'Tidak ada pinjaman yang memiliki angsuran terbuka.'; ?></p>
                        </div>
                        <?php else: ?>
                        <div class="ui-loan-list ui-ag-loan-list">
                            <?php foreach ($pinjaman_list as $pinjaman):
                                $progress = $pinjaman['total_angsuran'] > 0 ? ($pinjaman['angsuran_lunas'] / $pinjaman['total_angsuran'] * 100) : 0;
                                $statusTone = $pinjaman['status'] == 'aktif' ? 'success' : ($pinjaman['status'] == 'lunas' ? 'info' : 'danger');
                                $inisial = function_exists('mb_substr')
                                    ? mb_strtoupper(mb_substr(trim((string)$pinjaman['nama_lengkap']), 0, 1, 'UTF-8'), 'UTF-8')
                                    : strtoupper(substr(trim((string)$pinjaman['nama_lengkap']), 0, 1));
                                $isGadai = !empty($pinjaman['memerlukan_jaminan']);
                                $punyaPb = ($pinjaman['n_pb']['menunggu'] > 0)
                                    || (isTeller() && ($pinjaman['n_pb']['disetujui'] > 0 || $pinjaman['n_pb']['ditolak'] > 0));
                                $fotoNasabah = trim((string)($pinjaman['foto_pencairan'] ?? ''));
                                $inisialAvatar = $inisial !== '' ? $inisial : '?';
                            ?>
                            <div class="ui-ag-loan tone-<?php echo $statusTone; ?><?php echo $punyaPb ? ' has-alert' : ''; ?>" data-nik="<?php echo layananEscape($pinjaman['ktp']); ?>">
                                <a href="detail_angsuran.php?pinjaman_id=<?php echo $pinjaman['id'] . $qs_filter; ?>" class="ui-ag-loan-link">
                                    <div class="ui-ag-loan-avatar<?php echo $fotoNasabah !== '' ? ' has-photo' : ''; ?>" aria-hidden="true">
                                        <?php if ($fotoNasabah !== ''): ?>
                                            <img src="<?php echo htmlspecialchars(urlFoto($fotoNasabah)); ?>" alt="" loading="lazy" onerror="this.remove(); this.parentElement.classList.remove('has-photo'); this.parentElement.textContent=<?php echo json_encode($inisialAvatar, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($inisialAvatar); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ui-ag-loan-main min-w-0">
                                        <div class="ui-ag-loan-top">
                                            <div class="min-w-0">
                                                <div class="ui-ag-loan-nama-line">
                                                    <strong class="ui-ag-loan-nama"><?php echo htmlspecialchars($pinjaman['nama_lengkap'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <span class="ui-ag-loan-kode-anggota"><?php echo htmlspecialchars($pinjaman['kode_anggota'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                                <div class="ui-ag-loan-kode"><?php echo htmlspecialchars($pinjaman['kode_pinjaman']); ?></div>
                                            </div>
                                            <div class="ui-ag-loan-badges">
                                                <span class="ui-ag-tag <?php echo $isGadai ? 'is-gadai' : 'is-agt'; ?>"><?php echo $isGadai ? 'AGTJ · Gadai' : 'AGT'; ?></span>
                                                <?php if ($pinjaman['n_pb']['menunggu'] > 0): ?>
                                                <span class="badge bg-warning text-dark" title="Pengajuan pembatalan menunggu keputusan"><i class="ti ti-hourglass"></i> <?php echo (int)$pinjaman['n_pb']['menunggu']; ?></span>
                                                <?php endif; ?>
                                                <?php if (isTeller() && $pinjaman['n_pb']['disetujui'] > 0): ?>
                                                <span class="badge bg-success" title="Pembatalan disetujui Admin"><i class="ti ti-check"></i> <?php echo (int)$pinjaman['n_pb']['disetujui']; ?></span>
                                                <?php endif; ?>
                                                <?php if (isTeller() && $pinjaman['n_pb']['ditolak'] > 0): ?>
                                                <span class="badge bg-danger" title="Pembatalan ditolak Admin"><i class="ti ti-x"></i> <?php echo (int)$pinjaman['n_pb']['ditolak']; ?></span>
                                                <?php endif; ?>
                                                <span class="ui-ag-status is-<?php echo $statusTone; ?>"><?php echo htmlspecialchars($pinjaman['status']); ?></span>
                                            </div>
                                        </div>
                                        <div class="ui-ag-loan-stats">
                                            <div class="ui-ag-stat">
                                                <span class="ui-ag-stat-label">Plafon</span>
                                                <span class="ui-ag-stat-value"><?php echo formatRupiah($pinjaman['jumlah_pinjaman']); ?></span>
                                            </div>
                                            <div class="ui-ag-stat">
                                                <span class="ui-ag-stat-label">Sisa pokok</span>
                                                <span class="ui-ag-stat-value is-sisa"><?php echo formatRupiah($pinjaman['sisa_pokok']); ?></span>
                                            </div>
                                            <div class="ui-ag-stat">
                                                <span class="ui-ag-stat-label">Jadwal</span>
                                                <span class="ui-ag-stat-value is-meta"><?php echo !empty($pinjaman['hari_angsuran']) ? labelHariAngsuran($pinjaman['hari_angsuran']) : '—'; ?><?php echo ' · ' . labelTenorRow($pinjaman); ?></span>
                                            </div>
                                            <div class="ui-ag-stat ui-ag-stat-progress">
                                                <div class="d-flex justify-content-between align-items-baseline gap-2">
                                                    <span class="ui-ag-stat-label">Progress</span>
                                                    <span class="ui-ag-stat-pct"><?php echo (int)$pinjaman['angsuran_lunas']; ?>/<?php echo (int)$pinjaman['total_angsuran']; ?></span>
                                                </div>
                                                <div class="progress ui-ag-loan-progress"><div class="progress-bar" style="width: <?php echo max(0, min(100, $progress)); ?>%"></div></div>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="ui-ag-loan-go" aria-hidden="true"><i class="ti ti-chevron-right"></i></span>
                                </a>
                                <?php if ($pinjaman['total_angsuran'] > 0 && $pinjaman['angsuran_lunas'] == $pinjaman['total_angsuran']): ?>
                                <div class="ui-ag-loan-action ui-loan-action">
                                    <a class="btn btn-sm btn-success" target="_blank" rel="noopener"
                                       href="cetak_bukti_pelunasan.php?id=<?php echo (int) $pinjaman['id']; ?>">
                                        <i class="ti ti-receipt-2 me-1"></i>Bukti Pelunasan
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($pager_pinjaman)) ui_pagination($pager_pinjaman, 'angsuran.php'); ?>

                <?php else: /* detail */ ?>
                <span id="daftar-angsuran"></span>

                <?php if ($pinjaman_detail):
                    $progressDetail = $pinjaman_detail['total_angsuran'] > 0
                        ? ($pinjaman_detail['angsuran_lunas'] / $pinjaman_detail['total_angsuran'] * 100) : 0;
                    $statusToneDetail = $pinjaman_detail['status'] == 'aktif' ? 'success' : ($pinjaman_detail['status'] == 'lunas' ? 'info' : 'danger');
                ?>
                <div class="ui-ag-hero ui-pj-detail mb-3">
                    <div class="ui-pj-detail-hero">
                        <div class="min-w-0">
                            <div class="ui-pj-detail-kode"><?php echo htmlspecialchars($pinjaman_detail['kode_pinjaman']); ?></div>
                            <div class="ui-pj-detail-nama"><?php echo htmlspecialchars($pinjaman_detail['nama_lengkap']); ?>
                                <small class="text-muted">(<?php echo htmlspecialchars($pinjaman_detail['kode_anggota']); ?>)</small>
                            </div>
                            <div class="ui-pj-detail-meta">
                                <?php echo htmlspecialchars($pinjaman_detail['nama_jenis']); ?>
                                · <span class="badge rounded-pill bg-<?php echo !empty($pinjaman_detail['memerlukan_jaminan']) ? 'warning text-dark' : 'light text-secondary'; ?>"><?php echo !empty($pinjaman_detail['memerlukan_jaminan']) ? 'AGTJ' : 'AGT'; ?></span>
                            </div>
                        </div>
                        <div class="ui-pj-detail-hero-status">
                            <span class="badge bg-<?php echo $statusToneDetail; ?>"><?php echo htmlspecialchars($pinjaman_detail['status']); ?></span>
                            <a class="btn btn-sm btn-outline-primary mt-2" target="_blank" rel="noopener"
                               href="cetak_buku_angsuran.php?id=<?php echo (int) $pinjaman_detail['id']; ?>">
                                <i class="ti ti-book me-1"></i>Cetak Buku
                            </a>
                        </div>
                    </div>
                    <div class="ui-pj-detail-amount">
                        <div class="ui-pj-detail-amount-item">
                            <span class="ui-pj-detail-amount-label">Sisa pokok</span>
                            <span class="ui-pj-detail-amount-value"><?php echo formatRupiah($pinjaman_detail['sisa_pokok']); ?></span>
                        </div>
                        <div class="ui-pj-detail-amount-item">
                            <span class="ui-pj-detail-amount-label">Plafon</span>
                            <span class="ui-pj-detail-amount-sub"><?php echo formatRupiah($pinjaman_detail['jumlah_pinjaman']); ?></span>
                        </div>
                    </div>
                    <div class="ui-ag-hero-progress">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Progress angsuran</span>
                            <span><?php echo (int)$pinjaman_detail['angsuran_lunas']; ?>/<?php echo (int)$pinjaman_detail['total_angsuran']; ?></span>
                        </div>
                        <div class="progress"><div class="progress-bar bg-success" style="width: <?php echo $progressDetail; ?>%"></div></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card mt-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-calendar" aria-hidden="true"></i>Jadwal angsuran</span>
                        <span class="ui-dash-sec-note"><?php echo (int)count($angsuran_list); ?> cicilan</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($angsuran_list)): ?>
                        <div class="text-center py-5 px-3">
                            <i class="ti ti-info-circle sk-icon-3x text-info mb-3"></i>
                            <h5 class="text-muted">Belum ada angsuran</h5>
                            <p class="text-muted mb-0">Belum ada jadwal angsuran untuk pinjaman ini.</p>
                        </div>
                        <?php else: ?>
                        <div class="ui-mobile-cards d-md-none p-3">
                            <?php foreach ($angsuran_list as $angsuran):
                                $pbM = isset($pembatalan[$angsuran['id']]) ? $pembatalan[$angsuran['id']] : null;
                                $statusLabel = $angsuran['status'] == 'lunas' ? 'Lunas' : ($angsuran['status'] == 'telat' ? 'Telat' : 'Belum Bayar');
                                $statusClass = $angsuran['status'] == 'lunas' ? 'success' : ($angsuran['status'] == 'telat' ? 'warning' : 'danger');
                                $aksiM = angsuranItemAksiMenu($db, $angsuran, $keranjangIds, $pbM);
                            ?>
                            <div class="ui-mobile-card">
                                <div class="ui-mobile-card-row">
                                    <div class="min-w-0">
                                        <div class="ui-mobile-card-title">Angsuran ke-<?php echo (int)$angsuran['angsuran_ke']; ?>/<?php echo count($angsuran_list); ?></div>
                                        <div class="ui-mobile-card-meta">Jatuh tempo <?php echo formatTanggalUi($angsuran['tanggal_jatuh_tempo']); ?></div>
                                    </div>
                                    <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                </div>
                                <div class="ui-mobile-card-value mb-1"><?php echo formatRupiah($angsuran['jumlah_angsuran']); ?></div>
                                <?php if ((float)$angsuran['denda'] > 0): ?>
                                    <div class="ui-mobile-card-meta text-danger">Denda <?php echo formatRupiah($angsuran['denda']); ?></div>
                                <?php endif; ?>
                                <div class="ui-mobile-card-actions">
                                    <div class="ui-pay-actions<?php echo !empty($aksiM['in_cart']) ? ' is-in-cart' : ''; ?>">
                                        <?php if (!empty($aksiM['in_cart'])): ?><span class="ui-cart-chip" title="Sudah di keranjang"><i class="ti ti-basket" aria-hidden="true"></i><span>Keranjang</span></span><?php endif; ?>
                                        <?php echo ui_action_menu($aksiM['items']); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="table-responsive ui-table-scroll d-none d-md-block" id="tabelJadwalAngsuran">
                            <table class="table table-hover align-middle mb-0 js-datatable"
                                   id="dtJadwalAngsuran"
                                   data-dt-state="jadwal-angsuran-<?= (int)($_GET['pinjaman_id'] ?? 0) ?>"
                                   data-dt-page-length="12"
                                   data-dt-order="0,asc;1,asc"
                                   data-dt-filter="7"
                                   data-dt-date-col="2">
                                <thead>
                                    <tr>
                                        <th class="dt-prio">Prio</th>
                                        <th>Ke</th>
                                        <th>Jatuh Tempo</th>
                                        <th class="text-end">Pokok</th>
                                        <th class="text-end">Bunga</th>
                                        <th class="text-end">Denda</th>
                                        <th class="text-end">Total</th>
                                        <th>Status</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($angsuran_list as $angsuran):
                                        $pb = isset($pembatalan[$angsuran['id']]) ? $pembatalan[$angsuran['id']] : null;
                                        $menunggu = $pb && $pb['status'] === 'menunggu';
                                        $prio = angsuranPrioBaris($angsuran, $pb);
                                        $aksi = angsuranItemAksiMenu($db, $angsuran, $keranjangIds, $pb);
                                        if ($angsuran['status'] == 'lunas' && $menunggu) {
                                            $filterLabel = 'Pengajuan Pembatalan';
                                        } elseif ($angsuran['status'] == 'lunas') {
                                            $filterLabel = 'Lunas';
                                        } elseif ($angsuran['status'] == 'telat' || (!empty($angsuran['tanggal_jatuh_tempo']) && $angsuran['tanggal_jatuh_tempo'] < date('Y-m-d') && $angsuran['status'] != 'lunas')) {
                                            $filterLabel = 'Telat';
                                        } else {
                                            $filterLabel = 'Belum Bayar';
                                        }
                                    ?>
                                    <tr class="<?php echo $prio < 9 ? 'ui-row-action' : ''; ?>">
                                        <td><?php echo (int)$prio; ?></td>
                                        <td><?php echo (int)$angsuran['angsuran_ke']; ?><small class="text-muted">/<?php echo count($angsuran_list); ?></small></td>
                                        <td data-order="<?php echo htmlspecialchars($angsuran['tanggal_jatuh_tempo'] . ' 00:00:00'); ?>" data-date="<?php echo htmlspecialchars($angsuran['tanggal_jatuh_tempo']); ?>">
                                            <small><?php echo formatTanggalUi($angsuran['tanggal_jatuh_tempo']); ?></small>
                                            <?php if (!$angsuran['tanggal_bayar'] && $angsuran['tanggal_jatuh_tempo'] < date('Y-m-d')): ?>
                                            <br><small class="text-danger">Terlambat</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo formatRupiah($angsuran['pokok']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($angsuran['bunga']); ?></td>
                                        <td class="text-end text-danger"><?php echo formatRupiah($angsuran['denda']); ?></td>
                                        <td class="text-end fw-semibold"><?php echo formatRupiah($angsuran['jumlah_angsuran']); ?></td>
                                        <td data-filter="<?php echo htmlspecialchars($filterLabel); ?>">
                                            <?php if ($angsuran['status'] == 'lunas' && $menunggu): ?>
                                                <span class="badge bg-warning text-dark">Pengajuan Pembatalan</span>
                                                <br><small>Lunas <?php echo formatTanggalUi($angsuran['tanggal_bayar']); ?></small>
                                            <?php elseif ($angsuran['status'] == 'lunas'): ?>
                                                <span class="badge bg-success">Lunas</span>
                                                <br><small><?php echo formatTanggalUi($angsuran['tanggal_bayar']); ?></small>
                                            <?php elseif ($filterLabel === 'Telat'): ?>
                                                <span class="badge bg-warning text-dark">Telat</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Belum Bayar</span>
                                            <?php endif; ?>
                                            <?php if ($pb && $pb['status'] === 'ditolak' && $angsuran['status'] == 'lunas'): ?>
                                                <br><span class="badge bg-danger" title="<?php echo htmlspecialchars($pb['catatan_admin']); ?>">Pembatalan Ditolak Admin</span>
                                                <?php if (!empty($pb['catatan_admin'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($pb['catatan_admin']); ?></small><?php endif; ?>
                                            <?php elseif ($pb && $pb['status'] === 'disetujui' && $angsuran['status'] != 'lunas'): ?>
                                                <br><span class="badge bg-success" title="<?php echo htmlspecialchars($pb['catatan_admin']); ?>">Pembatalan Disetujui</span>
                                                <?php if (!empty($pb['catatan_admin'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($pb['catatan_admin']); ?></small><?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="table-actions text-end">
                                            <div class="ui-pay-actions<?php echo !empty($aksi['in_cart']) ? ' is-in-cart' : ''; ?>">
                                                <?php if (!empty($aksi['in_cart'])): ?><span class="ui-cart-chip" title="Sudah di keranjang"><i class="ti ti-basket" aria-hidden="true"></i><span>Keranjang</span></span><?php endif; ?>
                                                <?php echo ui_action_menu($aksi['items']); ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; /* list vs detail */ ?>
            </div>
    <?php include __DIR__ . '/layout/shell_end.php'; ?>

    <div class="modal fade ui-tx-modal ui-tx-modal-angsuran" id="bayarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" id="formBayarAngsuran">
                    <div class="modal-header">
                        <div>
                            <div class="text-uppercase small text-white-50 fw-semibold" id="bayar_mode_label">Pembayaran</div>
                            <h5 class="modal-title mb-0">Angsuran ke-<span id="bayar_angsuran_ke"></span></h5>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body ui-ag-form">
                        <?php echo layananToken(); ?>
                        <input type="hidden" name="id" id="bayar_id">
                        <input type="hidden" name="pinjaman_id" id="bayar_pinjaman_id" value="">
                        <section class="ui-form-section ui-pj-section ui-ag-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-cash" aria-hidden="true"></i>1. Nominal &amp; metode</h6>
                            <div class="mb-3">
                                <label class="form-label">Metode pembayaran</label>
                                <select name="metode" class="form-select" required>
                                    <option value="cash">Cash</option>
                                    <option value="transfer">Transfer</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Total dibayar</label>
                                <input type="text" class="form-control form-control-lg fw-semibold" id="bayar_total" readonly>
                            </div>
                        </section>
                        <section class="ui-form-section ui-pj-section ui-ag-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-alert-triangle" aria-hidden="true"></i>2. Denda</h6>
                            <div class="mb-0">
                                <label for="bayar_denda" class="form-label">Denda<?php echo isAdmin() ? '' : ' (otomatis)'; ?></label>
                                <input type="number" class="form-control" id="bayar_denda" name="denda" <?php echo isAdmin()?'':'readonly'; ?> value="0" min="0" step="1">
                                <div class="form-text" id="bayar_denda_info"></div>
                            </div>
                        </section>
                        <div class="alert alert-info mb-0 ui-ag-info" id="bayar_hint">
                            <small><i class="ti ti-info-circle me-1"></i><span id="bayar_hint_text">Pilih cara proses di bawah.</span></small>
                        </div>
                    </div>
                    <div class="modal-footer flex-wrap">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="tambah_keranjang" id="btnKeranjang" class="btn btn-outline-success"><i class="ti ti-shopping-cart-plus me-1"></i>Masukkan keranjang</button>
                        <button type="submit" name="bayar_langsung" id="btnLangsung" class="btn btn-success d-none"><i class="ti ti-bolt me-1"></i>Proses langsung</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (isAdmin()): ?>
    <div class="modal fade ui-tx-modal ui-tx-modal-angsuran" id="batalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">Batalkan Pembayaran</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="batal_id">
                        <p class="mb-2">Batalkan pembayaran:</p>
                        <dl class="ui-tx-confirm-dl mb-3">
                            <div><dt>Angsuran</dt><dd>ke-<span id="batal_ke"></span> — <span id="batal_kode"></span></dd></div>
                            <div><dt>Nominal</dt><dd id="batal_total"></dd></div>
                        </dl>
                        <div class="mb-3">
                            <label for="batal_alasan" class="form-label">Alasan pembatalan <span class="text-danger">*</span></label>
                            <textarea name="alasan" id="batal_alasan" class="form-control" rows="3" required minlength="5" maxlength="250"
                                      placeholder="Contoh: salah pencet bayar oleh teller"></textarea>
                        </div>
                        <div class="alert alert-info mb-0 ui-ag-info">
                            <small><i class="ti ti-info-circle me-1"></i>
                            Cicilan kembali <strong>belum bayar</strong>, kas dan saldo dikurangi, koreksi dicatat. Bisa dibayar ulang setelahnya.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                        <button type="submit" name="batalkan_bayar" class="btn btn-danger">Batalkan Pembayaran</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tx-modal ui-tx-modal-angsuran" id="putusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title" id="putus_judul"></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="pengajuan_id" id="putus_id">
                        <input type="hidden" name="keputusan" id="putus_keputusan">
                        <dl class="ui-tx-confirm-dl mb-3">
                            <div><dt>Angsuran</dt><dd>ke-<span id="putus_ke"></span> — <span id="putus_kode"></span></dd></div>
                            <div><dt>Nominal</dt><dd id="putus_total"></dd></div>
                        </dl>
                        <div class="border rounded p-2 mb-3 bg-light">
                            <div class="small text-muted">Diajukan oleh <strong id="putus_teller"></strong> pada <span id="putus_tanggal"></span></div>
                            <div class="mt-1"><span class="text-muted">Alasan Teller:</span><br><strong id="putus_alasan"></strong></div>
                        </div>
                        <div class="mb-3">
                            <label for="putus_catatan" class="form-label">Catatan Admin <span id="putus_catatan_opsi" class="text-muted">(opsional)</span></label>
                            <textarea name="catatan" id="putus_catatan" class="form-control" rows="2" maxlength="250"
                                      placeholder="Catatan untuk Teller..."></textarea>
                        </div>
                        <div class="alert mb-0" id="putus_info"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                        <button type="submit" name="putuskan_pembatalan" class="btn" id="putus_submit"></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isTeller()): ?>
    <div class="modal fade ui-tx-modal ui-tx-modal-angsuran" id="ajukanModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">Ajukan Pembatalan</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="ajukan_id">
                        <p class="mb-2">Ajukan pembatalan pembayaran:</p>
                        <dl class="ui-tx-confirm-dl mb-3">
                            <div><dt>Angsuran</dt><dd>ke-<span id="ajukan_ke"></span> — <span id="ajukan_kode"></span></dd></div>
                            <div><dt>Nominal</dt><dd id="ajukan_total"></dd></div>
                        </dl>
                        <div class="mb-3">
                            <label for="ajukan_alasan" class="form-label">Alasan pembatalan <span class="text-danger">*</span></label>
                            <textarea name="alasan" id="ajukan_alasan" class="form-control" rows="3" required minlength="5" maxlength="250"
                                      placeholder="Contoh: salah klik bayar, nasabah belum membayar"></textarea>
                        </div>
                        <div class="alert alert-info mb-0 ui-ag-info">
                            <small><i class="ti ti-info-circle me-1"></i>
                            Pembayaran <strong>belum dibatalkan</strong> sampai Admin menyetujui. Hasil keputusan tampil di halaman ini.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                        <button type="submit" name="ajukan_pembatalan" class="btn btn-warning">Kirim Pengajuan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function isiModalAngsuran(prefix, b) {
            document.getElementById(prefix + '_ke').textContent = b.getAttribute('data-angsuran_ke');
            document.getElementById(prefix + '_kode').textContent = b.getAttribute('data-kode');
            document.getElementById(prefix + '_total').textContent = 'Rp ' + Number(b.getAttribute('data-total')).toLocaleString('id-ID');
        }
        var batalModalEl = document.getElementById('batalModal');
        if (batalModalEl) {
            batalModalEl.addEventListener('show.bs.modal', function (event) {
                var b = event.relatedTarget;
                document.getElementById('batal_id').value = b.getAttribute('data-id');
                isiModalAngsuran('batal', b);
                document.getElementById('batal_alasan').value = '';
            });
        }
        var ajukanModalEl = document.getElementById('ajukanModal');
        if (ajukanModalEl) {
            ajukanModalEl.addEventListener('show.bs.modal', function (event) {
                var b = event.relatedTarget;
                document.getElementById('ajukan_id').value = b.getAttribute('data-id');
                isiModalAngsuran('ajukan', b);
                document.getElementById('ajukan_alasan').value = '';
            });
        }
        var putusModalEl = document.getElementById('putusModal');
        if (putusModalEl) {
            putusModalEl.addEventListener('show.bs.modal', function (event) {
                var b = event.relatedTarget;
                var setuju = b.getAttribute('data-keputusan') === 'setuju';
                document.getElementById('putus_id').value = b.getAttribute('data-pengajuan');
                document.getElementById('putus_keputusan').value = b.getAttribute('data-keputusan');
                isiModalAngsuran('putus', b);
                document.getElementById('putus_teller').textContent = b.getAttribute('data-teller');
                document.getElementById('putus_tanggal').textContent = b.getAttribute('data-tanggal');
                document.getElementById('putus_alasan').textContent = b.getAttribute('data-alasan');
                document.getElementById('putus_catatan').value = '';
                document.getElementById('putus_judul').textContent = setuju ? 'Setujui Pembatalan Pembayaran' : 'Tolak Pengajuan Pembatalan';
                var info = document.getElementById('putus_info');
                info.className = 'alert mb-0 ' + (setuju ? 'alert-warning' : 'alert-info');
                info.innerHTML = setuju
                    ? '<small><i class="ti ti-alert-triangle me-1"></i>Pembayaran <strong>dibatalkan</strong>: cicilan kembali belum bayar, kas dan saldo dikurangi sebesar nominal tercatat.</small>'
                    : '<small><i class="ti ti-info-circle me-1"></i>Angsuran tetap <strong>Lunas</strong>, tanpa perubahan saldo. Teller melihat penolakan beserta catatan Anda.</small>';
                var btn = document.getElementById('putus_submit');
                btn.className = 'btn ' + (setuju ? 'btn-success' : 'btn-danger');
                btn.textContent = setuju ? 'Setujui & Batalkan Pembayaran' : 'Tolak Pengajuan';
            });
        }

        var bayarModal = document.getElementById('bayarModal');
        if (bayarModal) {
            bayarModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                if (!button) return;
                document.getElementById('bayar_id').value = button.getAttribute('data-id');
                document.getElementById('bayar_angsuran_ke').textContent = button.getAttribute('data-angsuran_ke');
                document.getElementById('bayar_pinjaman_id').value = button.getAttribute('data-pinjaman_id') || '';
                var mode = button.getAttribute('data-mode') || 'keranjang';
                var label = document.getElementById('bayar_mode_label');
                var hint = document.getElementById('bayar_hint_text');
                var btnK = document.getElementById('btnKeranjang');
                var btnL = document.getElementById('btnLangsung');
                if (mode === 'langsung') {
                    label.textContent = 'Proses langsung';
                    hint.textContent = 'Pembayaran langsung masuk kas & laporan setelah dikonfirmasi.';
                    btnL.classList.remove('d-none');
                    btnK.classList.add('d-none');
                } else {
                    label.textContent = 'Masukkan ke keranjang';
                    hint.textContent = 'Ditampung di keranjang. Kas baru berubah saat Anda klik Proses semua.';
                    btnK.classList.remove('d-none');
                    btnL.classList.add('d-none');
                }
                var kredit = Number(button.getAttribute('data-kredit') || 0);
                var lelang = Number(button.getAttribute('data-lelang') || 0);
                bayarModal.dataset.lelang = lelang;
                var total = Number(button.getAttribute('data-total')) + Math.max(0, Number(button.getAttribute('data-denda') || 0) - kredit);
                total = Math.max(0, total - lelang);
                bayarModal.dataset.pokokBunga = Number(button.getAttribute('data-total'));
                bayarModal.dataset.kredit = kredit;
                document.getElementById('bayar_total').value = 'Rp ' + total.toLocaleString('id-ID');
                var denda = Number(button.getAttribute('data-denda'));
                bayarModal.dataset.dendaOtomatis = button.getAttribute('data-denda_auto');
                document.getElementById('bayar_denda').value = denda;
                document.getElementById('bayar_denda_info').textContent = button.getAttribute('data-denda_info') || '';
            });
            var dendaInput = document.getElementById('bayar_denda');
            if (dendaInput) {
                dendaInput.addEventListener('input', function () {
                    var total = Number(bayarModal.dataset.pokokBunga) + Math.max(0, Number(this.value.trim() === '' ? bayarModal.dataset.dendaOtomatis : this.value) - Number(bayarModal.dataset.kredit));
                    total = Math.max(0, total - Number(bayarModal.dataset.lelang || 0));
                    document.getElementById('bayar_total').value = 'Rp ' + total.toLocaleString('id-ID');
                });
            }
        }
    </script>
<?php if (isTeller()) { tandaiKeputusanPembatalanDilihat($db, $_SESSION['user_id']); } ?>
<script>
(function () {
    var cariPinjaman = document.getElementById('cariPinjaman');
    var clearCariPinjaman = document.getElementById('clearCariPinjaman');
    var daftarPinjaman = document.querySelector('.ui-ag-loan-list');
    if (!cariPinjaman || !daftarPinjaman) return;
    var kosongCari = daftarPinjaman.querySelector('.ui-loan-search-empty');
    if (!kosongCari) {
        kosongCari = document.createElement('div');
        kosongCari.className = 'ui-loan-search-empty text-center text-muted py-4';
        kosongCari.innerHTML = '<i class="ti ti-search me-1"></i>Nasabah tidak ditemukan';
        kosongCari.hidden = true;
        daftarPinjaman.appendChild(kosongCari);
    }
    cariPinjaman.addEventListener('input', function () {
        var q = this.value.trim().toLocaleLowerCase('id');
        if (clearCariPinjaman) clearCariPinjaman.hidden = !q;
        var terlihat = 0;
        daftarPinjaman.querySelectorAll(':scope > .ui-ag-loan').forEach(function (row) {
            var cocok = !q || ((row.textContent || '') + ' ' + (row.dataset.nik || '')).toLocaleLowerCase('id').includes(q);
            row.hidden = !cocok;
            if (cocok) terlihat++;
        });
        kosongCari.hidden = terlihat > 0;
    });
    if (clearCariPinjaman) {
        clearCariPinjaman.addEventListener('click', function () {
            cariPinjaman.value = '';
            cariPinjaman.dispatchEvent(new Event('input'));
            cariPinjaman.focus();
        });
    }
})();
</script>
</body>
</html>
