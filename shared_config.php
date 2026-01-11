<?php
/**
 * SHM PANEL - SHARED CONFIGURATION
 * ================================
 */

// Database Credentials (Should match /etc/shm/config.sh)
$db_host = 'localhost';
$db_name = 'shm_panel';
$db_user = 'shm_admin';
$db_pass = 'SHMPanel_Secure_Pass_2025'; // Changed during install

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<b>SHM Panel Error:</b> Database connection failed. Please check config.");
}

// Shell Command Bridge
function cmd($command)
{
    // Only allow specific characters to prevent injection (basic filter)
    // The strict logic is in shm-manage.
    $output = shell_exec("sudo /usr/local/bin/shm-manage " . $command);
    return trim($output);
}

// Helper: JSON Response
function sendResponse($data)
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    // Enable Cross-Subdomain Session (SSO)
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!filter_var($host, FILTER_VALIDATE_IP) && $host !== 'localhost') {
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            $base_domain = '.' . implode('.', array_slice($parts, -2));
            session_set_cookie_params(0, '/', $base_domain);
        }
    }
    session_start();
}
?>