<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isTeller()) {
    header('Location: ../dashboard.php');
    exit();
}

$label_petugas = 'TELLER';
require __DIR__ . '/../includes/slip_pencairan.php';
