<?php
/**
 * Security Migration Script
 * Handles database updates for 2FA and Rate Limiting
 */
require_once __DIR__ . '/shared/config.php';

echo "Starting Security Migration...\n";

try {
    // 1. Add 2FA Secret Column to Clients
    echo "1. Checking 'clients' table for 'two_fa_secret' column...\n";
    $colExists = false;
    $cols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('two_fa_secret', $cols)) {
        echo "   - Column 'two_fa_secret' already exists. Skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE clients ADD COLUMN two_fa_secret VARCHAR(255) NULL AFTER password");
        echo "   - Added 'two_fa_secret' column.\n";
    }

    // 2. Create Login Attempts Table
    echo "2. Creating 'login_attempts' table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            attempts INT DEFAULT 1,
            last_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            blocked_until TIMESTAMP NULL,
            INDEX idx_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "   - 'login_attempts' table ready.\n";

    echo "\nMigration Completed Successfully! ✅\n";
    echo "You can now delete this file.\n";

} catch (PDOException $e) {
    echo "\n❌ Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
