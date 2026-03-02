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

<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh;">

    <!-- Background Effects -->
    <div style="position: fixed; inset: 0; z-index: 0; pointer-events: none;">
        <div class="glow-1 animate-pulse"></div>
        <div class="glow-2 animate-pulse"></div>
    </div>

    <div class="login-wrapper fade-up" style="position: relative; z-index: 10;">
        <!-- ── Left: Brand Panel ── -->
        <div class="brand-panel">
            <div class="brand-glow"></div>
            <div style="position: relative; z-index: 10;">
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2.5rem;">
                    <div
                        style="width: 2.25rem; height: 2.25rem; border-radius: 0.75rem; background-color: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center;">
                        <i data-lucide="cloud" style="width: 1rem; height: 1rem; color: #fff;"></i>
                    </div>
                    <div>
                        <div
                            style="font-size: 0.875rem; font-weight: 700; font-family: 'Outfit', sans-serif; color: rgba(255,255,255,0.9);">
                            <?= htmlspecialchars(get_branding()) ?>
                        </div>
                        <div style="font-size: 0.625rem; color: rgba(255,255,255,0.4); font-weight: 500;">Client Portal
                        </div>
                    </div>
                </div>

                <h2
                    style="font-size: 1.5rem; font-weight: 700; font-family: 'Outfit', sans-serif; color: #fff; margin-bottom: 0.5rem; line-height: 1.375;">
                    Your hosting,<br>fully in
                    control.</h2>
                <p
                    style="font-size: 0.875rem; color: rgba(191, 219, 254, 0.5); margin-bottom: 2.25rem; line-height: 1.625;">
                    Manage domains, databases, emails and more from
                    one powerful dashboard.</p>

                <div>
                    <?php
                    $features = [
                        ['NVMe Cloud Hosting', 'zap'],
                        ['Free SSL & DDoS Shield', 'shield-check'],
                        ['24/7 Expert Support', 'life-buoy'],
                    ];
                    foreach ($features as $f): ?>
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i data-lucide="<?= $f[1] ?>"
                                    style="width: 0.875rem; height: 0.875rem; color: #bfdbfe;"></i>
                            </div>
                            <div
                                style="font-size: 0.875rem; color: rgba(219, 234, 254, 0.7); font-weight: 500; padding-top: 0.125rem;">
                                <?= $f[0] ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="position: relative; z-index: 10;">
                <div
                    style="display: inline-flex; align-items: center; gap: 0.5rem; background-color: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 9999px; padding: 0.375rem 0.75rem;">
                    <div
                        style="width: 0.375rem; height: 0.375rem; border-radius: 9999px; background-color: #34d399; animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;">
                    </div>
                    <span
                        style="font-size: 0.625rem; font-weight: 700; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.1em;">All
                        Systems
                        Operational</span>
                </div>
            </div>
        </div>

        <!-- ── Right: Form Panel ── -->
        <div class="form-panel">
            <div class="fade-up d1" style="margin-bottom: 1.75rem;">
                <h1
                    style="font-size: 1.5rem; font-weight: 700; font-family: 'Outfit', sans-serif; color: #0f172a; margin-bottom: 0.25rem;">
                    Reset Password</h1>
                <p style="color: #94a3b8; font-size: 0.875rem;">Enter your credentials to recover access</p>
            </div>

            <?php if ($error): ?>
                <div class="error-box" style="margin-bottom: 1.25rem;">
                    <i data-lucide="alert-circle" style="width: 1rem; height: 1rem; color: #ef4444; flex-shrink: 0;"></i>
                    <span
                        style="color: #dc2626; font-size: 0.75rem; font-weight: 600;"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div
                    style="margin-bottom: 1.25rem; padding: 1rem; border-radius: 12px; background-color: rgba(16, 185, 129, 0.1); border: 1.5px solid rgba(16, 185, 129, 0.2); color: #34d399; font-size: 0.875rem; display: flex; align-items: flex-start; gap: 0.75rem;">
                    <i data-lucide="check-circle"
                        style="width: 1.25rem; height: 1.25rem; flex-shrink: 0; margin-top: 0.125rem;"></i>
                    <div>
                        <?= $success ?>
                    </div>
                </div>
                <button onclick="window.location.href='login.php'" class="btn-signin" style="margin-top: 0.5rem;">
                    Return to Login
                </button>
            <?php else: ?>

                <form method="POST" class="fade-up d2" style="display: flex; flex-direction: column; gap: 1rem;"
                    onsubmit="this.querySelector('.btn-signin').classList.add('loading')">
                    
                    <div>
                        <label class="field-label" for="u">Username or Email</label>
                        <div class="input-wrap">
                            <i data-lucide="user" style="width: 1rem; height: 1rem; color: #94a3b8;"></i>
                            <input id="u" name="u" type="text" required placeholder="Enter username or email"
                                autocomplete="username">
                        </div>
                    </div>

                    <button type="submit" class="btn-signin" style="margin-top: 0.5rem;">
                        <i data-lucide="send" style="width: 1rem; height: 1rem;"></i>
                        Send Reset Link
                    </button>
                </form>

                <div class="fade-up d3" style="margin-top: 1.75rem; text-align: center;">
                    <a href="login.php"
                        style="font-size: 0.875rem; color: #64748b; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; text-decoration: none; transition: color 0.2s;"
                        onmouseover="this.style.color='#0f172a'" onmouseout="this.style.color='#64748b'">
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