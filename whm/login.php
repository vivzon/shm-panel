<?php
require_once __DIR__ . '/../shared/config.php';
if (isset($_SESSION['admin'])) {
    header("Location: /index.php");
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['u'] ?? '');
    $p = $_POST['p'] ?? '';
    if ($u && $p) {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch();
        if ($user && password_verify($p, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = $user['username'];
            header("Location: /index.php");
            exit;
        }
    }
    $error = "Invalid credentials. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | <?= htmlspecialchars(get_branding()) ?></title>
    <link rel="stylesheet" href="/assets/css/modern-design.css">
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* Modern Design System loaded via CSS */
    </style>
</head>

<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh;">
    <div class="bg-grid"></div>

    <div style="position: relative; z-index: 10; width: 100%; max-width: 400px; padding: 0 1.25rem;">

        <!-- Brand pill -->
        <div style="text-align: center; margin-bottom: 1.5rem;" class="fade-up">
            <div
                style="display: inline-flex; align-items: center; gap: 0.5rem; background: white; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); border-radius: 9999px; padding: 0.375rem 1rem;">
                <div style="width: 0.375rem; height: 0.375rem; border-radius: 9999px; background: #2563eb;"></div>
                <span
                    style="font-size: 11px; font-weight: 700; color: #475569; letter-spacing: 0.05em; text-transform: uppercase;">
                    <?= htmlspecialchars(get_branding()) ?>
                </span>
            </div>
        </div>

        <div class="login-card fade-up d1" style="padding: 2rem;">

            <!-- Icon + Title -->
            <div style="display: flex; flex-direction: column; align-items: center; margin-bottom: 1.75rem;">
                <div class="logo-ring"
                    style="width: 3.5rem; height: 3.5rem; border-radius: 1rem; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                    <i data-lucide="shield-check" style="width: 1.75rem; height: 1.75rem; color: white;"></i>
                </div>
                <h1 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem;"
                    class="font-heading">Admin Console</h1>
                <p style="color: #94a3b8; font-size: 0.75rem; font-weight: 500;">Authorized personnel only</p>
            </div>

            <!-- Error -->
            <?php if ($error): ?>
                <div class="error-box" style="margin-bottom: 1.25rem;">
                    <i data-lucide="alert-circle" style="width: 1rem; height: 1rem; color: #ef4444; flex-shrink: 0;"></i>
                    <span
                        style="color: #dc2626; font-size: 0.75rem; font-weight: 600;"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" style="display: flex; flex-direction: column; gap: 1rem;"
                onsubmit="this.querySelector('.btn-signin').classList.add('loading')">

                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                    <label
                        style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; display: block;">Username</label>
                    <div class="input-wrap">
                        <i data-lucide="user" style="width: 1rem; height: 1rem; color: #94a3b8;"></i>
                        <input name="u" type="text" required placeholder="admin username" autocomplete="username"
                            value="<?= htmlspecialchars($_POST['u'] ?? '') ?>">
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                    <label
                        style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.25rem;">Password</label>
                    <div class="input-wrap">
                        <i data-lucide="lock" style="width: 1rem; height: 1rem; color: #94a3b8;"></i>
                        <input id="pwd" name="p" type="password" required placeholder="••••••••••"
                            autocomplete="current-password">
                        <button type="button" class="eye-btn" onclick="togglePwd()"
                            style="background: none; border: none; padding: 0;">
                            <svg id="eye-icon" style="width: 1rem; height: 1rem;" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z" />
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-signin" style="margin-top: 0.25rem;">
                    <i data-lucide="log-in" style="width: 1rem; height: 1rem;"></i>
                    Sign in to Admin
                </button>
            </form>

            <!-- Security -->
            <div
                style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: center; gap: 0.75rem;">
                <div class="sec-badge">
                    <div style="width: 0.375rem; height: 0.375rem; border-radius: 9999px; background: #10b981;"></div>
                    TLS Secured
                </div>
                <div class="sec-badge">
                    <div style="width: 0.375rem; height: 0.375rem; border-radius: 9999px; background: #10b981;"></div>
                    Session Protected
                </div>
            </div>
        </div>

        <p class="fade-up d2" style="text-align: center; font-size: 10px; color: #94a3b8; margin-top: 1.25rem;">&copy;
            <?= date('Y') ?> <?= htmlspecialchars(get_branding()) ?>. All rights reserved.
        </p>
    </div>

    <script>
        lucide.createIcons();
        function togglePwd() {
            const input = document.getElementById('pwd'), icon = document.getElementById('eye-icon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>`;
            } else {
                input.type = 'password';
                icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>`;
            }
        }
    </script>
</body>

</html>