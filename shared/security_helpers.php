<?php
/**
 * Security Helper Functions
 * Provides common security utilities for the cPanel system
 */

/**
 * Escape output for HTML display (XSS protection)
 * @param string|null $str String to escape
 * @return string Escaped string
 */
function e($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Get CSRF token for forms
 * @return string CSRF token
 */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from POST request
 * @throws Exception if token is invalid
 */
function verify_csrf()
{
    if (empty($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        throw new Exception("Invalid security token. Please refresh the page.");
    }
}

/**
 * Generate CSRF token field for forms
 * @return string HTML input field
 */
function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Sanitize filename for safe file operations
 * @param string $filename Filename to sanitize
 * @return string Sanitized filename
 */
function sanitize_filename($filename)
{
    // Remove path traversal attempts
    $filename = basename($filename);
    // Remove dangerous characters
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return $filename;
}

/**
 * Validate domain name format
 * @param string $domain Domain to validate
 * @return bool True if valid
 */
function is_valid_domain($domain)
{
    return (bool) preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i', $domain);
}

/**
 * Validate email address format
 * @param string $email Email to validate
 * @return bool True if valid
 */
function is_valid_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Log security event
 * @param string $event Event description
 * @param array $context Additional context
 */
function log_security_event($event, $context = [])
{
    $username = $_SESSION['client'] ?? 'anonymous';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $message = "Security Event [$username@$ip]: $event";

    if (!empty($context)) {
        $message .= ' | ' . json_encode($context);
    }

    error_log($message);
}

/**
 * Check rate limit for action
 * @param string $action Action identifier
 * @param int $max_attempts Maximum attempts allowed
 * @param int $window_seconds Time window in seconds
 * @return bool True if within limit
 */
function check_rate_limit($action, $max_attempts = 5, $window_seconds = 900)
{
    $key = 'rate_limit_' . $action . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset_time' => time() + $window_seconds];
    }

    $limit = &$_SESSION[$key];

    // Reset if window expired
    if (time() > $limit['reset_time']) {
        $limit = ['count' => 0, 'reset_time' => time() + $window_seconds];
    }

    $limit['count']++;

    return $limit['count'] <= $max_attempts;
}
