<?php
require_once __DIR__ . '/../shared/config.php';
function show_create($table)
{
    global $pdo;
    try {
        $stmt = $pdo->query("SHOW CREATE TABLE $table");
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        print_r($res);
        $stmt = $pdo->query("DESCRIBE $table");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nColumns:\n";
        foreach ($cols as $c)
            echo $c['Field'] . " " . $c['Type'] . "\n";
    } catch (Exception $e) {
        echo "Error $table: " . $e->getMessage() . "\n";
    }
}
show_create('domains');
show_create('php_config');
show_create('dns_records');
