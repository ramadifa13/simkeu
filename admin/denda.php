<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/auth.php';
if (!isLoggedIn()) { header('Location: ../login.php'); exit; }
if (!isAdmin() && !isTeller()) { http_response_code(403); exit('Akses ditolak.'); }
header('Location: layanan.php?tab=denda');
exit;
