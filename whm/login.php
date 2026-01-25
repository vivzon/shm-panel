<?php
require_once __DIR__ . '/../shared/config.php';
if (isset($_SESSION['admin'])) {
    header("Location: /");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $u = $_POST['u'];
    $p = $_POST['p'];
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$u]);
    $user = $stmt->fetch();

    if ($user && password_verify($p, $user['password'])) {
        $_SESSION['admin'] = $user['username'];
        header("Location: /index.php");
        exit;
    } else {
        $error = "Invalid credentials";
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHM Admin | System Administration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --theme-color: #6366f1;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #000000;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        /* Ambient Glows */
        .glow-bg {
            position: absolute;
            inset: 0;
            overflow: hidden;
            z-index: 0;
            background: #020617;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.4;
            animation: orbFloat 20s infinite ease-in-out;
        }

        .orb-1 {
            width: 50vw;
            height: 50vw;
            background: #4f46e5;
            top: -10%;
            left: -10%;
            animation-delay: 0s;
        }

        .orb-2 {
            width: 40vw;
            height: 40vw;
            background: #ec4899;
            bottom: -10%;
            right: -10%;
            animation-delay: -5s;
        }

        .orb-3 {
            width: 20vw;
            height: 20vw;
            background: #06b6d4;
            bottom: 20%;
            left: 20%;
            animation-delay: -10s;
        }

        @keyframes orbFloat {

            0%,
            100% {
                transform: translate(0, 0) scale(1);
            }

            33% {
                transform: translate(30px, -50px) scale(1.1);
            }

            66% {
                transform: translate(-20px, 20px) scale(0.9);
            }
        }

        /* Glass Panel */
        .glass-panel {
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.05),
                0 25px 50px -12px rgba(0, 0, 0, 0.5),
                inset 0 0 40px rgba(255, 255, 255, 0.02);
        }

        /* Inputs */
        .input-field {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .input-field:focus-within {
            background: rgba(15, 23, 42, 0.8);
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }

        /* Button Glow */
        .btn-glow {
            position: relative;
            overflow: hidden;
        }

        .btn-glow::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 60%);
            transform: scale(0);
            transition: transform 0.6s ease-out;
            pointer-events: none;
        }

        .btn-glow:hover::before {
            transform: scale(1);
        }

        /* Loading */
        .loading span {
            opacity: 0;
        }

        .loading svg {
            display: none;
        }

        .loading::after {
            content: "";
            position: absolute;
            width: 20px;
            height: 20px;
            border: 2px solid currentColor;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
        }

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }
    </style>
</head>

<body class="selection:bg-indigo-500/30 selection:text-indigo-200">

    <!-- Background -->
    <div class="glow-bg">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
        <!-- Noise Overlay -->
        <div class="absolute inset-0 opacity-[0.15]"
            style="background-image: url('data:image/svg+xml,%3Csvg viewBox=%220 0 200 200%22 xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cfilter id=%22noiseFilter%22%3E%3CfeTurbulence type=%22fractalNoise%22 baseFrequency=%220.65%22 numOctaves=%223%22 stitchTiles=%22stitch%22/%3E%3C/filter%3E%3Crect width=%22100%25%22 height=%22100%25%22 filter=%22url(%23noiseFilter)%22/%3E%3C/svg%3E');">
        </div>
    </div>

    <!-- Main Card -->
    <div class="w-full max-w-[440px] px-6 relative z-10">

        <!-- Logo Area -->
        <div class="text-center mb-8 flex flex-col items-center">
            <div
                class="h-16 w-16 bg-gradient-to-br from-indigo-500 to-violet-600 rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-500/20 mb-6 group cursor-pointer transition-transform hover:rotate-3 hover:scale-105">
                <i data-lucide="shield-check" class="w-8 h-8 text-white"></i>
            </div>

            <h1 class="text-3xl font-bold text-white font-heading tracking-tight mb-2">
                SHM <span class="text-indigo-400">Admin</span>
            </h1>
            <p class="text-slate-400 font-medium text-sm">Use your master credentials to access the console</p>
        </div>

        <div class="glass-panel p-1 rounded-3xl">
            <div class="bg-slate-950/40 rounded-[22px] p-8 md:p-10 backdrop-blur-sm">

                <?php if (isset($error)): ?>
                    <div
                        class="mb-6 bg-red-500/10 border border-red-500/20 rounded-xl p-3 flex items-center gap-3 text-red-400 text-xs font-bold animate-bounce-short">
                        <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6"
                    onsubmit="document.getElementById('btn-submit').classList.add('loading')">

                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-slate-400 uppercase tracking-widest ml-1">Username</label>
                        <div
                            class="input-field rounded-xl flex items-center px-4 py-3 gap-3 focus-within:ring-2 focus-within:ring-indigo-500/20">
                            <i data-lucide="user" class="w-5 h-5 text-slate-500"></i>
                            <input name="u" type="text" required placeholder="admin" autocomplete="off"
                                class="bg-transparent border-none outline-none text-sm text-white placeholder-slate-600 w-full">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-slate-400 uppercase tracking-widest ml-1">Password</label>
                        <div
                            class="input-field rounded-xl flex items-center px-4 py-3 gap-3 focus-within:ring-2 focus-within:ring-indigo-500/20 relative">
                            <i data-lucide="lock" class="w-5 h-5 text-slate-500"></i>
                            <input name="p" id="pass-input" type="password" required placeholder="••••••••"
                                class="bg-transparent border-none outline-none text-sm text-white placeholder-slate-600 w-full pr-8">

                            <button type="button" onclick="togglePass()"
                                class="absolute right-4 text-slate-500 hover:text-white transition focus:outline-none">
                                <i data-lucide="eye" id="eye-icon" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" id="btn-submit"
                        class="btn-glow w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-4 rounded-xl shadow-lg shadow-indigo-600/20 transition-all transform hover:-translate-y-0.5 mt-2 flex items-center justify-center gap-2 text-sm uppercase tracking-wide">
                        <span>Authenticate</span>
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>

                </form>

                <div class="mt-8 pt-6 border-t border-slate-800/50 text-center">
                    <p class="text-[10px] font-bold text-slate-600 uppercase tracking-widest">
                        Protected System • v5.0 Stable
                    </p>
                </div>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();

        function togglePass() {
            const inp = document.getElementById('pass-input');
            const icon = document.getElementById('eye-icon');
            if (inp.type === "password") {
                inp.type = "text";
                icon.setAttribute('data-lucide', 'eye-off');
            } else {
                inp.type = "password";
                icon.setAttribute('data-lucide', 'eye');
            }
            lucide.createIcons();
        }
    </script>
</body>

</html>