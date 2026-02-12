<?php
require_once __DIR__ . '/../shared/config.php';
try {
    $stmt = $pdo->query("DESCRIBE dns_records");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . " " . $col['Type'] . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
