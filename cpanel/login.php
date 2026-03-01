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
    <meta name="description" content="Client Portal — Vivzon Cloud">
    <title>Client Portal | Vivzon Cloud</title>
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
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f1f5f9;
            min-height: 100svh;
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
                linear-gradient(rgba(148, 163, 184, 0.12) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.12) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        /* ── Split login card ── */
        .login-wrapper {
            display: flex;
            width: 860px;
            max-width: calc(100vw - 32px);
            min-height: 540px;
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid #cbd5e1;
            box-shadow: 0 24px 60px rgba(100, 116, 139, 0.25), 0 4px 16px rgba(0, 0, 0, 0.08);
        }

        /* ── Left brand panel ── */
        .brand-panel {
            width: 42%;
            background: linear-gradient(145deg, #1e40af 0%, #312e81 50%, #1e1b4b 100%);
            padding: 44px 38px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }

        .brand-glow {
            position: absolute;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(165, 180, 252, 0.25) 0%, transparent 70%);
            top: -80px;
            right: -60px;
            border-radius: 50%;
            pointer-events: none;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }

        .feature-icon {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            flex-shrink: 0;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ── Right form panel ── */
        .form-panel {
            flex: 1;
            background: #ffffff;
            padding: 44px 42px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* ── Input ── */
        .field-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 6px;
        }

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

        .eye-toggle {
            cursor: pointer;
            color: #94a3b8;
            transition: color 0.2s;
        }

        .eye-toggle:hover {
            color: #64748b;
        }

        /* ── Submit ── */
        .btn-submit {
            background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.28);
            border: none;
            cursor: pointer;
            color: white;
            font-weight: 700;
            font-size: 0.9rem;
            padding: 13px;
            border-radius: 12px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
            font-family: 'Plus Jakarta Sans', sans-serif;
            position: relative;
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 28px rgba(37, 99, 235, 0.38);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit.loading {
            color: transparent;
            pointer-events: none;
        }

        .btn-submit.loading::after {
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
            animation-delay: 0.1s;
        }

        .d2 {
            animation-delay: 0.2s;
        }

        .d3 {
            animation-delay: 0.3s;
        }

        @media(max-width:640px) {
            .login-wrapper {
                flex-direction: column;
            }

            .brand-panel {
                display: none;
            }

            .form-panel {
                padding: 32px 24px;
            }
        }
    </style>
</head>

<body>
    <div class="bg-grid"></div>

    <div class="login-wrapper relative z-10 fade-up">

        <!-- ── Left: Brand Panel ── -->
        <div class="brand-panel">
            <div class="brand-glow"></div>
            <div class="relative z-10">
                <div class="flex items-center gap-3 mb-10">
                    <div class="w-9 h-9 rounded-xl bg-white/10 border border-white/15 flex items-center justify-center">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 15a4.5 4.5 0 004.5 4.5H18a3.75 3.75 0 001.332-7.257 3 3 0 00-3.758-3.848 5.25 5.25 0 00-10.233 2.33A4.502 4.502 0 002.25 15z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-bold font-heading text-white/90">Vivzon Cloud</div>
                        <div class="text-[10px] text-white/40 font-medium">Client Portal</div>
                    </div>
                </div>

                <h2 class="text-2xl font-bold font-heading text-white mb-2 leading-snug">Your hosting,<br>fully in
                    control.</h2>
                <p class="text-sm text-blue-200/50 mb-9 leading-relaxed">Manage domains, databases, emails and more from
                    one powerful dashboard.</p>

                <div>
                    <?php
                    $features = [
                        ['NVMe Cloud Hosting', 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z'],
                        ['Free SSL & DDoS Shield', 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z'],
                        ['24/7 Expert Support', 'M19.114 5.636a9 9 0 010 12.728M16.463 8.288a5.25 5.25 0 010 7.424M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 012.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75z'],
                    ];
                    foreach ($features as $f): ?>
                        <div class="feature-item">
                            <div class="feature-icon">
                                <svg class="w-3.5 h-3.5 text-blue-200" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $f[1] ?>" />
                                </svg>
                            </div>
                            <div class="text-sm text-blue-100/70 font-medium pt-0.5"><?= $f[0] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="relative z-10">
                <div class="inline-flex items-center gap-2 bg-white/5 border border-white/10 rounded-full px-3 py-1.5">
                    <div class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></div>
                    <span class="text-[10px] font-bold text-white/50 uppercase tracking-widest">All Systems
                        Operational</span>
                </div>
            </div>
        </div>

        <!-- ── Right: Form Panel ── -->
        <div class="form-panel">

            <div class="mb-7 fade-up d1">
                <h1 class="text-2xl font-bold font-heading text-slate-900 mb-1">Welcome back</h1>
                <p class="text-slate-400 text-sm">Sign in to your <span class="text-slate-600 font-semibold">Vivzon
                        Technologies</span> client area</p>
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
            <form method="POST" class="space-y-4 fade-up d2"
                onsubmit="this.querySelector('.btn-submit').classList.add('loading')">

                <div>
                    <label class="field-label" for="u">Username or Email</label>
                    <div class="input-wrap">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        <input id="u" name="u" type="text" required placeholder="your@email.com" autocomplete="username"
                            value="<?= htmlspecialchars($_POST['u'] ?? '') ?>">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="field-label" for="p" style="margin-bottom:0">Password</label>
                        <a href="forgot_password.php"
                            class="text-[11px] text-blue-600 hover:text-blue-700 font-semibold transition">Forgot
                            password?</a>
                    </div>
                    <div class="input-wrap">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                        <input id="pwd" name="p" type="password" required placeholder="Enter your password"
                            autocomplete="current-password">
                        <button type="button" class="eye-toggle" onclick="togglePwd()" title="Show/hide password">
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

                <div class="flex items-center gap-2">
                    <input type="checkbox" id="remember" class="w-3.5 h-3.5 rounded accent-blue-600">
                    <label for="remember" class="text-xs text-slate-500 cursor-pointer select-none">Keep me signed in
                        for 30 days</label>
                </div>

                <button type="submit" class="btn-submit mt-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
                    Sign In to Client Portal
                </button>
            </form>

            <p class="mt-8 text-center text-[10px] text-slate-400 fade-up d3">
                &copy; <?= date('Y') ?> Vivzon Cloud &mdash; Secure &amp; Encrypted Connection
            </p>
        </div>
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
