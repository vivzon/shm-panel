<?php
require_once __DIR__ . '/../../shared/config.php';

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $service = $_POST['service'] ?? '';

    // Validate service (whitelisting)
    if (!in_array($service, ['nginx', 'php', 'mysql'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Invalid service']);
        exit;
    }

    if ($action === 'status') {
        // cmd is a helper from config.php that calls shm-manage via sudo
        $output = cmd("service-control status " . escapeshellarg($service));

        // Systemctl returns "active" if running
        if (trim($output) === 'active') {
            echo json_encode(['status' => 'active']);
        } else {
            echo json_encode(['status' => 'inactive']);
        }
        exit;
    }

    if ($action === 'restart') {
        $output = cmd("service-control restart " . escapeshellarg($service));
        echo json_encode(['status' => 'success', 'msg' => $output]);
        exit;
    }
}
?>