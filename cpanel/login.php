<?php
/**
 * VIVZON CPANEL - Login Page (Production v1.1)
 */

// 1. Load config
require_once '../shared/config.php';

// 2. Redirect if already logged in
if (isset($_SESSION['client'])) {
    header("Location: index.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize input
    $u = trim($_POST['u'] ?? '');
    $p = $_POST['p'] ?? '';

    if (!empty($u) && !empty($p)) {
        try {
            // 3. Query the database including 'status' check
            $stmt = $pdo->prepare("SELECT id, username, password, status FROM clients WHERE username = ? OR email = ?");
            $stmt->execute([$u, $u]); // Allowing login via username or email
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // 4. Verify Account Existence and Password Hash
            if ($user) {
                // Check if account is suspended
                if ($user['status'] === 'suspended') {
                    $error = "This account has been suspended. Please contact support.";
                }
                // Verify the BCRYPT hash (Matches the hashes in your SQL dump)
                else if (password_verify($p, $user['password'])) {
                    // Regenerate session ID for security
                    session_regenerate_id(true);

                    $_SESSION['client'] = $user['username'];
                    $_SESSION['cid'] = $user['id'];

                    header("Location: index.php");
                    exit;
                } else {
                    $error = "Invalid username or password.";
                }
            } else {
                $error = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            $error = "System Error. Please try again later.";
            // For debugging: $error = $e->getMessage();
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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
        }

        .glass-card {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }
    </style>
</head>

<body class="bg-[#0f172a] flex items-center justify-center min-h-screen p-4 overflow-hidden relative">

    <!-- Background Glow -->
    <div
        class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-blue-600/20 blur-[120px] rounded-full pointer-events-none">
    </div>
    <div
        class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-purple-600/20 blur-[120px] rounded-full pointer-events-none">
    </div>

    <div class="w-full max-w-md relative z-10">
        <!-- Logo Area -->
        <div class="text-center mb-8">
            <div
                class="inline-flex items-center justify-center w-20 h-20 bg-blue-600 rounded-3xl shadow-[0_0_40px_rgba(37,99,235,0.3)] mb-6 text-white transform rotate-3 hover:rotate-6 transition duration-500">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-white tracking-tight mb-2">Welcome Back</h1>
            <p class="text-slate-400 font-medium">Sign in to manage your hosting</p>
        </div>

        <!-- Form Area -->
        <div class="glass-card p-10 rounded-[2.5rem] shadow-2xl relative overflow-hidden group">

            <!-- Hover sheen effect -->
            <div
                class="absolute inset-0 bg-gradient-to-tr from-white/5 to-transparent opacity-0 group-hover:opacity-100 transition duration-1000 pointer-events-none">
            </div>

            <?php if ($error): ?>
                <div
                    class="bg-red-500/10 border border-red-500/20 text-red-400 p-4 rounded-2xl mb-8 text-sm font-semibold flex items-center gap-3 animate-pulse">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 ml-1">Username
                        or Email</label>
                    <div class="relative group/input">
                        <div
                            class="absolute left-4 top-4 text-slate-500 group-focus-within/input:text-blue-500 transition">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                        </div>
                        <input name="u" type="text" required autofocus
                            class="w-full p-4 pl-12 bg-slate-900/50 border border-slate-700 rounded-2xl outline-none focus:border-blue-500 focus:bg-slate-900/80 text-white transition-all placeholder:text-slate-600 shadow-inner"
                            placeholder="e.g. vivzon_user">
                    </div>
                </div>

                <div>
                    <label
                        class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-3 ml-1">Password</label>
                    <div class="relative group/input">
                        <div
                            class="absolute left-4 top-4 text-slate-500 group-focus-within/input:text-blue-500 transition">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                            </svg>
                        </div>
                        <input name="p" type="password" required
                            class="w-full p-4 pl-12 bg-slate-900/50 border border-slate-700 rounded-2xl outline-none focus:border-blue-500 focus:bg-slate-900/80 text-white transition-all placeholder:text-slate-600 shadow-inner"
                            placeholder="••••••••">
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit"
                        class="w-full p-4 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-2xl shadow-lg shadow-blue-600/30 transition-all hover:scale-[1.02] active:scale-[0.98] border border-transparent hover:border-blue-400">
                        Sign In to Dashboard
                    </button>
                    <div class="text-center mt-6">
                        <a href="#" class="text-sm text-slate-500 hover:text-white transition">Forgot Password?</a>
                    </div>
                </div>
            </form>
        </div>

        <p class="text-center mt-12 text-slate-500 text-sm">
            &copy; <?php echo date('Y'); ?> Vivzon Cloud Services.
        </p>
    </div>

</body>

</html>