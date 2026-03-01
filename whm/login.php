<?php
require_once __DIR__ . '/../shared/config.php';
if (isset($_SESSION['admin'])) {
    header("Location: /whm/index.php");
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
            header("Location: /whm/index.php");
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
    <title>Admin Console | Vivzon Technologies</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #030712;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        /* ── Background ── */
        .bg-grid {
            position: fixed;
            inset: 0;
            z-index: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.025) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(120px);
            pointer-events: none;
            z-index: 0;
        }

        /* ── Card ── */
        .login-card {
            background: linear-gradient(145deg, rgba(15, 23, 42, 0.9) 0%, rgba(7, 11, 26, 0.95) 100%);
            border: 1px solid rgba(255, 255, 255, 0.07);
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.04),
                0 32px 80px rgba(0, 0, 0, 0.6),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(24px);
        }

        /* ── Logo ring ── */
        .logo-ring {
            background: linear-gradient(135deg, #1e40af, #4338ca);
            box-shadow: 0 0 0 6px rgba(67, 56, 202, 0.12), 0 12px 32px rgba(67, 56, 202, 0.4);
        }

        /* ── Inputs ── */
        .input-wrap {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: border-color 0.25s, box-shadow 0.25s;
            border-radius: 12px;
        }

        .input-wrap:focus-within {
            border-color: rgba(99, 102, 241, 0.6);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
        }

        .input-wrap input {
            background: transparent;
            border: none;
            outline: none;
            color: #fff;
            font-size: 0.875rem;
            width: 100%;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .input-wrap input::placeholder {
            color: #475569;
        }

        /* ── Toggle password ── */
        .eye-btn {
            cursor: pointer;
            color: #475569;
            transition: color 0.2s;
        }

        .eye-btn:hover {
            color: #94a3b8;
        }

        /* ── Submit button ── */
        .btn-signin {
            background: linear-gradient(135deg, #1e40af 0%, #4338ca 100%);
            box-shadow: 0 8px 32px rgba(67, 56, 202, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-signin:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 40px rgba(67, 56, 202, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        .btn-signin:active {
            transform: translateY(0);
        }

        /* ── Error shake ── */
        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            20%,
            60% {
                transform: translateX(-6px);
            }

            40%,
            80% {
                transform: translateX(6px);
            }
        }

        .shake {
            animation: shake 0.45s ease;
        }

        /* ── Loading spinner on button ── */
        .btn-signin.loading {
            color: transparent !important;
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

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        /* ── Fade in ── */
        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(24px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-up {
            animation: fadeUp 0.7s ease both;
        }

        .delay-1 {
            animation-delay: 0.1s;
        }

        .delay-2 {
            animation-delay: 0.2s;
        }

        .delay-3 {
            animation-delay: 0.3s;
        }

        /* ── Security badge ── */
        .sec-badge {
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
    </style>
</head>

<body class="text-white">

    <!-- Background -->
    <div class="bg-grid"></div>
    <div class="blob w-[600px] h-[600px] bg-indigo-600/20 top-[-15%] left-[-10%]"></div>
    <div class="blob w-[500px] h-[500px] bg-blue-700/15 bottom-[-10%] right-[-10%]"></div>

    <div class="relative z-10 w-full max-w-[420px] px-5">

        <!-- Brand header -->
        <div class="text-center mb-8 fade-up">
            <div class="inline-flex items-center gap-2 mb-1">
                <div class="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></div>
                <span class="text-[10px] font-bold uppercase tracking-[0.2em] text-indigo-400">Vivzon
                    Technologies</span>
            </div>
            <div class="text-[11px] text-slate-600 font-medium">Secure Server Management Console</div>
        </div>

        <!-- Card -->
        <div class="login-card rounded-3xl p-8 fade-up delay-1">

            <!-- Logo + Title -->
            <div class="flex flex-col items-center mb-8">
                <div class="logo-ring w-16 h-16 rounded-2xl flex items-center justify-center mb-5">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" stroke-width="1.8"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                </div>
                <h1 class="text-2xl font-bold font-heading text-white tracking-tight mb-1">Admin Console</h1>
                <p class="text-slate-500 text-xs font-medium">Restricted — authorized personnel only</p>
            </div>

            <!-- Error -->
            <?php if ($error): ?>
                <div id="err-box"
                    class="mb-6 flex items-center gap-3 bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3 shake">
                    <svg class="w-4 h-4 text-red-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <span class="text-red-400 text-xs font-semibold"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" class="space-y-5" onsubmit="this.querySelector('.btn-signin').classList.add('loading')">

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-400 block">Username</label>
                    <div class="input-wrap flex items-center px-4 py-3.5 gap-3">
                        <svg class="w-4 h-4 text-slate-600 shrink-0" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        <input name="u" type="text" required placeholder="admin username" autocomplete="username"
                            value="<?= htmlspecialchars($_POST['u'] ?? '') ?>">
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-400 block">Password</label>
                    <div class="input-wrap flex items-center px-4 py-3.5 gap-3">
                        <svg class="w-4 h-4 text-slate-600 shrink-0" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                        <input id="pwd" name="p" type="password" required placeholder="••••••••••"
                            autocomplete="current-password">
                        <button type="button" class="eye-btn shrink-0" onclick="togglePwd()">
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

                <div class="text-right mb-1">
                    <a href="../cpanel/forgot_password.php"
                        class="text-[11px] text-indigo-400 hover:text-indigo-300 font-semibold transition">Forgot
                        password?</a>
                </div>

                <button type="submit"
                    class="btn-signin relative w-full py-3.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2 mt-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
                    Sign in to Console
                </button>

            </form>

            <!-- Security indicators -->
            <div class="mt-7 pt-6 border-t border-white/[0.05] flex items-center justify-center gap-4">
                <div class="sec-badge flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-1 rounded-full">
                    <div class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></div>
                    TLS Encrypted
                </div>
                <div class="sec-badge flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-1 rounded-full">
                    <div class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></div>
                    Session Protected
                </div>
            </div>
        </div>

        <!-- Footer -->
        <p class="text-center text-[10px] text-slate-700 mt-6 fade-up delay-3 font-medium">
            &copy; <?= date('Y') ?> Vivzon Technologies. All rights reserved.
        </p>
    </div>

    <script>
        function togglePwd() {
            const input = document.getElementById('pwd');
            const icon = document.getElementById('eye-icon');
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