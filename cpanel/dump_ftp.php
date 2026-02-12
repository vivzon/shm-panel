<?php
require_once __DIR__ . '/../shared/config.php';

try {
    echo "--- TABLE STRUCTURE ---\n";
    $stmt = $pdo->query("DESCRIBE ftp_users");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " | " . $row['Type'] . "\n";
    }

    echo "\n--- USERS ---\n";
    $stmt = $pdo->query("SELECT * FROM ftp_users");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
