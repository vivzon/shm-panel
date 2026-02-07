<?php
/**
 * VIVZON CPANEL - Dashboard Router
 * Refactored to MVC
 */
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/controllers/DashboardController.php';

// Authentication Check
if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}

// Instantiate Controller
$controller = new DashboardController($pdo, $_SESSION['cid'], $_SESSION['client']);

// Get Data
$data = $controller->index();

// Render View
require_once __DIR__ . '/views/dashboard.php';