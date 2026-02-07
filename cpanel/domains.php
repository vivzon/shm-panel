<?php
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/controllers/DomainController.php';

// Authentication Check handled in Controller constructor, but double check here for safety if needed
if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}

$controller = new DomainController();
$data = $controller->index();

// Extract data for view
// The view expects variables like $domains, $total_pages etc.
// But we returned them in an array $data
// We can extract them or use $data['key'] in view.
// The view I wrote uses $data['key'], so we just pass $data.

require_once __DIR__ . '/views/domains.php';