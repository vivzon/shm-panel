<?php
/**
 * VIVZON CPANEL - Login Page (Production v5.0)
 */
require_once '../shared/config.php';

if (isset($_SESSION['client'])) {
    header("Location: index.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $u = trim($_POST['u'] ?? '');
    $p = $_POST['p'] ?? '';

    if (!empty($u) && !empty($p)) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password, status FROM clients WHERE username = ? OR email = ?");
            $stmt->execute([$u, $u]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($p, $user['password'])) {
                if ($user['status'] === 'suspended') {
                    $error = "Account suspended. Contact support.";
                } else {
                    session_regenerate_id(true);
                    $_SESSION['client'] = $user['username'];
                    $_SESSION['cid'] = $user['id'];
                    header("Location: index.php");
                    exit;
                }
            } else {
                $error = "Invalid credentials.";
            }
        } catch (PDOException $e) {
            $error = "System Error.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= get_branding() ?> - SHM Client</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --theme-color: #2563eb;
            --theme-color-hover: #1d4ed8;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #020617;
            overflow: hidden;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        .glass-panel {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 40px rgba(0, 0, 0, 0.4);
        }

        .input-group {
            position: relative;
        }

        .input-field {
            background: rgba(30, 41, 59, 0.4);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s ease;
        }

        .input-field:focus {
            background: rgba(30, 41, 59, 0.6);
            border-color: var(--theme-color);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
        }

        .input-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #94a3b8;
            font-size: 0.875rem;
            font-weight: 600;
        }

        /* Ambient Glows */
        .glow-1 {
            background: radial-gradient(circle, rgba(37, 99, 235, 0.15) 0%, transparent 70%);
        }

        .glow-2 {
            background: radial-gradient(circle, rgba(139, 92, 246, 0.15) 0%, transparent 70%);
        }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen relative text-slate-200">

    <!-- Background Effects -->
    <div class="fixed inset-0 z-0 pointer-events-none">
        <div
            class="absolute top-[-10%] left-[-10%] w-[50%] h-[50%] glow-1 blur-3xl rounded-full opacity-60 animate-pulse">
        </div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[50%] h-[50%] glow-2 blur-3xl rounded-full opacity-60 animate-pulse"
            style="animation-delay: 2s"></div>
    </div>

    <div class="w-full max-w-[420px] p-6 relative z-10 perspective-[1000px]">
        <div class="glass-panel p-8 md:p-10 rounded-3xl transform transition-all duration-500 hover:scale-[1.005]">

            <!-- Header -->
            <div class="text-center mb-10">
                <div
                    class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 shadow-lg shadow-blue-500/30 mb-6 group transition-transform hover:rotate-6">
                    <svg xmlns="http://www.w3.org/2000/svg"
                        class="w-8 h-8 text-white transition-transform group-hover:scale-110" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-white font-heading tracking-tight mb-2">Welcome Back</h1>
                <p class="text-slate-400 text-sm">Sign in to your Client Portal</p>
            </div>

            <?php if ($error): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3 animate-[shake_0.5s_ease-in-out]">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6"
                onsubmit="this.querySelector('button[type=submit]').classList.add('loading')">

                <div class="input-group">
                    <label for="u">Username or Email</label>
                    <input id="u" name="u" type="text" required placeholder="Enter your username"
                        class="input-field w-full rounded-xl px-4 py-3.5 text-sm text-white outline-none focus:ring-2 focus:ring-blue-500/50">
                </div>

                <div class="input-group">
                    <label for="p">Password</label>
                    <input id="p" name="p" type="password" required placeholder="Enter your password"
                        class="input-field w-full rounded-xl px-4 py-3.5 text-sm text-white outline-none focus:ring-2 focus:ring-blue-500/50">
                </div>

                <div class="flex items-center justify-between text-xs">
                    <label class="flex items-center gap-2 cursor-pointer group">
                        <input type="checkbox"
                            class="w-3.5 h-3.5 rounded border-slate-700 bg-slate-800 text-blue-600 focus:ring-offset-0 focus:ring-blue-500/50 transition-colors">
                        <span class="text-slate-400 group-hover:text-slate-300 transition-colors">Remember me</span>
                    </label>
                    <a href="forgot_password.php"
                        class="text-blue-500 hover:text-blue-400 font-medium transition-colors">Forgot
                        password?</a>
                </div>

                <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 rounded-xl shadow-lg shadow-blue-600/20 hover:shadow-blue-600/30 transition-all transform hover:-translate-y-0.5 active:translate-y-0 disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span>Sign In</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                    </svg>
                </button>

            </form>
        </div>

        <div class="mt-8 text-center">
            <p class="text-xs text-slate-600">&copy; <?= date('Y') ?> <?= get_branding() ?>. Secure Access.</p>
        </div>
    </div>

    <style>
        /* Custom Shake Animation */
        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-5px);
            }

            75% {
                transform: translateX(5px);
            }
        }

        .loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }

        .loading::after {
            content: "";
            position: absolute;
            width: 20px;
            height: 20px;
            border: 2px solid #fff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
        }

        @keyframes spin {
            from {
                transform: translate(-50%, -50%) rotate(0deg);
            }

            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }
    </style>
</body>

</html>