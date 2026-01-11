<?php
/**
 * SHM PANEL - SHARED CONFIGURATION (v5.0)
 * =======================================
 * Robust configuration loader supporting local environments.
 */

// 1. Load Local Configuration (if exists)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// 2. Default Configuration (Fallback)
if (!isset($db_host)) $db_host = 'localhost';
if (!isset($db_name)) $db_name = 'shm_panel';
if (!isset($db_user)) $db_user = 'shm_admin';
if (!isset($db_pass)) $db_pass = 'SHMPanel_Secure_Pass_2025';

// 3. Database Connection
try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If we are not already in the installer, redirect to it
    if (!defined('INSTALLER_RUNNING')) {
        // Simple relative redirect check
        if (file_exists(__DIR__ . '/../install.php')) {
            header("Location: ../install.php?error=db_connect");
            exit;
        }
        die("<div style='font-family:sans-serif;background:#fee;color:#c00;padding:20px;border-radius:10px;border:1px solid #eba;'>
                <b>SHM Panel System Error</b><br>
                Database connection failed. Please run the installer or check config.<br>
                <small>" . $e->getMessage() . "</small>
             </div>");
    } else {
        // Re-throw for installer to handle
        throw $e;
    }
}

/**
 * Shell Command Bridge
 * Safe wrapper for executing system commands.
 */
function cmd($command)
{
    // Windows Safety Check
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Mock responses for development
        if (strpos($command, 'list_ssh') !== false) return "mock-key-rsa AAAA...";
        if (strpos($command, 'list_backups') !== false) return "1024K Jan 01 12:00 backup_test.tar.gz";
        return "Command '$command' simulated on Windows.";
    }

    // Production Linux Execution
    $output = shell_exec("sudo /usr/local/bin/shm-manage " . $command);
    return trim($output);
}

// Helper: JSON Response
if (!function_exists('sendResponse')) {
    function sendResponse($data)
    {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

// Session Management (SSO)
if (session_status() === PHP_SESSION_NONE) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!filter_var($host, FILTER_VALIDATE_IP) && $host !== 'localhost') {
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            $base_domain = '.' . implode('.', array_slice($parts, -2));
            // Only set if cookie params allow
            // session_set_cookie_params(0, '/', $base_domain);
        }
    }
    session_start();
}
?>
