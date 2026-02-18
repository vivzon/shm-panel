<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


/**
 * SHM PANEL - SHARED CONFIGURATION (v5.0)
 * =======================================
 * Robust configuration loader supporting local environments.
 */

// Load security helper functions
require_once __DIR__ . '/security_helpers.php';

// 1. Load Local Configuration (if exists)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// 2. Default Configuration (Fallback)
if (!isset($db_host))
    $db_host = 'localhost';
if (!isset($db_name))
    $db_name = 'shm_panel';
if (!isset($db_user))
    $db_user = 'shm_admin';
if (!isset($db_pass))
    $db_pass = 'QwErTyUiOp@1';

// 3. Database Connection
try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log the error for debugging
    error_log("SHM Panel DB Connection Error: " . $e->getMessage());
    error_log("DB Config: host=$db_host, dbname=$db_name, user=$db_user");

    // If we are not already in the installer, redirect to it
    if (!defined('INSTALLER_RUNNING')) {
        // Simple relative redirect check
        if (file_exists(__DIR__ . '/../install.php')) {
            header("Location: ../install.php?error=db_connect");
            exit;
        }

        // Detailed error message for debugging
        $error_details = htmlspecialchars($e->getMessage());
        $error_code = $e->getCode();

        die("<div style='font-family:sans-serif;background:#fee;color:#c00;padding:20px;border-radius:10px;border:1px solid #eba;max-width:800px;margin:50px auto;'>
                <b style='font-size:18px;'>🔴 SHM Panel Database Error</b><br><br>
                <strong>Connection Failed:</strong> Unable to connect to the database.<br><br>
                <strong>Error Code:</strong> $error_code<br>
                <strong>Error Message:</strong> $error_details<br><br>
                <strong>Troubleshooting Steps:</strong><br>
                <ol style='margin:10px 0;padding-left:20px;'>
                    <li>Verify MySQL/MariaDB is running</li>
                    <li>Check database credentials in <code>shared/config.php</code> or <code>shared/config.local.php</code></li>
                    <li>Ensure database '$db_name' exists</li>
                    <li>Verify user '$db_user' has access to the database</li>
                    <li>Run the installer: <a href='../install.php' style='color:#00c;'>install.php</a></li>
                </ol>
                <small style='color:#666;'>Check your PHP error log for more details.</small>
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
        if (strpos($command, 'list_ssh') !== false)
            return "mock-key-rsa AAAA...";
        if (strpos($command, 'list_backups') !== false)
            return "1024K Jan 01 12:00 backup_test.tar.gz";
        return "Command '$command' simulated on Windows.";
    }

    // Production Linux Execution
    $output = shell_exec("sudo /usr/local/bin/shm-manage " . $command);
    return trim($output ?? '');
}

// Helper: JSON Response
if (!function_exists('sendResponse')) {
    function sendResponse($data)
    {
        if (!headers_sent())
            header('Content-Type: application/json');
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
            // Support for domains like vivzon.in or vivzon.cloud -> .vivzon.cloud
            $base_domain = '.' . implode('.', array_slice($parts, -2));
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => $base_domain,
                'secure' => true,
                'httponly' => true
            ]);
        }
    }
    session_start();
}

/**
 * Branding Helper
 * Automatically derives branding from the domain name if not explicitly set.
 */
if (!function_exists('get_branding')) {
    function get_branding()
    {
        global $brand_name;
        if (isset($brand_name)) {
            return $brand_name;
        }

        // Default branding
        $brand = "SHM Provider";

        if (isset($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];

            // If it's an IP address, use generic
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                return "SHM Panel";
            }

            // Extract domain parts
            $parts = explode('.', $host);

            // Handle subdomains like panel.example.com -> Example
            // or vivzon.cloud -> Vivzon

            // If we have at least 2 parts (domain.com)
            if (count($parts) >= 2) {
                // If it's a subdomain (e.g. panel.domain.com), usually the main domain is the brand
                // But simplification: take the second to last part (SLD)
                // e.g. vivzon.cloud -> vivzon
                // e.g. panel.vivzon.cloud -> vivzon (if we take parts[-2]?)
                // Actually, if it is panel.example.com, parts are [panel, example, com].
                // We want 'example'.

                // Common TLDs handling might be complex, so let's try a simple approach:
                // Take the SLD which is usually immediately before the TLD.

                // Allow specific overrides here if needed, otherwise dynamic:
                $sld = $parts[count($parts) - 2];
                $brand = ucfirst($sld);

                // Special case for 'shm-panel' test var or similar if needed
            }
        }

        return $brand;
    }
}
?>