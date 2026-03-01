<?php
/**
 * One-time script to update packages in DB
 */
require_once __DIR__ . '/shared/config.php';

try {
    $pdo->query("TRUNCATE TABLE packages;");
    $pdo->query("
        INSERT INTO packages (id, name, price, disk_mb, max_domains, max_emails, max_databases, features) VALUES 
        (1, 'Basic Plan', 49.00, 1000, 1, 2, 1, 'Standard Speed, Beginner Friendly'),
        (2, 'Smart Plan', 149.00, 5000, 3, 10, 5, 'Faster Performance, Priority Support'),
        (3, 'Pro Plan', 249.00, 15000, 10, 25, 10, 'Free Backup, High Performance, Developer Friendly'),
        (4, 'Agency Plan', 399.00, 40000, 9999, 100, 9999, 'Free Backup, Priority Resources, 24/7 Support');
    ");
    echo "Packages updated successfully.\n";
} catch (Exception $e) {
    echo "Error updating packages: " . $e->getMessage() . "\n";
}
