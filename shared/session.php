<?php
/**
 * Secure Session Handler
 * 
 * Implements secure session configuration to prevent:
 * - Session fixation
 * - Session hijacking
 * - XSS attacks via session cookies
 * 
 * @package SHM Panel
 * @version 1.0.0
 */

// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {

    // Secure session configuration
    ini_set('session.cookie_httponly', 1);      // Prevent JavaScript access to session cookie
    ini_set('session.cookie_secure', 1);        // Only send cookie over HTTPS
    ini_set('session.cookie_samesite', 'Strict'); // Prevent CSRF attacks
    ini_set('session.use_strict_mode', 1);      // Reject uninitialized session IDs
    ini_set('session.use_only_cookies', 1);     // Don't accept session IDs from URL
    ini_set('session.use_trans_sid', 0);        // Don't add session ID to URLs
    ini_set('session.entropy_length', 32);      // Strong session ID generation
    ini_set('session.hash_function', 'sha256'); // Use SHA-256 for session hashing

    // Set session cookie parameters
    session_set_cookie_params([
        'lifetime' => 3600,                     // 1 hour
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => true,                       // HTTPS only
        'httponly' => true,                     // No JavaScript access
        'samesite' => 'Strict'                  // Strict same-site policy
    ]);

    // Custom session name (more secure than default PHPSESSID)
    session_name('SHM_SESSION');

    // Start the session
    session_start();

    // Regenerate session ID periodically to prevent fixation
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        // Regenerate session ID every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }

    // Validate session to prevent hijacking
    if (!isset($_SESSION['user_agent'])) {
        // First time - store user agent
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
    } else {
        // Validate user agent hasn't changed
        if ($_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            // Possible session hijacking attempt
            session_unset();
            session_destroy();

            // Log security event
            error_log("Possible session hijacking detected - User agent mismatch");

            // Redirect to appropriate login page based on context
            $login_page = (isset($_SESSION['admin']) || strpos($_SERVER['PHP_SELF'] ?? '', '/whm/') !== false)
                ? '/whm/login.php'
                : '/cpanel/login.php';
            header('Location: ' . $login_page);
            exit;
        }

        // Optional: Also validate IP address (can cause issues with mobile users)
        // Uncomment if you want strict IP validation
        /*
        if ($_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
            session_unset();
            session_destroy();
            error_log("Possible session hijacking detected - IP address mismatch");
            header('Location: /cpanel/login.php');
            exit;
        }
        */
    }

    // Set last activity timestamp for timeout
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
    } else {
        // Check if session has timed out (1 hour of inactivity)
        if (time() - $_SESSION['last_activity'] > 3600) {
            session_unset();
            session_destroy();

            // Redirect to appropriate login page with timeout message based on context
            $login_page = (isset($_SESSION['admin']) || strpos($_SERVER['PHP_SELF'] ?? '', '/whm/') !== false)
                ? '/whm/login.php?timeout=1'
                : '/cpanel/login.php?timeout=1';
            header('Location: ' . $login_page);
            exit;
        }

        // Update last activity time
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Destroy session securely
 */
function destroy_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Unset all session variables
        $_SESSION = [];

        // Delete session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(
                session_name(),
                '',
                time() - 3600,
                '/',
                $_SERVER['HTTP_HOST'] ?? '',
                true,
                true
            );
        }

        // Destroy session
        session_destroy();
    }
}

/**
 * Check if user is logged in
 * 
 * @param string $type User type ('client' or 'admin')
 * @return bool
 */
function is_logged_in($type = 'client')
{
    if ($type === 'admin') {
        return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
    } else {
        return isset($_SESSION['client_id']) && !empty($_SESSION['client_id']);
    }
}

/**
 * Require login - redirect to login page if not authenticated
 * 
 * @param string $type User type ('client' or 'admin')
 * @param string $redirect_url URL to redirect to after login
 */
function require_login($type = 'client', $redirect_url = null)
{
    if (!is_logged_in($type)) {
        // Store intended destination
        if ($redirect_url) {
            $_SESSION['redirect_after_login'] = $redirect_url;
        }

        // Redirect to appropriate login page
        $login_page = $type === 'admin' ? '/whm/login.php' : '/cpanel/login.php';
        header('Location: ' . $login_page);
        exit;
    }
}

/**
 * Get current user ID
 * 
 * @param string $type User type ('client' or 'admin')
 * @return int|null
 */
function get_user_id($type = 'client')
{
    if ($type === 'admin') {
        return $_SESSION['admin_id'] ?? null;
    } else {
        return $_SESSION['client_id'] ?? null;
    }
}

/**
 * Get current username
 * 
 * @param string $type User type ('client' or 'admin')
 * @return string|null
 */
function get_username($type = 'client')
{
    if ($type === 'admin') {
        return $_SESSION['admin_username'] ?? null;
    } else {
        return $_SESSION['username'] ?? null;
    }
}

/**
 * Set flash message for next request
 * 
 * @param string $message Message to display
 * @param string $type Message type (success, error, warning, info)
 */
function set_flash_message($message, $type = 'info')
{
    $_SESSION['flash_message'] = [
        'message' => $message,
        'type' => $type
    ];
}

/**
 * Get and clear flash message
 * 
 * @return array|null Array with 'message' and 'type' keys, or null
 */
function get_flash_message()
{
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $flash;
    }
    return null;
}
?>