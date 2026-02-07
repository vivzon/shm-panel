<?php
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/controllers/BackupController.php';

// Authentication Check
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$controller = new BackupController();
$data = $controller->index();

require_once __DIR__ . '/views/backups.php';
