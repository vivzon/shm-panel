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
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #010409;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        .glow-bg {
            position: absolute;
            inset: 0;
            z-index: 0;
            background: radial-gradient(circle at 50% 50%, rgba(29, 78, 216, 0.15), rgba(0, 0, 0, 0));
        }

        .glass-card {
            background: rgba(13, 17, 23, 0.8);
            border: 1px solid rgba(48, 54, 61, 0.8);
            box-shadow: 0 0 0 1px rgba(48, 54, 61, 0.4), 0 20px 40px -10px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(12px);
            margin-top: 60px;
            margin-bottom: 60px;
        }

        .input-field {
            background: rgba(1, 4, 9, 0.6);
            border: 1px solid #30363d;
            transition: all 0.2s;
        }

        .input-field:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }

        .btn-primary {
            background: #2563eb;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .checkbox-wrapper input:checked {
            background-color: white;
            border-color: white;
        }
    </style>
</head>

<body class="bg-[#010409] text-white">

    <div class="glow-bg"></div>

    <div class="w-full max-w-[400px] z-10 relative">
        <div class="glass-card rounded-3xl p-8 md:p-10">

            <div class="flex flex-col items-center text-center mb-8">
                <div
                    class="w-16 h-16 bg-blue-600 rounded-2xl flex items-center justify-center mb-6 shadow-lg shadow-blue-500/20">
                    <i data-lucide="shield" class="w-8 h-8 text-white fill-current"></i>
                </div>
                <h1 class="text-2xl font-bold font-heading mb-2">SHM Admin</h1>
                <p class="text-slate-400 text-xs">Use your master credentials to access the console</p>
            </div>

            <?php if (isset($error)): ?>
                <div
                    class="mb-6 bg-red-900/20 border border-red-900/50 rounded-lg p-3 text-center text-red-400 text-xs font-bold">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-400">Username or Email</label>
                    <div class="input-field rounded-xl px-4 py-3">
                        <input name="u" type="text" required placeholder="Enter your username"
                            class="bg-transparent border-none outline-none text-sm text-white placeholder-slate-600 w-full font-medium">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-400">Password</label>
                    <div class="input-field rounded-xl px-4 py-3">
                        <input name="p" type="password" required placeholder="Enter your password"
                            class="bg-transparent border-none outline-none text-sm text-white placeholder-slate-600 w-full font-medium">
                    </div>
                </div>

                <div class="flex justify-between items-center text-xs mt-2">
                    <label
                        class="flex items-center gap-2 cursor-pointer text-slate-400 hover:text-slate-300 transition">
                        <input type="checkbox"
                            class="rounded bg-slate-800 border-slate-700 text-blue-500 focus:ring-0 w-3 h-3">
                        Remember me
                    </label>
                    <a href="#" class="text-blue-500 hover:text-blue-400 font-bold">Forgot password?</a>
                </div>

                <button type="submit"
                    class="btn-primary w-full py-3.5 rounded-xl font-bold flex items-center justify-center gap-2 text-sm mt-6 shadow-lg shadow-blue-600/20">
                    Sign In <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
            </form>
        </div>

        <p class="text-center text-[10px] text-slate-600 mt-8 font-medium">
            &copy; 2026 Webguruindia. Secure Access.
        </p>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>