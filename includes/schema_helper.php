<?php
/**
 * Pastikan tabel opsional ada (idempotent, di-cache per request).
 * DDL idealnya lewat database/migrations; helper ini hanya jaring pengaman runtime.
 */
if (!function_exists('schemaPastikanTabel')) {
    function schemaPastikanTabel(PDO $db, string $nama, string $ddl): bool
    {
        static $sudah = [];
        $key = strtolower($nama);
        if (isset($sudah[$key])) {
            return $sudah[$key];
        }
        try {
            $q = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $q->execute([$nama]);
            if ((int)$q->fetchColumn() > 0) {
                return $sudah[$key] = true;
            }
            $db->exec($ddl);
            return $sudah[$key] = true;
        } catch (Throwable $e) {
            error_log('schemaPastikanTabel(' . $nama . '): ' . $e->getMessage());
            return $sudah[$key] = false;
        }
    }
}

if (!function_exists('schemaPastikanWaserda')) {
    function schemaPastikanWaserda(PDO $db): bool
    {
        return schemaPastikanTabel($db, 'waserda_transaksi',
            "CREATE TABLE IF NOT EXISTS waserda_transaksi (
                id INT PRIMARY KEY AUTO_INCREMENT,
                kode_transaksi VARCHAR(20) UNIQUE NOT NULL,
                anggota_id INT NOT NULL,
                items TEXT NOT NULL,
                total_bayar DECIMAL(15,2) NOT NULL,
                metode_bayar ENUM('tunai', 'transfer') NOT NULL,
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (anggota_id) REFERENCES anggota(id),
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
        );
    }
}

if (!function_exists('schemaPastikanLogKoreksi')) {
    function schemaPastikanLogKoreksi(PDO $db): bool
    {
        return schemaPastikanTabel($db, 'log_koreksi',
            "CREATE TABLE IF NOT EXISTS log_koreksi (
                id INT PRIMARY KEY AUTO_INCREMENT,
                transaksi_id INT NOT NULL,
                jumlah_lama DECIMAL(15,2) NOT NULL,
                jumlah_baru DECIMAL(15,2) NOT NULL,
                selisih DECIMAL(15,2) NOT NULL,
                keterangan TEXT,
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (transaksi_id) REFERENCES transaksi(id),
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
        );
    }
}
