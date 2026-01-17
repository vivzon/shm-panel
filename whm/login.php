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
    <title>WHM | System Administration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --theme-color: #f59e0b;
            /* Amber for Admin Distinction? Or just Blue? Let's stick to Blue but maybe darker/different accent if needed, but User requested "unified". I will use Blue but maybe with a more "System" feel. Actually, unified means similar. I will stick to Blue/Indigo. */
            --theme-color: #4f46e5;
            /* Indigo for Admin */
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #000000;
            /* Darker purely black bg for Admin */
            overflow: hidden;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        .glass-panel {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 50px rgba(0, 0, 0, 0.6);
        }

        .input-group {
            position: relative;
        }

        .input-field {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(71, 85, 105, 0.2);
            transition: all 0.3s ease;
        }

        .input-field:focus {
            background: rgba(30, 41, 59, 0.8);
            border-color: var(--theme-color);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15);
        }

        .input-group label {
            pointer-events: none;
            transition: all 0.2s ease;
        }

        .input-field:focus~label,
        .input-field:not(:placeholder-shown)~label {
            transform: translateY(-26px) translateX(-4px) scale(0.85);
            color: var(--theme-color);
            font-weight: 600;
        }

        /* Ambient Glows */
        .glow-1 {
            background: radial-gradient(circle, rgba(79, 70, 229, 0.15) 0%, transparent 70%);
        }

        .glow-2 {
            background: radial-gradient(circle, rgba(236, 72, 153, 0.1) 0%, transparent 70%);
        }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen relative text-slate-200">

    <!-- Background Effects -->
    <div class="fixed inset-0 z-0 pointer-events-none">
        <div
            class="absolute top-[-10%] left-[-10%] w-[60%] h-[60%] glow-1 blur-[100px] rounded-full opacity-50 animate-pulse">
        </div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[60%] h-[60%] glow-2 blur-[100px] rounded-full opacity-40 animate-pulse"
            style="animation-delay: 2s"></div>
        <div
            class="absolute inset-0 bg-[url('https://grainy-gradients.vercel.app/noise.svg')] opacity-20 brightness-100 contrast-150 mix-blend-overlay">
        </div>
    </div>

    <div class="w-full max-w-[420px] p-6 relative z-10 perspective-[1000px]">
        <div class="glass-panel p-8 md:p-10 rounded-3xl transform transition-all duration-500 hover:scale-[1.005]">

            <!-- Header -->
            <div class="text-center mb-10">
                <div
                    class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-600 shadow-lg shadow-indigo-500/30 mb-6 group transition-transform hover:rotate-6">
                    <svg xmlns="http://www.w3.org/2000/svg"
                        class="w-8 h-8 text-white transition-transform group-hover:scale-110" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-white font-heading tracking-tight mb-2">WHM Admin</h1>
                <p class="text-slate-500 text-sm">System Administration Console</p>
            </div>

            <?php if (isset($error)): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 text-xs font-bold flex items-center gap-3 animate-[shake_0.5s_ease-in-out]">
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
                    <input id="u" name="u" type="text" required placeholder=" "
                        class="input-field w-full rounded-xl px-4 py-3.5 text-sm text-white outline-none">
                    <label for="u"
                        class="absolute left-4 top-3.5 text-slate-500 text-sm transition-all duration-200">Username</label>
                </div>

                <div class="input-group">
                    <input id="p" name="p" type="password" required placeholder=" "
                        class="input-field w-full rounded-xl px-4 py-3.5 text-sm text-white outline-none">
                    <label for="p"
                        class="absolute left-4 top-3.5 text-slate-500 text-sm transition-all duration-200">Password</label>
                </div>

                <button type="submit"
                    class="w-full bg-white hover:bg-slate-200 text-slate-900 font-bold py-3.5 rounded-xl shadow-lg shadow-white/10 hover:shadow-white/20 transition-all transform hover:-translate-y-0.5 active:translate-y-0 disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span>Authenticates</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                        </path>
                    </svg>
                </button>

            </form>
        </div>

        <div class="mt-8 text-center flex flex-col items-center gap-2">
            <span
                class="px-3 py-1 rounded-full bg-slate-900 border border-slate-800 text-[10px] uppercase font-bold text-slate-500 tracking-widest">
                v5.0 Stable
            </span>
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
            border: 2px solid #000;
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