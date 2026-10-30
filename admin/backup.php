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

/**
 * Seed jenis simpanan sistem yang wajib ada setelah wipe master.
 * ANG dipakai pembayaran angsuran; BONUS/SHU untuk jurnal khusus.
 */
function backupSeedJenisSimpananSistem(PDO $db): void
{
    $seed = [
        ['ANG', 'Pembayaran Angsuran', 'Jurnal pembayaran angsuran pinjaman'],
        ['BONUS', 'Bonus', 'Jurnal bonus anggota'],
        ['SHU', 'Pembagian SHU', 'Jurnal pembagian SHU'],
    ];
    $cek = $db->prepare('SELECT id FROM jenis_simpanan WHERE kode_jenis = ? LIMIT 1');
    $ins = $db->prepare(
        "INSERT INTO jenis_simpanan (kode_jenis, nama_jenis, deskripsi, minimum, maksimum, bagi_hasil, status)
         VALUES (?, ?, ?, 0, 0, 0, 'aktif')"
    );
    $aktif = $db->prepare("UPDATE jenis_simpanan SET status = 'aktif' WHERE id = ?");
    foreach ($seed as [$kode, $nama, $deskripsi]) {
        $cek->execute([$kode]);
        $id = $cek->fetchColumn();
        if ($id) {
            $aktif->execute([(int) $id]);
            continue;
        }
        $ins->execute([$kode, $nama, $deskripsi]);
    }
}

