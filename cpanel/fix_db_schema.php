<?php
require_once __DIR__ . '/../shared/config.php';

try {
    echo "Checking schema for client_databases...\n";
    // Check if column exists to avoid error if script is run multiple times (though IF NOT EXISTS handles it in some SQL versions, explicitly checking is safer for PDO error handling sometimes)
    // Actually, ALTER TABLE ... ADD COLUMN IF NOT EXISTS is MariaDB 10.2+ / MySQL 8.0+.
    // Let's assume modern DB.
    
    $pdo->exec("ALTER TABLE client_databases ADD COLUMN IF NOT EXISTS domain_id INT DEFAULT NULL");
    echo "Success: Column 'domain_id' added to 'client_databases'.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    // Fallback for older MySQL that might not support IF NOT EXISTS in ALTER
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
         echo "Column already exists (verified via error).\n";
    }
}
?>
