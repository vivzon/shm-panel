<?php
/**
 * VIVZON WHM - Dashboard Router
 * Refactored to MVC
 */
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/controllers/AdminDashboardController.php';

// Authentication Check
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Instantiate Controller
$controller = new AdminDashboardController();

// Get Data
$data = $controller->index();

// Render View
require_once __DIR__ . '/views/dashboard.php';