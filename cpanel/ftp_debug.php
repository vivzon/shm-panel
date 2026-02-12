<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client']) && !isset($_SESSION['admin'])) {
    die("Login first");
}

echo "<h1>FTP Debugger</h1>";

try {
    // 1. Describe Table
    echo "<h2>Table Structure</h2>";
    $stmt = $pdo->query("DESCRIBE ftp_users");
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        foreach ($row as $val)
            echo "<td>$val</td>";
        echo "</tr>";
    }
    echo "</table>";

    // 2. Dump Current Users (Safe Dump - No Passwords clearly)
    echo "<h2>Current Users</h2>";
    $stmt = $pdo->query("SELECT * FROM ftp_users");
    echo "<table border='1'><tr>";
    $cols = range(0, $stmt->columnCount() - 1);
    foreach ($cols as $col) {
        $meta = $stmt->getColumnMeta($col);
        echo "<th>" . $meta['name'] . "</th>";
    }
    echo "</tr>";

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        foreach ($row as $k => $val) {
            if ($k == 'passwd')
                echo "<td>(hashed)</td>";
            else
                echo "<td>$val</td>";
        }
        echo "</tr>";
    }
    echo "</table>";

    // 3. Test POSIX
    echo "<h2>User Check</h2>";
    if (isset($_SESSION['client'])) {
        $u = $_SESSION['client'];
        echo "Checking User: $u<br>";
        if (function_exists('posix_getpwnam')) {
            $i = posix_getpwnam($u);
            echo "POSIX Info: <pre>" . print_r($i, true) . "</pre>";
        } else {
            echo "POSIX function not available.";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
