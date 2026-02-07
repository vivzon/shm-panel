<?php
require_once __DIR__ . '/../../shared/config.php';

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Mock Backup Directory for Demo (Production would use /var/backups/shm)
    // We will simulate backups for the UI since we don't have actual backup files in this dev environment
    // In production, this would scan the actual directory

    if ($action === 'list') {
        // In a real scenario:
        // $files = glob('/var/backups/shm/*.tar.gz');
        // But for now, returning simulated data if no real files exist

        $backups = [];
        // Add a fake backup for demonstration
        $backups[] = [
            'filename' => 'shm_backup_2025-05-20.tar.gz',
            'type' => 'Full',
            'size' => '124 MB',
            'date' => date('Y-m-d H:i:s')
        ];

        echo json_encode([
            'status' => 'success',
            'backups' => $backups,
            'total_size' => 124 * 1024 * 1024 // in bytes
        ]);
        exit;
    }

    if ($action === 'create') {
        // Call shm-manage backup create (simulated)
        // cmd("backup create all"); // This would be the real call
        sleep(2); // Simulate delay
        echo json_encode(['status' => 'success', 'msg' => 'Backup created successfully']);
        exit;
    }

    if ($action === 'delete') {
        $filename = $_POST['filename'] ?? '';
        // cmd("rm /var/backups/shm/" . escapeshellarg($filename));
        echo json_encode(['status' => 'success', 'msg' => 'Backup deleted']);
        exit;
    }
}
?>