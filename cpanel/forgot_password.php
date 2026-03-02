<?php
/**
 * Vivzon Cloud - FORGOT PASSWORD
 * Glassmorphism Design
 */
require_once __DIR__ . '/../shared/config.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['u']);
    if (!$input) {
        $error = "Please enter your username or email.";
    } else {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT id, email, username FROM clients WHERE username = ? OR email = ?");
            $stmt->execute([$input, $input]);
            $user = $stmt->fetch();

            // Always show success message for security (don't reveal user existence)
            // In a real app, we would send the email here.
            $success = "If an account exists for '<strong>" . htmlspecialchars($input) . "</strong>', a password reset link has been sent to the registered email address.";

            // Log the request (Simulation)
            if ($user && function_exists('error_log')) {
                error_log("Password reset requested for user: " . $user['username']);
            }

        } catch (PDOException $e) {
            $error = "System error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | <?= htmlspecialchars(get_branding()) ?></title>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&family=Lexend:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/modern-design.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* Modern Design System loaded via CSS */
    </style>
</head>

<body>

    <!-- Background Effects -->
    <div style="position: fixed; inset: 0; z-index: 0; pointer-events: none;">
        <div class="glow-1 animate-pulse"></div>
        <div class="glow-2 animate-pulse"></div>
    </div>

    <div style="width: 100%; max-width: 420px; padding: 1.5rem; position: relative; z-index: 10;">
        <div class="glass-panel">

            <!-- Header -->
            <div style="text-align: center; margin-bottom: 2.5rem;">
                <div style="display: inline-flex; align-items: center; justify-content: center; width: 4rem; height: 4rem; border-radius: 1rem; background: linear-gradient(135deg, var(--primary), var(--secondary)); box-shadow: var(--shadow-lg); margin-bottom: 1.5rem; transition: transform 0.2s;"
                    onmouseover="this.style.transform='rotate(6deg)'" onmouseout="this.style.transform='rotate(0deg)'">
                    <i data-lucide="shield-check" style="width: 2rem; height: 2rem; color: #fff;"></i>
                </div>
                <h1 style="font-size: 1.5rem; font-weight: 700; color: #e2e8f0; font-family: 'Lexend', sans-serif; letter-spacing: -0.025em; margin-bottom: 0.5rem;"
                    class="font-heading">Reset Password</h1>
                <p style="color: #94a3b8; font-size: 0.875rem;">Enter your credentials to recover access</p>
            </div>

            <?php if ($error): ?>
                <div
                    style="margin-bottom: 2rem; padding: 1rem; border-radius: 0.75rem; background-color: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #f87171; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; gap: 0.75rem;">
                    <i data-lucide="alert-circle" style="width: 1rem; height: 1rem; flex-shrink: 0;"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div
                    style="margin-bottom: 2rem; padding: 1rem; border-radius: 0.75rem; background-color: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: #34d399; font-size: 0.875rem; display: flex; align-items: flex-start; gap: 0.75rem;">
                    <i data-lucide="check-circle"
                        style="width: 1.25rem; height: 1.25rem; flex-shrink: 0; margin-top: 0.125rem;"></i>
                    <div>
                        <?= $success ?>
                    </div>
                </div>
                <button onclick="window.location.href='login.php'" class="btn btn-secondary" style="width: 100%;">
                    Return to Login
                </button>
            <?php else: ?>

                <form method="POST" style="display: flex; flex-direction: column; gap: 1.5rem;">
                    <div class="input-group">
                        <label for="u">Username or Email</label>
                        <input id="u" name="u" type="text" required placeholder="Enter username or email"
                            class="input-field" style="color: #e2e8f0;">
                    </div>

                    <button type="submit" class="btn btn-primary"
                        style="width: 100%; padding: 0.875rem; border-radius: 0.75rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                        <span>Send Reset Link</span>
                        <i data-lucide="send" style="width: 1rem; height: 1rem;"></i>
                    </button>
                </form>

                <div style="margin-top: 1.5rem; text-align: center;">
                    <a href="login.php"
                        style="font-size: 0.875rem; color: #cbd5e1; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; text-decoration: none; transition: color 0.2s;"
                        onmouseover="this.style.color='#fff'" onmouseout="this.style.color='#cbd5e1'">
                        <i data-lucide="arrow-left" style="width: 1rem; height: 1rem;"></i>
                        Back to Login
                    </a>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>