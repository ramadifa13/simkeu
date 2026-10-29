<?php
require_once '../includes/auth.php';
require_once '../config/config.php';
require_once '../includes/migrasi_export_service.php';
require_once '../includes/migrasi_dokumen_cadangan.php';

// Only admin can access this page
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini!";
    header("Location: dashboard.php");
    exit();
}

// Fungsi untuk backup database menggunakan PHP native
function backupDatabase($db, $backup_path) {
    try {
        // Get all tables
        $tables = [];
        $result = $db->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        $output = "-- Database Backup\n";
        $output .= "-- Tanggal: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- " . str_repeat("-", 50) . "\n\n";
        
        // Disable foreign key checks
        $output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        
        foreach ($tables as $table) {
            // Get create table syntax
            $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $output .= "\n-- Struktur tabel `$table`\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $create['Create Table'] . ";\n\n";
            
            // Get data
            $rows = $db->query("SELECT * FROM `$table`");
            $num_rows = $rows->rowCount();
            
            if ($num_rows > 0) {
                $output .= "-- Data tabel `$table`\n";
                
                // Get column names
                $columns = [];
                for ($i = 0; $i < $rows->columnCount(); $i++) {
                    $col = $rows->getColumnMeta($i);
                    $columns[] = "`" . $col['name'] . "`";
                }
                $column_str = implode(", ", $columns);
                
                // Process rows in chunks
                $chunk_size = 100;
                $row_count = 0;
                
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    if ($row_count % $chunk_size == 0) {
                        if ($row_count > 0) {
                            $output .= ";\n";
                        }
                        $output .= "INSERT INTO `$table` ($column_str) VALUES\n";
                    } else {
                        $output .= ",\n";
                    }
                    
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = "NULL";
                        } else {
                            $values[] = $db->quote($value);
                        }
                    }
                    
                    $output .= "(" . implode(", ", $values) . ")";
                    $row_count++;
                }
                
                if ($row_count > 0) {
                    $output .= ";\n";
                }
            }
            
            $output .= "\n-- " . str_repeat("-", 50) . "\n";
        }
        
        // Enable foreign key checks
        $output .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
        
        // Write to file
        if (file_put_contents($backup_path, $output) === false) {
            throw new Exception("Gagal menulis file backup");
        }
        
        return true;
        
    } catch (Exception $e) {
        throw new Exception("Error saat backup: " . $e->getMessage());
    }
}

