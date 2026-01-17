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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Vivzon CPanel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background: #020617;
        }

        .radial-gradient {
            background: radial-gradient(circle at center, #1e293b 0%, #020617 100%);
        }

        .glossy-card {
            background: linear-gradient(180deg, rgba(30, 41, 59, 0.4) 0%, rgba(15, 23, 42, 0.6) 100%);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 0 50px -10px rgba(0, 0, 0, 0.5);
        }

        .input-field:focus {
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen relative overflow-hidden radial-gradient text-white">

    <!-- Decorative Elements -->
    <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl animate-pulse"></div>
    <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-emerald-500/10 rounded-full blur-3xl animate-pulse"
        style="animation-delay: 2s"></div>

    <div class="w-full max-w-md p-6 relative z-10">
        <div class="glossy-card p-10 rounded-3xl">
            <div class="flex items-center gap-4 mb-8">
                <div
                    class="w-12 h-12 bg-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-600/20">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl font-bold tracking-tight">Client Portal</h1>
                    <p class="text-slate-400 text-xs">Manage your digital assets</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div
                    class="mb-6 p-4 rounded-xl bg-red-500/10 text-red-500 text-xs font-bold flex items-center gap-3 border border-red-500/20">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5"
                onsubmit="this.querySelector('button').classList.add('opacity-75', 'cursor-not-allowed')">
                <div>
                    <label
                        class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 px-1">Identity</label>
                    <input name="u" type="text" required placeholder="Username or Email"
                        class="input-field w-full bg-slate-900/50 border border-slate-700/50 rounded-xl p-4 text-sm outline-none focus:border-blue-500 transition-all text-white placeholder-slate-600">
                </div>

                <div>
                    <div class="flex justify-between items-center mb-2 px-1">
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest">Key</label>
                        <a href="#" class="text-xs text-blue-400 hover:text-blue-300">Forgot?</a>
                    </div>
                    <input name="p" type="password" required placeholder="Password"
                        class="input-field w-full bg-slate-900/50 border border-slate-700/50 rounded-xl p-4 text-sm outline-none focus:border-blue-500 transition-all text-white placeholder-slate-600">
                </div>

                <div class="pt-2">
                    <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold p-4 rounded-xl transition-all shadow-xl shadow-blue-500/10 hover:shadow-blue-500/20 active:scale-[0.98]">
                        Authenticate
                    </button>
                    <div class="text-center mt-6">
                        <span class="text-xs text-slate-500">Secure Environment v5.0</span>
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>

</html>