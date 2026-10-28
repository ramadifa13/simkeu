<?php
/** Jalankan dengan DB_NAME=simkeu_import_test php tests/migrasi_import_integration_test.php */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/migrasi_import_service.php';

$file = getenv('MIGRASI_TEST_FILE') ?: dirname(__DIR__) . '/Template_Migrasi_Final_Revisi.xlsx';
$before = (int)$db->query('SELECT COUNT(*) FROM migrasi_pembayaran')->fetchColumn();
$first = migrasiImportFinal($db, $file);
$afterFirst = (int)$db->query('SELECT COUNT(*) FROM migrasi_pembayaran')->fetchColumn();
$second = migrasiImportFinal($db, $file);
$afterSecond = (int)$db->query('SELECT COUNT(*) FROM migrasi_pembayaran')->fetchColumn();

if ($afterFirst < $before || $afterSecond !== $afterFirst) {
    throw new RuntimeException('Import ulang menggandakan pembayaran.');
}
if (!(int)$db->query('SELECT COUNT(*) FROM pinjaman')->fetchColumn() || !(int)$db->query('SELECT COUNT(*) FROM kas')->fetchColumn()) {
    throw new RuntimeException('Pinjaman atau kas tidak terbentuk.');
}
echo "OK pembayaran=$afterSecond import_pertama=" . $first['pembayaran'] . " import_ulang=" . $second['pembayaran'] . PHP_EOL;
