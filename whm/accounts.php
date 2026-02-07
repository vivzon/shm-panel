<?php
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/controllers/AccountController.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$controller = new AccountController();
$data = $controller->index();

require_once __DIR__ . '/views/accounts.php';