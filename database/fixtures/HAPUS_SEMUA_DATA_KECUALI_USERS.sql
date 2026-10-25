-- PERINGATAN: skrip ini menghapus seluruh isi tabel dalam database aktif,
-- kecuali tabel `users` (akun/login). Struktur tabel tidak dihapus.
-- Jalankan setelah membuat backup/export database di phpMyAdmin.

DELIMITER $$

DROP PROCEDURE IF EXISTS hapus_data_kecuali_users$$

CREATE PROCEDURE hapus_data_kecuali_users()
BEGIN
    DECLARE selesai BOOLEAN DEFAULT FALSE;
    DECLARE nama_tabel VARCHAR(255);
    DECLARE daftar_tabel CURSOR FOR
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_type = 'BASE TABLE'
          AND table_name <> 'users';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET selesai = TRUE;

    SET FOREIGN_KEY_CHECKS = 0;
    OPEN daftar_tabel;

    hapus_per_tabel: LOOP
        FETCH daftar_tabel INTO nama_tabel;
        IF selesai THEN
            LEAVE hapus_per_tabel;
        END IF;

        SET @sql_hapus = CONCAT('DELETE FROM `', REPLACE(nama_tabel, '`', '``'), '`');
        PREPARE perintah_hapus FROM @sql_hapus;
        EXECUTE perintah_hapus;
        DEALLOCATE PREPARE perintah_hapus;
    END LOOP;

    CLOSE daftar_tabel;
    SET FOREIGN_KEY_CHECKS = 1;
END$$

CALL hapus_data_kecuali_users()$$
DROP PROCEDURE hapus_data_kecuali_users$$

DELIMITER ;
