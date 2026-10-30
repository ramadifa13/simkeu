<?php
// Wrapper daftar lunas. Pengaturan harus lewat layanan.php (jangan paksa tab di sini).
if (($_GET['tab'] ?? '') === 'pengaturan') {
    header('Location: layanan.php?tab=pengaturan');
    exit;
}
$_GET['tab'] = 'lunas';
require __DIR__ . '/../includes/layanan_page.php';
