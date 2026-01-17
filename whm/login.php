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
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Space Grotesk', sans-serif;
            background: #000;
            overflow: hidden;
        }

        .aurora-bg {
            position: absolute;
            inset: 0;
            z-index: 0;
            background: radial-gradient(circle at 0% 0%, #1e1b4b 0%, #000000 50%), radial-gradient(circle at 100% 100%, #312e81 0%, #000000 50%);
        }

        .aurora-blob {
            position: absolute;
            filter: blur(80px);
            opacity: 0.6;
            animation: float 10s infinite ease-in-out;
        }

        @keyframes float {

            0%,
            100% {
                transform: translate(0, 0);
            }

            50% {
                transform: translate(20px, -20px);
            }
        }

        .glass-panel {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 40px rgba(0, 0, 0, 0.5);
        }

        .input-group input:focus~label,
        .input-group input:not(:placeholder-shown)~label {
            top: -10px;
            left: 10px;
            font-size: 10px;
            color: #60a5fa;
            background: #000;
            padding: 0 5px;
        }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen relative text-white">

    <div class="aurora-bg">
        <div class="aurora-blob bg-blue-900/40 w-96 h-96 top-0 left-0"></div>
        <div class="aurora-blob bg-indigo-900/40 w-96 h-96 bottom-0 right-0" style="animation-delay: -2s"></div>
    </div>

    <div class="relative z-10 w-full max-w-md p-6">
        <div class="glass-panel p-10 rounded-3xl">
            <div class="text-center mb-10">
                <div
                    class="w-16 h-16 bg-gradient-to-tr from-blue-600 to-indigo-600 rounded-2xl mx-auto flex items-center justify-center shadow-lg shadow-blue-500/30 mb-6">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-white" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                    </svg>
                </div>
                <h1 class="text-3xl font-bold tracking-tight mb-2">Admin Portal</h1>
                <p class="text-slate-500 text-sm">Valid credentials required for access</p>
            </div>

            <?php if (isset($error)): ?>
                <div
                    class="bg-red-500/10 border border-red-500/20 text-red-500 p-3 rounded-xl mb-6 text-xs font-bold text-center">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div class="relative input-group">
                    <input name="u" required
                        class="w-full bg-transparent border border-slate-700 rounded-xl p-4 text-sm outline-none focus:border-blue-500 transition-colors placeholder-transparent"
                        placeholder="Username">
                    <label
                        class="absolute left-4 top-4 text-slate-500 text-sm transition-all pointer-events-none">Username</label>
                </div>

                <div class="relative input-group">
                    <input name="p" type="password" required
                        class="w-full bg-transparent border border-slate-700 rounded-xl p-4 text-sm outline-none focus:border-blue-500 transition-colors placeholder-transparent"
                        placeholder="Password">
                    <label
                        class="absolute left-4 top-4 text-slate-500 text-sm transition-all pointer-events-none">Password</label>
                </div>

                <button
                    class="w-full py-4 bg-white text-black font-bold rounded-xl hover:bg-slate-200 transition transform hover:scale-[1.01] active:scale-[0.99] shadow-xl">
                    Secure Login
                </button>
            </form>
        </div>
        <div class="text-center mt-8 text-slate-600 text-xs uppercase tracking-widest font-bold">
            SHM Panel v5.0
        </div>
    </div>

</body>

</html>