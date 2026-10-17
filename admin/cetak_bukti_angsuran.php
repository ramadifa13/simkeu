<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

require __DIR__ . '/../includes/bukti_angsuran.php';
