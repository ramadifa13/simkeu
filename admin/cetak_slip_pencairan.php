<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isAdmin() && !isTeller()) {
    header('Location: ../dashboard.php');
    exit();
}

$label_petugas = isAdmin() ? 'PETUGAS' : 'TELLER';
require __DIR__ . '/../includes/slip_pencairan.php';
