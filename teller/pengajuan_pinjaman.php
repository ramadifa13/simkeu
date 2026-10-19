<?php
require_once __DIR__.'/../includes/auth.php';
if (!isTeller()) { http_response_code(403); exit('Akses ditolak.'); }
header('Location: pengajuan.php');exit;
