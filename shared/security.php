<?php
/**
 * Security Functions
 * 
 * CSRF protection, input validation, and other security utilities
 * 
 * @package SHM Panel
 * @version 1.0.0
 */

/**
 * Generate or retrieve CSRF token for current session
 * 
 * @return string CSRF token
 */
if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

/**
 * Generate HTML hidden input field with CSRF token
 * 
 * @return string HTML input field
 * 
 * @example
 * <form method="POST">
 *     <?php echo csrf_field(); ?>
 *     <input type="text" name="domain">
 *     <button type="submit">Submit</button>
 * </form>
 */
if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

/**
 * Verify CSRF token from request
 * 
 * @param string $token Token to verify
 * @return bool True if valid
 * @throws Exception If token is invalid
 * 
 * @example
 * if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 *     verify_csrf_token($_POST['csrf_token'] ?? '');
 *     // Process form...
 * }
 */
if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token'])) {
            http_response_code(403);
            die('CSRF token not found in session');
        }

        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            error_log("CSRF token validation failed for user: " . ($_SESSION['username'] ?? 'unknown'));
            die('CSRF token validation failed');
        }

        return true;
    }
}

/**
 * Sanitize user input
 * 
 * @param string $input Input to sanitize
 * @param string $type Type of sanitization (html, email, url, string)
 * @return string Sanitized input
 */
function sanitize_input($input, $type = 'string')
{
    switch ($type) {
        case 'html':
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        case 'email':
            return filter_var($input, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($input, FILTER_SANITIZE_URL);
        case 'int':
            return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
        case 'string':
        default:
            return strip_tags(trim($input));
    }
}

/**
 * Validate input against specific rules
 * 
 * @param string $input Input to validate
 * @param string $type Type of validation
 * @return bool True if valid
 * 
 * @example
 * if (!validate_input($_POST['email'], 'email')) {
 *     die('Invalid email address');
 * }
 */
function validate_input($input, $type)
{
    switch ($type) {
        case 'username':
            return preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $input);
        case 'domain':
            return preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $input);
        case 'email':
            return filter_var($input, FILTER_VALIDATE_EMAIL) !== false;
        case 'ip':
            return filter_var($input, FILTER_VALIDATE_IP) !== false;
        case 'url':
            return filter_var($input, FILTER_VALIDATE_URL) !== false;
        case 'int':
            return filter_var($input, FILTER_VALIDATE_INT) !== false;
        default:
            return false;
    }
}

/**
 * Generate secure random password
 * 
 * @param int $length Password length
 * @return string Random password
 */
function generate_password($length = 16)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    $max = strlen($chars) - 1;

    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }

    return $password;
}

/**
 * Hash password securely
 * 
 * @param string $password Plain text password
 * @return string Hashed password
 */
function hash_password($password)
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password against hash
 * 
 * @param string $password Plain text password
 * @param string $hash Hashed password
 * @return bool True if password matches
 */
function verify_password($password, $hash)
{
    return password_verify($password, $hash);
}

/**
 * Check if password needs rehashing (algorithm or cost changed)
 * 
 * @param string $hash Current password hash
 * @return bool True if rehash needed
 */
function needs_password_rehash($hash)
{
    // Note: calls the PHP built-in, NOT this function recursively
    return \password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Escape shell argument for safe command execution
 * 
 * @param string $arg Argument to escape
 * @return string Escaped argument
 */
function escape_shell_arg_safe($arg)
{
    // Additional validation before escaping
    if (preg_match('/[;&|`$<>]/', $arg)) {
        throw new InvalidArgumentException("Potentially dangerous characters in shell argument");
    }
    return escapeshellarg($arg);
}

/**
 * Rate limiting check (Upgraded to Redis)
 * 
 * Uses Redis for robust IP/Action-based rate limiting to prevent 
 * session-dropping bypasses. Falls back to Database, then Session.
 * 
 * @param string $key Unique key for rate limit (e.g., 'login_' . $ip)
 * @param int $maxAttempts Maximum attempts allowed
 * @param int $timeWindow Time window in seconds
 * @return bool True if within rate limit
 */
if (!function_exists('check_rate_limit')) {
    function check_rate_limit($key, $maxAttempts = 5, $timeWindow = 300)
    {
        // Sanitize key for strict safety
        $safeKey = 'shmpanel_ratelimit:' . preg_replace('/[^a-zA-Z0-9_\-\.:]/', '', $key);

        // 1. Try Redis (Best option, prevents session dropping)
        if (class_exists('Redis')) {
            try {
                $redis = new Redis();
                // Default redis port from setup script
                if (@$redis->connect('127.0.0.1', 6379, 1.0)) {
                    $current = $redis->get($safeKey);

                    if ($current === false) {
                        $redis->setex($safeKey, $timeWindow, 1);
                        return true;
                    }

                    if ($current >= $maxAttempts) {
                        return false;
                    }

                    $redis->incr($safeKey);
                    return true;
                }
            } catch (Exception $e) {
                error_log("Redis rate limit failed, falling back: " . $e->getMessage());
            }
        }

        // 2. Try Database Fallback
        try {
            require_once __DIR__ . '/Database.php';
            $db = Database::getInstance();

            // Cleanup old entries
            $db->execute("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)", [$timeWindow]);

            // In our schema, login_attempts uses ip natively, so extract IP from key
            $parts = explode(':', $key);
            $ip = end($parts);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $stmt = $db->query("SELECT COUNT(*) as count FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)", [$ip, $timeWindow]);
                $result = $stmt->fetch();

                if (($result['count'] ?? 0) >= $maxAttempts) {
                    return false;
                }
                return true; // The actual INSERT happens in internal login logic usually
            }
        } catch (Exception $e) {
            // Silently continue to session fallback
        }

        // 3. Fallback to Session (Weakest, but better than nothing)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $now = time();

        if (!isset($_SESSION['rate_limit'][$key])) {
            $_SESSION['rate_limit'][$key] = [
                'attempts' => 1,
                'first_attempt' => $now
            ];
            return true;
        }

        $data = $_SESSION['rate_limit'][$key];

        if ($now - $data['first_attempt'] > $timeWindow) {
            $_SESSION['rate_limit'][$key] = [
                'attempts' => 1,
                'first_attempt' => $now
            ];
            return true;
        }

        $_SESSION['rate_limit'][$key]['attempts']++;

        if ($data['attempts'] >= $maxAttempts) {
            return false;
        }

        return true;
    }
} // end if (!function_exists('check_rate_limit'))

/**
 * Log security event
 * 
 * @param string $event Event description
 * @param string $severity Severity level (info, warning, critical)
 * @param array $context Additional context
 */
if (!function_exists('log_security_event')) {
    function log_security_event($event, $severity = 'info', $context = [])
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'severity' => $severity,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user' => $_SESSION['username'] ?? $_SESSION['admin_username'] ?? 'guest',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'context' => $context
        ];

        $logFile = '/var/log/shm-security.log';
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND);

        // Also log to database if available
        try {
            require_once __DIR__ . '/Database.php';
            $db = Database::getInstance();
            $db->execute(
                "INSERT INTO security_logs (event, severity, ip, user, user_agent, context, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$event, $severity, $logEntry['ip'], $logEntry['user'], $logEntry['user_agent'], json_encode($context)]
            );
        } catch (Exception $e) {
            // Silently fail if database logging fails
            error_log("Failed to log security event to database: " . $e->getMessage());
        }
    }
} // end if (!function_exists('log_security_event'))
?>