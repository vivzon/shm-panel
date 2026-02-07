<?php
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/AuthHelper.php';

if (!isset($_SESSION['2fa_pending_uid'])) {
    header("Location: login.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'] ?? '';
    $uid = $_SESSION['2fa_pending_uid'];

    // Fetch Secret
    $stmt = $pdo->prepare("SELECT two_fa_secret, username FROM clients WHERE id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if ($user) {
        $auth = new AuthHelper($pdo);
        if ($auth->verifyTOTP($user['two_fa_secret'], $code)) {
            // Success
            $_SESSION['client'] = $user['username'];
            $_SESSION['cid'] = $uid;
            unset($_SESSION['2fa_pending_uid']);
            session_regenerate_id(true);
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid Authentication Code";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Verification | SHM Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: white;
        }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md p-6">
        <div class="bg-slate-800/50 border border-slate-700 p-8 rounded-2xl shadow-2xl backdrop-blur-xl">
            <div class="text-center mb-8">
                <div
                    class="bg-blue-500/10 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-blue-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>
                <h1 class="text-2xl font-bold mb-2">Two-Factor Auth</h1>
                <p class="text-slate-400 text-sm">Enter the code from your Authenticator App</p>
            </div>

            <?php if ($error): ?>
                <div
                    class="bg-red-500/10 text-red-400 p-3 rounded-lg text-center font-bold text-sm mb-6 border border-red-500/20">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div>
                    <input type="text" name="code"
                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-4 text-center text-2xl tracking-[0.5em] font-mono outline-none focus:border-blue-500 transition placeholder-slate-600"
                        placeholder="000000" maxlength="6" autofocus required>
                </div>
                <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-500 py-3.5 rounded-xl font-bold shadow-lg shadow-blue-600/20 transition transform hover:-translate-y-0.5">Verify</button>
            </form>

            <div class="text-center mt-6">
                <a href="login.php" class="text-sm text-slate-500 hover:text-white transition">Back to Login</a>
            </div>
        </div>
    </div>
</body>

</html>