// Reset total untuk migrasi: semua data/master/pengaturan dikosongkan,
// kecuali akun Admin yang sedang dipakai agar aplikasi tidak terkunci.
if ($_POST && isset($_POST['reset_total_migrasi'])) {
    if (trim((string) ($_POST['confirmation_total'] ?? '')) !== 'RESET TOTAL') {
        $_SESSION['error'] = "Konfirmasi tidak valid! Ketik 'RESET TOTAL'.";
    } else {
        try {
            layananValidasiToken();
            $adminId = (int) ($_SESSION['user_id'] ?? 0);
            if ($adminId <= 0) {
                throw new RuntimeException('Admin aktif tidak ditemukan.');
            }
            $cekAdmin = $db->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND status='aktif'");
            $cekAdmin->execute([$adminId]);
            if (!$cekAdmin->fetchColumn()) {
                throw new RuntimeException('Akun Admin aktif wajib tersedia untuk reset total.');
            }

            $backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups';
            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                throw new RuntimeException('Folder backups/ tidak dapat dibuat. Reset dibatalkan.');
            }
            if (!is_writable($backupDir)) {
                throw new RuntimeException('Folder backups/ tidak dapat ditulis. Reset dibatalkan.');
            }

            // Wajib sukses dulu: bila ZIP gagal, jangan hapus data.
            $autoZipName = 'auto_pre_reset_' . date('Y-m-d_H-i-s') . '.zip';
            $autoZip = migrasiExportBackupZip($db, $backupDir, null, $autoZipName);

            // Cadangan mapping dokumen (juga sudah dijalankan di dalam ZIP; ulang aman/idempoten).
            $cadangan = migrasiSimpanCadanganDokumen($db);

            $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            // Pertahankan identitas koperasi, TTD Admin, meta dokumen, dan cadangan foto/TTD.
            // File uploads/ tidak dihapus; anggota dipertahankan agar path foto cocok saat import kode sama.
            $skip = ['migrations', 'users', 'anggota', 'koperasi', 'pengaturan_perjanjian', 'migrasi_anggota_dokumen', 'migrasi_dokumen_cadangan'];
            $target = array_values(array_filter($tables, static function ($table) use ($skip) {
                return !in_array($table, $skip, true);
            }));

            $db->beginTransaction();
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($target as $table) {
                $db->exec('DELETE FROM `' . str_replace('`', '``', $table) . '`');
            }
            // Pertahankan Admin yang login + seluruh akun Teller.
            $hapusUser = $db->prepare("DELETE FROM users WHERE role='anggota' OR (role='admin' AND id<>?)");
            $hapusUser->execute([$adminId]);
            $db->exec("UPDATE anggota SET user_id = NULL, status = 'nonaktif'");
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
            $db->commit();

            foreach ($target as $table) {
                $db->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` AUTO_INCREMENT=1');
            }

            backupSeedJenisSimpananSistem($db);
            if (function_exists('layananPastikanPengaturan')) {
                layananPastikanPengaturan($db);
            }

            $_SESSION['success'] = 'Reset total selesai. Auto-backup: ' . $autoZip['name']
                . ' (Excel + ' . (int) $autoZip['files'] . ' file uploads). '
                . 'Akun Admin & Teller tetap ada. Cadangan dokumen: '
                . (int) $cadangan['anggota'] . ' nasabah, ' . (int) $cadangan['pinjaman'] . ' pinjaman. '
                . 'Jenis sistem ANG/BONUS/SHU sudah di-seed. Langkah berikutnya: Import Migrasi (unggah Excel; Master_Simpanan wajib berisi ANG). '
                . 'Folder uploads/ tidak dihapus.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            try {
                $db->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $ignored) {
            }
            $_SESSION['error'] = 'Gagal melakukan reset total: ' . $e->getMessage();
        }
    }
    header('Location: backup.php');
    exit();
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
$total_size = 0;
foreach ($backups as $b) {
    $total_size += $b['size'];
}
$countSql = count(array_filter($backups, static function ($b) { return ($b['type'] ?? '') === 'sql'; }));
$countXlsx = count(array_filter($backups, static function ($b) { return ($b['type'] ?? '') === 'xlsx'; }));
$countZip = count(array_filter($backups, static function ($b) { return ($b['type'] ?? '') === 'zip'; }));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup &amp; Reset - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-backup" aria-hidden="true"><i class="ti ti-database-export"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Pengaturan</p>
                        <h1 class="ui-dash-title">Backup &amp; Reset</h1>
                        <p class="ui-dash-meta mb-0">Cadangkan data dan siapkan migrasi</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="import_migrasi.php" class="btn btn-sm ui-set-btn-ghost is-import">
                            <i class="ti ti-file-import me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Import migrasi</span>
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-set-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Ukuran DB', 'value' => ($db_size['size_mb'] ?? '0') . ' MB', 'tone' => 'primary', 'icon' => 'ti ti-database', 'hint' => htmlspecialchars((string)$db_name)],
                    ['label' => 'File backup', 'value' => count($backups), 'tone' => 'info', 'icon' => 'ti ti-files', 'hint' => round($total_size / 1048576, 2) . ' MB total'],
                    ['label' => 'SQL / Excel / ZIP', 'value' => $countSql . ' / ' . $countXlsx . ' / ' . $countZip, 'tone' => 'success', 'icon' => 'ti ti-file-stack', 'hint' => 'Folder backups/'],
                    ['label' => 'Server', 'value' => htmlspecialchars((string)$db_host), 'tone' => 'warning', 'icon' => 'ti ti-server', 'hint' => 'Host database'],
                ]); ?>
                </div>

                <div class="ui-set-split mb-3">
                    <div class="card ui-set-card">
                        <div class="card-header ui-dash-sec">
                            <span class="ui-dash-sec-title"><i class="ti ti-download" aria-hidden="true"></i>Buat cadangan</span>
                            <span class="ui-dash-sec-note">SQL · Excel · ZIP</span>
                        </div>
                        <div class="card-body">
                            <div class="ui-backup-actions">
                                <form method="POST" class="ui-backup-action">
                                    <?php echo layananToken(); ?>
                                    <div class="ui-backup-action-icon is-sql"><i class="ti ti-file-database" aria-hidden="true"></i></div>
                                    <div>
                                        <strong>Backup SQL</strong>
                                        <p>Struktur + seluruh data (PHP native, tanpa mysqldump)</p>
                                    </div>
                                    <button type="submit" name="backup_database" class="btn btn-success btn-sm">Buat SQL</button>
                                </form>
                                <form method="POST" class="ui-backup-action">
                                    <?php echo layananToken(); ?>
                                    <div class="ui-backup-action-icon is-xlsx"><i class="ti ti-file-spreadsheet" aria-hidden="true"></i></div>
                                    <div>
                                        <strong>Backup Excel migrasi</strong>
                                        <p>Nasabah, pinjaman, angsuran, master — siap di-import ulang</p>
                                    </div>
                                    <button type="submit" name="backup_excel_migrasi" class="btn btn-outline-success btn-sm">Buat Excel</button>
                                </form>
                                <form method="POST" class="ui-backup-action">
                                    <?php echo layananToken(); ?>
                                    <div class="ui-backup-action-icon is-zip"><i class="ti ti-archive" aria-hidden="true"></i></div>
                                    <div>
                                        <strong>Backup ZIP lengkap</strong>
                                        <p>Excel + folder <code>uploads/</code> — disarankan pindah hosting</p>
                                    </div>
                                    <button type="submit" name="backup_zip_lengkap" class="btn btn-primary btn-sm">Buat ZIP</button>
                                </form>
                            </div>
                            <p class="ui-set-hint mb-0 mt-3">
                                <i class="ti ti-info-circle" aria-hidden="true"></i>
                                File disimpan di <code>backups/</code>. Import Excel lewat menu Import Migrasi.
                            </p>
                        </div>
                    </div>

                    <div class="card ui-set-card ui-set-danger">
                        <div class="card-header ui-dash-sec">
                            <span class="ui-dash-sec-title text-danger"><i class="ti ti-alert-octagon" aria-hidden="true"></i>Reset total migrasi</span>
                            <span class="ui-dash-sec-note">Berbahaya</span>
                        </div>
                        <div class="card-body">
                            <ol class="small text-muted mb-3 ps-3">
                                <li class="mb-1"><strong>ZIP otomatis</strong> dibuat dulu ke <code>backups/auto_pre_reset_*.zip</code>. Jika ZIP gagal, reset dibatalkan.</li>
                                <li class="mb-1">Ketik <strong>RESET TOTAL</strong> lalu konfirmasi. Transaksi &amp; master dihapus; Admin/Teller, anggota+foto, <code>uploads/</code>, koperasi, TTD Admin tetap.</li>
                                <li class="mb-0">Lanjut ke <a href="import_migrasi.php">Import Migrasi</a>. Sheet <code>Master_Simpanan</code> wajib berisi kode <strong>ANG</strong>.</li>
                            </ol>
                            <form method="POST" id="resetTotalForm"><?php echo layananToken(); ?>
                                <label class="form-label text-danger" for="confirm_total">Ketik <strong>RESET TOTAL</strong></label>
                                <input type="text" id="confirm_total" name="confirmation_total" class="form-control mb-3" placeholder="RESET TOTAL" required pattern="RESET TOTAL" autocomplete="off">
                                <button type="submit" name="reset_total_migrasi" class="btn btn-outline-danger" onclick="return confirmResetTotal()">
                                    <i class="ti ti-skull me-1" aria-hidden="true"></i>Reset total untuk migrasi
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card ui-set-card">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-history" aria-hidden="true"></i>Backup tersimpan</span>
                        <span class="ui-dash-sec-note"><?php echo count($backups); ?> file</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($backups)): ?>
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="2,desc"
                                   data-dt-filter="1"
                                   data-empty-message="Belum ada backup.">
                                <thead>
                                    <tr>
                                        <th>Nama file</th>
                                        <th>Tipe</th>
                                        <th>Tanggal</th>
                                        <th class="text-end">Ukuran</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $backup):
                                        $type = strtolower((string)($backup['type'] ?? 'sql'));
                                        $icon = $type === 'xlsx' ? 'ti-file-spreadsheet text-success' : ($type === 'zip' ? 'ti-archive text-primary' : 'ti-file-database text-muted');
                                    ?>
                                    <tr>
                                        <td>
                                            <i class="ti <?php echo $icon; ?> me-1" aria-hidden="true"></i>
                                            <code class="ui-set-kode"><?php echo htmlspecialchars($backup['name']); ?></code>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars(strtoupper($type)); ?>">
                                            <span class="ui-backup-type is-<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars(strtoupper($type)); ?></span>
                                        </td>
                                        <td data-order="<?php echo (int)$backup['modified']; ?>"><?php echo date('d/m/Y H:i', $backup['modified']); ?></td>
                                        <td class="text-end"><?php echo $backup['size'] >= 1048576 ? round($backup['size'] / 1048576, 2) . ' MB' : round($backup['size'] / 1024, 1) . ' KB'; ?></td>
                                        <td>
                                            <a href="?download=<?php echo urlencode($backup['name']); ?>" class="btn btn-sm btn-outline-primary" title="Download"><i class="ti ti-download"></i></a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus backup ini?')">
                                                <?= layananToken() ?>
                                                <button class="btn btn-sm btn-outline-danger" type="submit" name="delete" value="<?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Hapus backup"><i class="ti ti-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="ui-set-empty">
                            <i class="ti ti-database-off" aria-hidden="true"></i>
                            <strong>Belum ada backup</strong>
                            <span>Buat cadangan SQL, Excel, atau ZIP di atas</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
    <script>
        function confirmResetTotal() {
            return confirm('PERINGATAN KERAS!\n\n1) Backup ZIP otomatis dibuat dulu (auto_pre_reset_*.zip). Jika ZIP gagal, reset dibatalkan.\n2) Transaksi & master dihapus. Dipertahankan: Admin/Teller, anggota+foto, uploads/, koperasi, TTD Admin.\n3) Setelah reset: Import Migrasi (Master_Simpanan wajib ANG).\n\nLanjutkan?');
        }
    </script>
</body>
</html>

