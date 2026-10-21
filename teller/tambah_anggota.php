<?php
/**
 * Kompatibilitas URL lama.
 *
 * Form tambah anggota sudah disatukan ke anggota.php agar Teller hanya memakai
 * satu alur validasi dan penyimpanan. Parameter tambah membuka modal otomatis.
 */
require_once '../includes/auth.php';

checkTeller();
header('Location: anggota.php?tambah=1', true, 302);
exit();