// Handle database backup
if ($_POST && isset($_POST['backup_database'])) {
    try {
        $backup_file = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $backup_path = '../backups/' . $backup_file;
        
        // Ensure backups directory exists
        if (!is_dir('../backups')) {
            if (!mkdir('../backups', 0755, true)) {
                throw new Exception("Gagal membuat direktori backups");
            }
        }
        
        // Check if directory is writable
        if (!is_writable('../backups')) {
            throw new Exception("Direktori backups tidak dapat ditulis");
        }
        
        // Perform backup
        if (backupDatabase($db, $backup_path)) {
            $_SESSION['success'] = "Backup database berhasil dibuat: " . $backup_file;
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Gagal membuat backup: " . $e->getMessage();
    }
    
    header("Location: backup.php");
    exit();
}

// Backup Excel format template migrasi (bisa di-import ulang)
if ($_POST && isset($_POST['backup_excel_migrasi'])) {
    try {
        layananValidasiToken();
        migrasiSimpanCadanganDokumen($db);
        $hasil = migrasiExportBackupExcel($db, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups');
        $_SESSION['success'] = 'Backup Excel migrasi berhasil: ' . $hasil['name']
            . ' (' . (int)$hasil['counts']['nasabah'] . ' nasabah, '
            . (int)$hasil['counts']['pinjaman'] . ' pinjaman, '
            . (int)$hasil['counts']['angsuran'] . ' angsuran). Cadangan foto/TTD juga diperbarui.';
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Gagal membuat backup Excel: ' . $e->getMessage();
    }
    header('Location: backup.php');
    exit();
}

// Backup ZIP lengkap: Excel migrasi + folder uploads/
if ($_POST && isset($_POST['backup_zip_lengkap'])) {
    try {
        layananValidasiToken();
        $hasil = migrasiExportBackupZip($db, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups');
        $_SESSION['success'] = 'Backup ZIP lengkap berhasil: ' . $hasil['name']
            . ' (Excel + ' . (int)$hasil['files'] . ' file uploads). '
            . 'Di hosting: ekstrak uploads/, lalu import Excel dari folder migrasi/.';
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Gagal membuat backup ZIP: ' . $e->getMessage();
    }
    header('Location: backup.php');
    exit();
}

// Handle delete backup
if ($_POST && isset($_POST['delete'])) {
    $file = basename((string)$_POST['delete']); // Prevent directory traversal
    $file_path = '../backups/' . $file;
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    if (in_array($ext, ['sql', 'xlsx', 'zip'], true) && is_file($file_path)) {
        if (unlink($file_path)) {
            $_SESSION['success'] = "Backup berhasil dihapus";
        } else {
            $_SESSION['error'] = "Gagal menghapus backup";
        }
    } else {
        $_SESSION['error'] = "File tidak ditemukan";
    }
    
    header("Location: backup.php");
    exit();
}

// Handle download backup
if ($_GET && isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $file_path = '../backups/' . $file;
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $mime = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
        'sql' => 'application/sql',
    ];
    
    if (isset($mime[$ext]) && is_file($file_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime[$ext]);
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit();
    } else {
        $_SESSION['error'] = "File tidak ditemukan";
        header("Location: backup.php");
        exit();
    }
}

// Handle data reset (TERMASUK WASERDA)
if ($_POST && isset($_POST['reset_data'])) {
    $confirmation = $_POST['confirmation'] ?? '';
    
    if ($confirmation === 'RESET') {
        $tables = [
            'transaksi','simpanan','pinjaman','angsuran','pengajuan_pinjaman',
            'pembelian_listrik','pembelian_indihome','kas','log_koreksi','waserda_transaksi'
        ];
        try {
            $db->beginTransaction();
            
            // Disable foreign key checks
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            foreach ($tables as $table) {
                // Use DELETE instead of TRUNCATE to avoid foreign key constraints
                $delete_query = "DELETE FROM {$table}";
                $db->exec($delete_query);
            }
            
            // Reset anggota data but keep the structure and all uploaded documents.
            // File paths are intentionally preserved so photos/TTD can still be
            // reused when the member is imported again with the same code.
            $reset_anggota_query = "UPDATE anggota SET 
                                   alamat = '', 
                                   telepon = '', 
                                   email = '', 
                                   tempat_lahir = '', 
                                   tanggal_lahir = NULL, 
                                   pekerjaan = '', 
                                   ktp = '', 
                                   rekening_bank = '',
                                   tanggal_daftar = CURDATE()";
            $db->exec($reset_anggota_query);
            
            // Re-enable foreign key checks
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            
            $db->commit();
            // ALTER TABLE menyebabkan implicit commit di MySQL, sehingga harus dijalankan
            // setelah transaksi selesai agar rollback tidak pernah gagal.
            foreach ($tables as $table) $db->exec("ALTER TABLE {$table} AUTO_INCREMENT = 1");
            $_SESSION['success'] = "Data berhasil direset! Data waserda, transaksi, simpanan, pinjaman telah dihapus. Data master dan pengguna tetap tersimpan.";
            
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            // Re-enable foreign key checks even if error
            try { $db->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Throwable $ignored) {}
            $_SESSION['error'] = "Gagal mereset data: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Konfirmasi tidak valid! Ketik 'RESET' untuk mengonfirmasi.";
    }
    
    header("Location: backup.php");
    exit();
}

// Reset total untuk migrasi: semua data/master/pengaturan dikosongkan,
// kecuali akun Admin yang sedang dipakai agar aplikasi tidak terkunci.
if ($_POST && isset($_POST['reset_total_migrasi'])) {
    if (trim((string)($_POST['confirmation_total'] ?? '')) !== 'RESET TOTAL') {
        $_SESSION['error'] = "Konfirmasi tidak valid! Ketik 'RESET TOTAL'.";
    } else {
        try {
            layananValidasiToken();
            $adminId = (int)($_SESSION['user_id'] ?? 0);
            if ($adminId <= 0) throw new RuntimeException('Admin aktif tidak ditemukan.');
            $cekAdmin=$db->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND status='aktif'");
            $cekAdmin->execute([$adminId]);
            if (!$cekAdmin->fetchColumn()) throw new RuntimeException('Akun Admin aktif wajib tersedia untuk reset total.');
            // Cadangkan SEMUA foto/TTD dulu (termasuk foto pencairan + perjanjian)
            // agar setelah import dengan kode yang sama dokumen otomatis balik.
            $cadangan = migrasiSimpanCadanganDokumen($db);
            $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            // Do not remove uploaded files.  They live under uploads/ and are
            // deliberately outside this database reset.  The anggota table is
            // also retained so existing document references (foto KTP/KK,
            // pasangan) remain available and can be matched by kode_anggota
            // during the next import.
            // Pertahankan identitas koperasi, TTD Admin, meta dokumen, dan cadangan foto/TTD.
            $skip=['migrations','users','anggota','koperasi','pengaturan_perjanjian','migrasi_anggota_dokumen','migrasi_dokumen_cadangan'];
            $target=array_values(array_filter($tables,function($table) use ($skip) { return !in_array($table,$skip,true); }));
            $db->beginTransaction(); $db->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($target as $table) $db->exec("DELETE FROM `".str_replace('`','``',$table)."`");
            $hapusUser=$db->prepare('DELETE FROM users WHERE id <> ?'); $hapusUser->execute([$adminId]);
            // Remove stale login links without touching the member documents.
            $db->exec("UPDATE anggota SET user_id = NULL, status = 'nonaktif'");
            $db->exec('SET FOREIGN_KEY_CHECKS=1'); $db->commit();
            foreach ($target as $table) $db->exec("ALTER TABLE `".str_replace('`','``',$table)."` AUTO_INCREMENT=1");
            $_SESSION['success']='Reset total selesai. Cadangan dokumen: '.(int)$cadangan['anggota'].' nasabah, '.(int)$cadangan['pinjaman'].' pinjaman. Setelah import (kode sama), foto KTP/KK/pencairan dan TTD perjanjian dipulihkan otomatis. File uploads/ tidak dihapus.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            try { $db->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
            $_SESSION['error']='Gagal melakukan reset total: '.$e->getMessage();
        }
    }
    header('Location: backup.php'); exit();
}

// Get existing backups
$backups = [];
$backup_dir = '../backups/';
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($file !== '.' && $file !== '..' && in_array($ext, ['sql', 'xlsx', 'zip'], true)) {
            $file_path = $backup_dir . $file;
            $backups[] = [
                'name' => $file,
                'size' => filesize($file_path),
                'modified' => filemtime($file_path),
                'type' => $ext,
            ];
        }
    }
    
    // Sort by modification time (newest first)
    usort($backups, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
}

// Get database name from PDO connection
try {
    $db_name = $db->query("SELECT DATABASE()")->fetchColumn();
} catch (Exception $e) {
    $db_name = defined('DB_NAME') ? DB_NAME : 'koperasi_db';
}

// Get database size
$size_query = "SELECT 
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb 
    FROM information_schema.tables 
    WHERE table_schema = :dbname";
$size_stmt = $db->prepare($size_query);
$size_stmt->bindValue(':dbname', $db_name);
$size_stmt->execute();
$db_size = $size_stmt->fetch(PDO::FETCH_ASSOC);

// Get database config
$db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
$db_user = defined('DB_USER') ? DB_USER : 'root';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup & Reset - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .backup-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .danger-zone {
            border: 2px solid #dc3545;
            border-left: 6px solid #dc3545;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-database me-2"></i>Backup & Reset Data
                    </h1>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Database Information -->
                    <div class="col-lg-4 mb-4">
                        <div class="card backup-card">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informasi Database</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted">Nama Database</small>
                                    <div class="h6"><?php echo htmlspecialchars($db_name); ?></div>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Ukuran Database</small>
                                    <div class="h6"><?php echo $db_size['size_mb'] ?? '0'; ?> MB</div>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Jumlah Backup</small>
                                    <div class="h6"><?php echo count($backups); ?> file</div>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Total Ukuran Backup</small>
                                    <?php 
                                    $total_size = 0;
                                    foreach ($backups as $b) {
                                        $total_size += $b['size'];
                                    }
                                    ?>
                                    <div class="h6"><?php echo round($total_size / 1024 / 1024, 2); ?> MB</div>
                                </div>
                                <div>
                                    <small class="text-muted">Server</small>
                                    <div class="h6"><?php echo htmlspecialchars($db_host); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Info Backup -->
                        <div class="card backup-card mt-4">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informasi Backup</h6>
                            </div>
                            <div class="card-body">
                                <p class="small text-muted mb-2">
                                    <i class="fas fa-check-circle text-success me-1"></i>
                                    Backup dibuat menggunakan PHP native (tanpa mysqldump)
                                </p>
                                <p class="small text-muted mb-2">
                                    <i class="fas fa-database me-1"></i>
                                    Semua tabel akan dibackup termasuk struktur dan data
                                </p>
                                <p class="small text-muted">
                                    <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                                    File backup disimpan di folder <code>backups/</code>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Backup Database -->
                    <div class="col-lg-8">
                        <div class="card backup-card mb-4">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-download me-2"></i>Backup Database</h6>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">
                                    Buat backup database untuk keamanan data. Tersedia format SQL (restore penuh)
                                    dan Excel format migrasi (bisa diedit lalu di-import ulang lewat Import Migrasi).
                                </p>
                                
                                <form method="POST"><?php echo layananToken(); ?>
                                    <div class="alert alert-info">
                                        <small>
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Database:</strong> <?php echo htmlspecialchars($db_name); ?><br>
                                            <strong>Metode:</strong> Backup PHP Native (tanpa mysqldump)
                                        </small>
                                    </div>
                                    
                                    <button type="submit" name="backup_database" class="btn btn-success">
                                        <i class="fas fa-download me-2"></i>Backup SQL
                                    </button>
                                </form>
                                <form method="POST" class="mt-3"><?php echo layananToken(); ?>
                                    <div class="alert alert-success mb-2">
                                        <small>
                                            <i class="fas fa-file-excel me-2"></i>
                                            Excel berisi Nasabah (foto KTP/KK/pasangan), Pinjaman (foto pencairan), Angsuran_Historis, master, Dapin, kas, dan konfigurasi.
                                            ZIP lengkap = Excel + seluruh folder <code>uploads/</code> (disarankan untuk pindah hosting).
                                        </small>
                                    </div>
                                    <button type="submit" name="backup_excel_migrasi" class="btn btn-outline-success">
                                        <i class="fas fa-file-excel me-2"></i>Backup Excel (format migrasi)
                                    </button>
                                    <button type="submit" name="backup_zip_lengkap" class="btn btn-success">
                                        <i class="fas fa-file-archive me-2"></i>Backup ZIP lengkap (Excel + uploads)
                                    </button>
                                    <a href="import_migrasi.php" class="btn btn-link">Buka Import Migrasi</a>
                                </form>
                                <hr class="my-4">
                                <h6 class="text-danger">Reset Total untuk Migrasi</h6>
                                <p class="small text-muted mb-2">Menghapus transaksi &amp; master. Sebelum hapus, sistem <strong>mencadangkan semua foto &amp; TTD</strong>. Setelah import dengan kode sama, foto nasabah, foto pencairan, dan TTD perjanjian otomatis kembali. Folder uploads/ tidak dihapus.</p>
                                <form method="POST" id="resetTotalForm"><?php echo layananToken(); ?>
                                    <div class="mb-3"><label class="form-label text-danger">Ketik <strong>RESET TOTAL</strong></label>
                                        <input type="text" name="confirmation_total" class="form-control" placeholder="RESET TOTAL" required pattern="RESET TOTAL"></div>
                                    <button type="submit" name="reset_total_migrasi" class="btn btn-outline-danger" onclick="return confirmResetTotal()"><i class="fas fa-skull-crossbones me-2"></i>Reset Total untuk Migrasi</button>
                                </form>
                            </div>
                        </div>

                        <!-- Existing Backups -->
                        <div class="card backup-card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-history me-2"></i>Backup Tersimpan</h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($backups)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Nama File</th>
                                                    <th>Ukuran</th>
                                                    <th>Tanggal</th>
                                                    <th>Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($backups as $backup): ?>
                                                <tr>
                                                    <td>
                                                        <i class="fas <?php echo (($backup['type'] ?? '') === 'xlsx') ? 'fa-file-excel text-success' : 'fa-file-alt text-muted'; ?> me-2"></i>
                                                        <?php echo htmlspecialchars($backup['name']); ?>
                                                        <small class="text-muted">(<?php echo strtoupper($backup['type'] ?? 'sql'); ?>)</small>
                                                    </td>
                                                    <td><?php echo round($backup['size'] / 1024, 2); ?> KB</td>
                                                    <td><?php echo date('d/m/Y H:i', $backup['modified']); ?></td>
                                                    <td>
                                                        <a href="?download=<?php echo urlencode($backup['name']); ?>" 
                                                           class="btn btn-sm btn-outline-primary" title="Download">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus backup ini?')"><?= layananToken() ?><button class="btn btn-sm btn-danger" type="submit" name="delete" value="<?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Hapus backup"><i class="fas fa-trash"></i></button></form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-database fa-3x text-muted mb-3"></i>
                                        <p class="text-muted mb-0">Belum ada backup</p>
                                        <small class="text-muted">Buat backup pertama Anda sekarang</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmReset() {
            return confirm('⚠️ PERINGATAN!\n\nTindakan ini akan menghapus SEMUA data transaksi, simpanan, pinjaman, riwayat, dan WASERDA.\n\nPastikan Anda sudah membuat backup sebelum melanjutkan!\n\nApakah Anda yakin ingin melanjutkan?');
        }
        function confirmResetTotal() {
            return confirm('PERINGATAN KERAS!\n\nTransaksi dan master akan dihapus. Dipertahankan: Admin aktif, anggota+foto, uploads/, koperasi, TTD Admin.\n\nBuat Backup ZIP lengkap dulu. Lanjutkan?');
        }
        
        function confirmDelete(filename) {
            return confirm('Hapus backup ' + filename + '?\n\nTindakan ini tidak dapat dibatalkan.');
        }
    </script>
</body>
</html>
