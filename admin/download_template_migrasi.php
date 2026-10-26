<?php
require_once '../includes/auth.php';
require_once '../config/config.php';
if (!isAdmin()) { http_response_code(403); exit('Akses ditolak.'); }
$file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Template_Migrasi.xlsx';
if (!is_file($file)) { http_response_code(404); exit('Template belum tersedia.'); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Template_Migrasi.xlsx"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($file);
exit;
