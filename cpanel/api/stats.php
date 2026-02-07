<?php
require_once __DIR__ . '/../../shared/config.php';

// Authentication Check
if (!isset($_SESSION['client'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$username = $_SESSION['client'];

// CSRF Check
if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'CSRF mismatch']);
    exit;
}

if (isset($_POST['ajax_action'])) {
    if ($_POST['ajax_action'] == 'clear_logs') {
        cmd("clear-client-logs " . escapeshellarg($username));
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['ajax_action'] == 'get_logs') {
        $logs = cmd("get-client-logs " . escapeshellarg($username));
        echo htmlspecialchars($logs);
        exit;
    }
}
?>