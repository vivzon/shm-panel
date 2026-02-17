<?php
/**
 * SHM Panel - Migration API
 * Web-accessible migration runner
 */

header('Content-Type: application/json');

// Allow from any origin (for development)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/../shared/config.php';

$response = [
    'success' => false,
    'message' => '',
    'details' => '',
    'error' => ''
];

try {
    // Start transaction
    $pdo->beginTransaction();

    $steps = [];

    // 1. Create domain_traffic table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS domain_traffic (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            date DATE NOT NULL,
            hits INT DEFAULT 0,
            bytes_sent BIGINT DEFAULT 0,
            unique_visitors INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_domain_id (domain_id),
            INDEX idx_date (date),
            UNIQUE KEY unique_domain_date (domain_id, date),
            FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $steps[] = "✓ Created domain_traffic table";

    // 2. Create malware_scans table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS malware_scans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            status ENUM('clean', 'infected', 'running', 'failed') DEFAULT 'clean',
            threats_found INT DEFAULT 0,
            files_scanned INT DEFAULT 0,
            scan_duration INT DEFAULT 0,
            scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            details TEXT,
            INDEX idx_domain_id (domain_id),
            INDEX idx_status (status),
            INDEX idx_scanned_at (scanned_at),
            FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $steps[] = "✓ Created malware_scans table";

    // 3. Insert sample traffic data
    $pdo->exec("
        INSERT IGNORE INTO domain_traffic (domain_id, date, hits, bytes_sent, unique_visitors)
        SELECT 
            id as domain_id,
            DATE(NOW() - INTERVAL n DAY) as date,
            FLOOR(RAND() * 1000) as hits,
            FLOOR(RAND() * 10000000) as bytes_sent,
            FLOOR(RAND() * 500) as unique_visitors
        FROM domains
        CROSS JOIN (
            SELECT 0 as n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 
            UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
        ) days
        WHERE NOT EXISTS (
            SELECT 1 FROM domain_traffic dt 
            WHERE dt.domain_id = domains.id 
            AND dt.date = DATE(NOW() - INTERVAL n DAY)
        )
    ");
    $traffic_count = $pdo->query("SELECT COUNT(*) FROM domain_traffic")->fetchColumn();
    $steps[] = "✓ Inserted sample traffic data ($traffic_count records)";

    // 4. Insert malware scan status
    $pdo->exec("
        INSERT IGNORE INTO malware_scans (domain_id, status, scanned_at)
        SELECT id, 'clean', NOW()
        FROM domains
        WHERE NOT EXISTS (
            SELECT 1 FROM malware_scans ms WHERE ms.domain_id = domains.id
        )
    ");
    $scans_count = $pdo->query("SELECT COUNT(*) FROM malware_scans")->fetchColumn();
    $steps[] = "✓ Initialized malware scan status ($scans_count domains)";

    // Commit transaction
    $pdo->commit();

    $response['success'] = true;
    $response['message'] = 'Migration completed successfully! The dashboard should now work correctly.';
    $response['details'] = implode("\n", $steps);

} catch (PDOException $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $response['success'] = false;
    $response['message'] = 'Migration failed. Please check the error details below.';
    $response['error'] = $e->getMessage() . "\n\nCode: " . $e->getCode() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine();
}

echo json_encode($response, JSON_PRETTY_PRINT);
