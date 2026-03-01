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
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        blue: {
                            50: '#f0f5ff',
                            100: '#e0ebff',
                            200: '#cce0ff',
                            300: '#99c2ff',
                            400: '#66a3ff',
                            500: '#4880ed',
                            600: '#2563eb', /* Primary */
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                        indigo: {
                            50: '#f2f4fb',
                            100: '#e6ebfb',
                            200: '#cdcdfa',
                            300: '#9ea6eb',
                            400: '#6f7ee1',
                            500: '#3f51b5', /* Secondary */
                            600: '#36469b',
                            700: '#2c397e',
                            800: '#242f67',
                            900: '#1f2752',
                        }
                    }
                }
            }
        }
    </script>
    <link
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

    <div class="relative z-10 w-full max-w-[400px] px-5">

        <!-- Brand pill -->
        <div class="text-center mb-6 fade-up">
            <div
                class="inline-flex items-center gap-2 bg-white border border-slate-200 shadow-sm rounded-full px-4 py-1.5">
                <div class="w-1.5 h-1.5 rounded-full bg-blue-600"></div>
                <span class="text-[11px] font-bold text-slate-600 tracking-wider uppercase">Vivzon Cloud</span>
            </div>
        </div>

        <div class="login-card p-8 fade-up d1">

            <!-- Icon + Title -->
            <div class="flex flex-col items-center mb-7">
                <div class="logo-ring w-14 h-14 rounded-2xl flex items-center justify-center mb-4">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold font-heading text-slate-900 mb-1">Admin Console</h1>
                <p class="text-slate-400 text-xs font-medium">Authorized personnel only</p>
            </div>

            <!-- Error -->
            <?php if ($error): ?>
                <div class="error-box mb-5">
                    <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <span class="text-red-600 text-xs font-semibold"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" class="space-y-4" onsubmit="this.querySelector('.btn-signin').classList.add('loading')">

                <div class="space-y-1">
                    <label class="text-[11px] font-bold text-slate-500 uppercase tracking-wider block">Username</label>
                    <div class="input-wrap">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        <input name="u" type="text" required placeholder="admin username" autocomplete="username"
                            value="<?= htmlspecialchars($_POST['u'] ?? '') ?>">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-[11px] font-bold text-slate-500 uppercase tracking-wider block mb-1">Password</label>
                    <div class="input-wrap">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                        <input id="pwd" name="p" type="password" required placeholder="••••••••••"
                            autocomplete="current-password">
                        <button type="button" class="eye-btn" onclick="togglePwd()">
                            <svg id="eye-icon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z" />
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-signin mt-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
                    Sign in to Admin
                </button>
            </form>

            <!-- Security -->
            <div class="mt-6 pt-5 border-t border-slate-100 flex items-center justify-center gap-3">
                <div class="sec-badge">
                    <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></div>TLS Secured
                </div>
                <div class="sec-badge">
                    <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></div>Session Protected
                </div>
            </div>
        </div>

        <p class="text-center text-[10px] text-slate-400 mt-5 fade-up d2">&copy; <?= date('Y') ?> Vivzon Cloud.
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
