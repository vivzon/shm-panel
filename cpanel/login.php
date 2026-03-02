<?php
error_log("Client login: " . date('Y-m-d H:i:s'));
try {
    require_once '../shared/config.php';
} catch (Exception $e) {
    die("<div style='font-family:sans-serif;padding:20px;background:#fee;color:#c00'><b>Configuration Error</b><br>" . htmlspecialchars($e->getMessage()) . "</div>");
}

if (isset($_SESSION['client'])) {
    header("Location: index.php");
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['u'] ?? '');
    $p = $_POST['p'] ?? '';
    if ($u && $p) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password, status FROM clients WHERE username = ? OR email = ?");
            $stmt->execute([$u, $u]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($p, $user['password'])) {
                if ($user['status'] === 'suspended') {
                    $error = "Your account has been suspended. Please contact support.";
                } else {
                    session_regenerate_id(true);
                    $_SESSION['client'] = $user['username'];
                    $_SESSION['cid'] = $user['id'];
                    header("Location: index.php");
                    exit;
                }
            } else {
                $error = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            error_log("Login DB: " . $e->getMessage());
            $error = "System error. Please try again.";
        }
    } else {
        $error = "Please enter your username and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Client Portal — <?= htmlspecialchars(get_branding()) ?>">
    <title>Client Portal | <?= htmlspecialchars(get_branding()) ?></title>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/modern-design.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* Modern Design System loaded via CSS */
    </style>
</head>

<body>
    <div class="bg-grid"></div>

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
                    Welcome back</h1>
                <p style="color: #94a3b8; font-size: 0.875rem;">Sign in to your <span
                        style="color: #475569; font-weight: 600;"><?= htmlspecialchars(get_branding()) ?></span> client
                    area</p>
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
            <form method="POST" class="fade-up d2" style="display: flex; flex-direction: column; gap: 1rem;"
                onsubmit="this.querySelector('.btn-submit').classList.add('loading')">

                <div>
                    <label class="field-label" for="u">Username or Email</label>
                    <div class="input-wrap">
                        <i data-lucide="user" style="width: 1rem; height: 1rem; color: #94a3b8;"></i>
                        <input id="u" name="u" type="text" required placeholder="your@email.com" autocomplete="username"
                            value="<?= htmlspecialchars($_POST['u'] ?? '') ?>">
                    </div>
                </div>

                <div>
                    <div
                        style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.375rem;">
                        <label class="field-label" for="p" style="margin-bottom:0">Password</label>
                        <a href="forgot_password.php"
                            style="font-size: 11px; color: #2563eb; font-weight: 600; transition: color 0.2s; text-decoration: none;"
                            onmouseover="this.style.color='#1d4ed8'" onmouseout="this.style.color='#2563eb'">Forgot
                            password?</a>
                    </div>
                    <div class="input-wrap">
                        <i data-lucide="lock" style="width: 1rem; height: 1rem; color: #94a3b8;"></i>
                        <input id="pwd" name="p" type="password" required placeholder="Enter your password"
                            autocomplete="current-password">
                        <button type="button" class="eye-toggle" onclick="togglePwd()" title="Show/hide password">
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

                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="checkbox" id="remember"
                        style="width: 0.875rem; height: 0.875rem; border-radius: 0.25rem; accent-color: #2563eb;">
                    <label for="remember"
                        style="font-size: 0.75rem; color: #64748b; cursor: pointer; user-select: none;">Keep me signed
                        in
                        for 30 days</label>
                </div>

                <button type="submit" class="btn-submit" style="margin-top: 0.25rem;">
                    <i data-lucide="log-in" style="width: 1rem; height: 1rem;"></i>
                    Sign In to Client Portal
                </button>
            </form>

            <p class="fade-up d3" style="margin-top: 2rem; text-align: center; font-size: 0.625rem; color: #94a3b8;">
                &copy; <?= date('Y') ?> <?= htmlspecialchars(get_branding()) ?> &mdash; Secure &amp; Encrypted
                Connection
            </p>
        </div>
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