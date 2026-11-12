<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/auth.php';
if (!isLoggedIn()) { header('Location: ../login.php'); exit; }
if (!isAdmin() && !isTeller()) { http_response_code(403); exit('Akses ditolak.'); }
$_GET['tab'] = 'denda';
require __DIR__.'/../includes/layanan_page.php';
