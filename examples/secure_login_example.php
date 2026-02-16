<?php
/**
 * Example: Secure Login Implementation
 * 
 * This is an example of how to update cpanel/login.php with all security fixes
 * Use this as a template for updating other files
 */

// Include security files
require_once '../shared/session.php';      // Secure session handling
require_once '../shared/security.php';     // CSRF, validation, password functions
require_once '../shared/Database.php';     // Secure database queries

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. CSRF Protection
        verify_csrf_token($_POST['csrf_token'] ?? '');

        // 2. Rate Limiting (5 attempts per 5 minutes per IP)
        $rateKey = 'login:' . $_SERVER['REMOTE_ADDR'];
        if (!check_rate_limit($rateKey, 5, 300)) {
            $error = 'Too many login attempts. Please try again in 5 minutes.';
            log_security_event('Rate limit exceeded on login', 'warning', [
                'ip' => $_SERVER['REMOTE_ADDR']
            ]);
        } else {
            // 3. Input Sanitization
            $username = sanitize_input($_POST['username'] ?? '', 'string');
            $password = $_POST['password'] ?? '';

            // 4. Input Validation
            if (empty($username) || empty($password)) {
                $error = 'Username and password are required';
            } elseif (!validate_input($username, 'username')) {
                $error = 'Invalid username format';
            } else {
                // 5. Secure Database Query (Prepared Statement)
                $db = Database::getInstance();
                $client = $db->fetchOne(
                    "SELECT * FROM clients WHERE username = ? AND status = 'active'",
                    [$username]
                );

                // Log login attempt
                $db->execute(
                    "INSERT INTO login_attempts (username, ip, user_agent, success, created_at) VALUES (?, ?, ?, ?, NOW())",
                    [$username, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '', 0]
                );

                // 6. Secure Password Verification
                if ($client && verify_password($password, $client['password'])) {
                    // Login successful

                    // Update login attempt to success
                    $db->execute(
                        "UPDATE login_attempts SET success = 1 WHERE id = LAST_INSERT_ID()"
                    );

                    // 7. Regenerate Session ID (prevent session fixation)
                    session_regenerate_id(true);

                    // 8. Set Session Variables
                    $_SESSION['client_id'] = $client['id'];
                    $_SESSION['username'] = $client['username'];
                    $_SESSION['email'] = $client['email'];
                    $_SESSION['login_time'] = time();

                    // 9. Check if password needs rehashing (algorithm/cost changed)
                    if (password_needs_rehash($client['password'])) {
                        $newHash = hash_password($password);
                        $db->execute(
                            "UPDATE clients SET password = ? WHERE id = ?",
                            [$newHash, $client['id']]
                        );
                    }

                    // 10. Update last login timestamp
                    $db->execute(
                        "UPDATE clients SET last_login = NOW() WHERE id = ?",
                        [$client['id']]
                    );

                    // 11. Track active session
                    $db->execute(
                        "INSERT INTO active_sessions (session_id, user_id, user_type, ip, user_agent) VALUES (?, ?, 'client', ?, ?)",
                        [session_id(), $client['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '']
                    );

                    // 12. Log successful login
                    log_security_event('Successful login', 'info', [
                        'username' => $username,
                        'client_id' => $client['id']
                    ]);

                    // 13. Redirect to dashboard or intended page
                    $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
                    unset($_SESSION['redirect_after_login']);

                    header('Location: ' . $redirect);
                    exit;
                } else {
                    // Login failed
                    $error = 'Invalid username or password';

                    // Log failed login attempt
                    log_security_event('Failed login attempt', 'warning', [
                        'username' => $username,
                        'reason' => $client ? 'invalid_password' : 'user_not_found'
                    ]);

                    // Add small delay to prevent timing attacks
                    usleep(500000); // 0.5 seconds
                }
            }
        }
    } catch (Exception $e) {
        $error = 'An error occurred. Please try again.';
        error_log("Login error: " . $e->getMessage());
    }
}

// Get flash message if any
$flash = get_flash_message();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SHM Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-slate-900 to-slate-800 min-h-screen flex items-center justify-center p-4">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-slate-900 mb-2">Welcome Back</h1>
            <p class="text-slate-600">Sign in to your SHM Panel account</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6">
                <p class="font-medium">
                    <?= htmlspecialchars($error) ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div
                class="bg-<?= $flash['type'] === 'success' ? 'green' : 'blue' ?>-50 border border-<?= $flash['type'] === 'success' ? 'green' : 'blue' ?>-200 text-<?= $flash['type'] === 'success' ? 'green' : 'blue' ?>-800 px-4 py-3 rounded-lg mb-6">
                <p class="font-medium">
                    <?= htmlspecialchars($flash['message']) ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-6">
                <p class="font-medium">Your session has expired. Please login again.</p>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-6">
            <!-- CSRF Protection -->
            <?php echo csrf_field(); ?>

            <div>
                <label for="username" class="block text-sm font-semibold text-slate-700 mb-2">
                    Username
                </label>
                <input type="text" id="username" name="username" required autocomplete="username"
                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition"
                    placeholder="Enter your username"
                    value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
            </div>

            <div>
                <label for="password" class="block text-sm font-semibold text-slate-700 mb-2">
                    Password
                </label>
                <input type="password" id="password" name="password" required autocomplete="current-password"
                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition"
                    placeholder="Enter your password">
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center">
                    <input type="checkbox" name="remember"
                        class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <span class="ml-2 text-sm text-slate-600">Remember me</span>
                </label>
                <a href="forgot_password.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                    Forgot password?
                </a>
            </div>

            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition duration-200 shadow-lg hover:shadow-xl">
                Sign In
            </button>
        </form>

        <div class="mt-6 text-center text-sm text-slate-600">
            Don't have an account?
            <a href="../landing/index.php" class="text-blue-600 hover:text-blue-700 font-medium">
                Sign up
            </a>
        </div>

        <div class="mt-8 pt-6 border-t border-slate-200 text-center">
            <p class="text-xs text-slate-500">
                Protected by advanced security features including CSRF protection,
                rate limiting, and secure session management.
            </p>
        </div>
    </div>

</body>

</html>