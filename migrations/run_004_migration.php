<?php
/**
 * SHM Panel - Database Migration Runner
 * Adds missing analytics tables (domain_traffic, malware_scans)
 */

require_once __DIR__ . '/../shared/config.php';

echo "=== SHM Panel Database Migration ===\n";
echo "Adding missing analytics tables...\n\n";

try {
    // Start transaction
    $pdo->beginTransaction();

    // 1. Create domain_traffic table
    echo "Creating domain_traffic table... ";
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
    echo "✓ Done\n";

    // 2. Create malware_scans table
    echo "Creating malware_scans table... ";
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
    echo "✓ Done\n";

    // 3. Insert sample traffic data for last 7 days
    echo "Inserting sample traffic data... ";
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
    echo "✓ Done\n";

    // 4. Insert initial malware scan status
    echo "Initializing malware scan status... ";
    $pdo->exec("
        INSERT IGNORE INTO malware_scans (domain_id, status, scanned_at)
        SELECT id, 'clean', NOW()
        FROM domains
        WHERE NOT EXISTS (
            SELECT 1 FROM malware_scans ms WHERE ms.domain_id = domains.id
        )
    ");
    echo "✓ Done\n";

    // Commit transaction
    $pdo->commit();

    // 5. Verify tables exist
    echo "\nVerifying tables...\n";
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE()
            AND table_name IN ('domain_traffic', 'malware_scans')
        ORDER BY table_name
    ");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        echo "  ✓ $table\n";
    }

    // 6. Show record counts
    echo "\nRecord counts:\n";
    $traffic_count = $pdo->query("SELECT COUNT(*) FROM domain_traffic")->fetchColumn();
    $scans_count = $pdo->query("SELECT COUNT(*) FROM malware_scans")->fetchColumn();
    echo "  - domain_traffic: $traffic_count records\n";
    echo "  - malware_scans: $scans_count records\n";

    echo "\n✅ Migration completed successfully!\n";
    echo "\nYou can now access the dashboard at: http://localhost/cpanel/\n";

} catch (PDOException $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "\nError details:\n";
    echo "  Code: " . $e->getCode() . "\n";
    echo "  File: " . $e->getFile() . "\n";
    echo "  Line: " . $e->getLine() . "\n";
    exit(1);
}
