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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WHM Administration | Secure Access</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #020617;
            overflow: hidden;
        }

        /* Animated Background */
        .bg-glow {
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.15) 0%, rgba(0, 0, 0, 0) 70%);
            border-radius: 50%;
            animation: float 10s infinite ease-in-out;
            z-index: 0;
        }

        @keyframes float {

            0%,
            100% {
                transform: translate(0, 0);
            }

            50% {
                transform: translate(30px, -30px);
            }
        }

        .glass-panel {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .input-dark {
            background: rgba(2, 6, 23, 0.5);
            border: 1px solid rgba(51, 65, 85, 0.5);
            color: #f8fafc;
        }

        .input-dark:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.2);
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out forwards;
            opacity: 0;
            transform: translateY(10px);
        }

        @keyframes fadeIn {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen relative">

    <!-- Background Elements -->
    <div class="bg-glow top-[-100px] left-[-100px]"></div>
    <div class="bg-glow bottom-[-100px] right-[-100px]"
        style="background: radial-gradient(circle, rgba(124, 58, 237, 0.1) 0%, rgba(0,0,0,0) 70%); animation-delay: 2s;">
    </div>

    <div class="w-full max-w-sm relative z-10 p-6">

        <!-- Header -->
        <div class="text-center mb-10 fade-in" style="animation-delay: 0.1s">
            <div
                class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 shadow-2xl shadow-blue-900/50 mb-6 border border-white/10">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-white" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-white tracking-tight mb-2">WHM Admin</h1>
            <p class="text-slate-400 text-sm">Server & Account Management</p>
        </div>

        <!-- Login Card -->
        <div class="glass-panel p-8 rounded-[2rem] fade-in" style="animation-delay: 0.2s">
            <?php if (isset($error)): ?>
                <div
                    class="bg-red-500/10 border border-red-500/20 text-red-400 p-4 rounded-xl mb-6 text-xs font-bold text-center flex items-center justify-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <div class="space-y-1">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-2">Username</label>
                    <input name="u" required placeholder="admin" autocomplete="off"
                        class="input-dark w-full p-4 rounded-2xl outline-none transition duration-300 placeholder:text-slate-600 text-sm">
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-2">Password</label>
                    <input name="p" type="password" required placeholder="••••••••"
                        class="input-dark w-full p-4 rounded-2xl outline-none transition duration-300 placeholder:text-slate-600 text-sm">
                </div>

                <button
                    class="w-full py-4 mt-4 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-bold rounded-2xl shadow-lg shadow-blue-600/20 transition-all duration-300 transform hover:scale-[1.02] active:scale-[0.98]">
                    Authenticate
                </button>
            </form>
        </div>

        <p class="text-center mt-8 text-slate-600 text-xs font-mono fade-in" style="animation-delay: 0.3s">
            SECURE ENVIRONMENT v2.0
        </p>
    </div>

</body>

</html>