<?php
require_once __DIR__ . '/../../shared/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['client'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Fallback for old calls
    if (empty($action) && isset($_POST['ajax_action'])) {
        $action = $_POST['ajax_action'];
    }

    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Security token mismatch. Please refresh.");
        }

        // Get Client Limits
        $stmt = $pdo->prepare("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = ?");
        $stmt->execute([$cid]);
        $limits = $stmt->fetch();

        // --- Action: Add Database ---
        if ($action == 'add_db') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_databases WHERE client_id = ?");
            $stmt->execute([$cid]);
            if ($stmt->fetchColumn() >= $limits['max_databases'])
                throw new Exception("Plan database limit reached.");

            $db_suffix = preg_replace('/[^a-z0-9_]/', '', $_POST['db_name']);
            $db_name = $username . "_" . $db_suffix;
            $domain_id = !empty($_POST['domain_id']) ? (int) $_POST['domain_id'] : null;

            $pdo->prepare("INSERT INTO client_databases (client_id, domain_id, db_name) VALUES (?, ?, ?)")->execute([$cid, $domain_id, $db_name]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name`");
            } else {
                cmd("mysql-tool create-db " . escapeshellarg($db_name));
            }
            echo json_encode($res);
            exit;
        }

        // --- Action: Delete Database ---
        if ($action == 'delete_db') {
            $db_name = $_POST['db_name'];
            $check = $pdo->prepare("SELECT id FROM client_databases WHERE db_name = ? AND client_id = ?");
            $check->execute([$db_name, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM client_databases WHERE db_name = ?")->execute([$db_name]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pdo->exec("DROP DATABASE IF EXISTS `$db_name`");
            } else {
                cmd("mysql-tool delete-db " . escapeshellarg($db_name));
            }
            echo json_encode($res);
            exit;
        }

        // --- Action: Add Database User ---
        if ($action == 'add_db_user') {
            $user_suffix = preg_replace('/[^a-z0-9_]/', '', $_POST['db_user']);
            $db_user = $username . "_" . $user_suffix;
            $pass = $_POST['db_pass'];
            $target_db = $_POST['target_db'];

            $pdo->prepare("INSERT INTO client_db_users (client_id, db_user) VALUES (?, ?)")->execute([$cid, $db_user]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $quoted_pass = $pdo->quote($pass);
                $pdo->exec("CREATE USER IF NOT EXISTS '$db_user'@'localhost' IDENTIFIED BY $quoted_pass");
                $pdo->exec("GRANT ALL PRIVILEGES ON `$target_db`.* TO '$db_user'@'localhost'");
                $pdo->exec("FLUSH PRIVILEGES");
            } else {
                cmd("mysql-tool create-user " . escapeshellarg($db_user) . " " . escapeshellarg($pass) . " " . escapeshellarg($target_db));
            }
            echo json_encode($res);
            exit;
        }

        // --- Action: Delete Database User ---
        if ($action == 'delete_db_user') {
            $db_user = $_POST['db_user'];
            $check = $pdo->prepare("SELECT id FROM client_db_users WHERE db_user = ? AND client_id = ?");
            $check->execute([$db_user, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM client_db_users WHERE db_user = ?")->execute([$db_user]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pdo->exec("DROP USER IF EXISTS '$db_user'@'localhost'");
            } else {
                cmd("mysql-tool delete-user " . escapeshellarg($db_user));
            }
            echo json_encode($res);
            exit;
        }

        // --- Action: Reset Password ---
        if ($action == 'reset_db_pass') {
            $db_user = $_POST['db_user'];
            $pass = $_POST['new_pass'];

            $check = $pdo->prepare("SELECT id FROM client_db_users WHERE db_user = ? AND client_id = ?");
            $check->execute([$db_user, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $quoted_pass = $pdo->quote($pass);
                $pdo->exec("ALTER USER '$db_user'@'localhost' IDENTIFIED BY $quoted_pass");
                $pdo->exec("FLUSH PRIVILEGES");
            } else {
                cmd("mysql-tool reset-pass " . escapeshellarg($db_user) . " " . escapeshellarg($pass));
            }
            echo json_encode($res);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}
