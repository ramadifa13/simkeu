<?php
require_once '../includes/auth.php'; require_once '../config/config.php';
if (!isAdmin()) { http_response_code(403); die('Akses ditolak'); }
$id=(int)($_GET['id']??0); require '../includes/cetak_perjanjian.php';
