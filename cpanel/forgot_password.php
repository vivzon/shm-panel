<?php
/**
 * VIVZON CPANEL - FORGOT PASSWORD
 * Glassmorphism Design
 */
require_once __DIR__ . '/../shared/config.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['u']);
    if (!$input) {
        $error = "Please enter your username or email.";
    } else {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT id, email, username FROM clients WHERE username = ? OR email = ?");
            $stmt->execute([$input, $input]);
            $user = $stmt->fetch();

            // Always show success message for security (don't reveal user existence)
            // In a real app, we would send the email here.
            $success = "If an account exists for '<strong>" . htmlspecialchars($input) . "</strong>', a password reset link has been sent to the registered email address.";

            // Log the request (Simulation)
            if ($user && function_exists('error_log')) {
                error_log("Password reset requested for user: " . $user['username']);
            }

        } catch (PDOException $e) {
            $error = "System error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Vivzon CPanel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&family=Lexend:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            overflow: hidden;
        }

        .font-heading {
            font-family: 'Lexend', sans-serif;
        }

        .glass-panel {
            background: rgba(30, 41, 59, 0.4);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .input-field {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .input-field:focus {
            background: rgba(15, 23, 42, 0.8);
            border-color: #3b82f6;
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.2);
        }

        .input-group label {
            display: block;
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
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
                <h1 class="text-2xl font-bold text-white font-heading tracking-tight mb-2">Reset Password</h1>
                <p class="text-slate-400 text-sm">Enter your credentials to recover access</p>
            </div>

            <?php if ($error): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-bold flex items-center gap-3">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div
                    class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm flex items-start gap-3">
                    <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <?= $success ?>
                    </div>
                </div>
                <button onclick="window.location.href='login.php'"
                    class="w-full bg-slate-700 hover:bg-slate-600 text-white font-bold py-3.5 rounded-xl transition shadow-lg">
                    Return to Login
                </button>
            <?php else: ?>

                <form method="POST" class="space-y-6">
                    <div class="input-group">
                        <label for="u">Username or Email</label>
                        <input id="u" name="u" type="text" required placeholder="Enter username or email"
                            class="input-field w-full rounded-xl px-4 py-3.5 text-sm text-white outline-none focus:ring-2 focus:ring-blue-500/50">
                    </div>

                    <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 rounded-xl shadow-lg shadow-blue-600/20 hover:shadow-blue-600/30 transition-all transform hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center gap-2">
                        <span>Send Reset Link</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                            </path>
                        </svg>
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <a href="login.php"
                        class="text-sm text-slate-400 hover:text-white transition flex items-center justify-center gap-2 group">
                        <svg class="w-4 h-4 group-hover:-translate-x-1 transition" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back to Login
                    </a>
                </div>

            <?php endif; ?>

        </div>
    </div>

</body>

</html>