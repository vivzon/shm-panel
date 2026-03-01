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
    <title>Admin Login | Vivzon Cloud</title>
    <link rel="stylesheet" href="layout/modern-design.css">
    href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap"
    rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        /* ── Background grid ── */
        .bg-grid {
            position: fixed;
            inset: 0;
            z-index: 0;
            background-image:
                linear-gradient(rgba(148, 163, 184, 0.15) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.15) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        /* ── Card ── */
        .login-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            box-shadow: 0 20px 60px rgba(148, 163, 184, 0.35), 0 4px 16px rgba(0, 0, 0, 0.08);
            border-radius: 24px;
        }

        /* ── Logo ── */
        .logo-ring {
            background: linear-gradient(135deg, #1e40af, #4338ca);
            box-shadow: 0 8px 24px rgba(67, 56, 202, 0.3);
        }

        /* ── Input ── */
        .input-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            padding: 12px 16px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-wrap:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: #fff;
        }

        .input-wrap svg {
            color: #94a3b8;
            flex-shrink: 0;
        }

        .input-wrap input {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            color: #1e293b;
            font-size: 0.875rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .input-wrap input::placeholder {
            color: #94a3b8;
        }

        .eye-btn {
            cursor: pointer;
            color: #94a3b8;
            transition: color 0.2s;
        }

        .eye-btn:hover {
            color: #64748b;
        }

        /* ── Button ── */
        .btn-signin {
            background: linear-gradient(135deg, #1e40af 0%, #4338ca 100%);
            box-shadow: 0 6px 20px rgba(67, 56, 202, 0.3);
            border: none;
            cursor: pointer;
            color: white;
            font-weight: 700;
            padding: 13px;
            border-radius: 12px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.9rem;
            position: relative;
        }

        .btn-signin:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 30px rgba(67, 56, 202, 0.4);
        }

        .btn-signin:active {
            transform: translateY(0);
        }

        .btn-signin.loading {
            color: transparent;
            pointer-events: none;
        }

        .btn-signin.loading::after {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
        }

        /* ── Error ── */
        .error-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shake 0.4s ease;
        }

        /* ── Security badge ── */
        .sec-badge {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 100px;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0)
            }

            20%,
            60% {
                transform: translateX(-5px)
            }

            40%,
            80% {
                transform: translateX(5px)
            }
        }

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(16px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .fade-up {
            animation: fadeUp 0.55s ease both;
        }

        .d1 {
            animation-delay: 0.08s;
        }

        .d2 {
            animation-delay: 0.16s;
        }
    </style>
</head>

<body>
    <div class="bg-grid"></div>

    <div style="position: relative; z-index: 10; width: 100%; max-width: 400px; padding: 0 1.25rem;">

        <!-- Brand pill -->
        <div style="text-align: center; margin-bottom: 1.5rem;" class="fade-up">
            <div
                style="display: inline-flex; align-items: center; gap: 0.5rem; background: white; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); border-radius: 9999px; padding: 0.375rem 1rem;">
                <div style="width: 0.375rem; height: 0.375rem; border-radius: 9999px; background: #2563eb;"></div>
                <span
                    style="font-size: 11px; font-weight: 700; color: #475569; letter-spacing: 0.05em; text-transform: uppercase;">Vivzon
                    Cloud</span>
            </div>
        </div>

        <div class="login-card fade-up d1" style="padding: 2rem;">

            <!-- Icon + Title -->
            <div style="display: flex; flex-direction: column; align-items: center; margin-bottom: 1.75rem;">
                <div class="logo-ring"
                    style="width: 3.5rem; height: 3.5rem; border-radius: 1rem; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                    <svg style="width: 1.75rem; height: 1.75rem; color: white;" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                </div>
                <h1 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem;"
                    class="font-heading">Admin Console</h1>
                <p style="color: #94a3b8; font-size: 0.75rem; font-weight: 500;">Authorized personnel only</p>
            </div>

            <!-- Error -->
            <?php if ($error): ?>
                <div class="error-box" style="margin-bottom: 1.25rem;">
                    <svg style="width: 1rem; height: 1rem; color: #ef4444; flex-shrink: 0;" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
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
                        <svg style="width: 1rem; height: 1rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        <input name="u" type="text" required placeholder="admin username" autocomplete="username"
                            value="<?= htmlspecialchars($_POST['u'] ?? '') ?>">
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                    <label
                        style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.25rem;">Password</label>
                    <div class="input-wrap">
                        <svg style="width: 1rem; height: 1rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
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
                    <svg style="width: 1rem; height: 1rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
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
            <?= date('Y') ?> Vivzon Cloud.
            All rights reserved.</p>
    </div>

    <script>
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