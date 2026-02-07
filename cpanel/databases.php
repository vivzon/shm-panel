<?php
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/controllers/DatabaseController.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}

$controller = new DatabaseController();
$data = $controller->index();

require_once __DIR__ . '/views/databases.php';