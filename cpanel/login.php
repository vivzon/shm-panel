<?php 
/**
 * VIVZON CPANEL - Login Page (Production v1.1)
 */

// 1. Load config
require_once '../shared/config.php';

// 2. Redirect if already logged in
if(isset($_SESSION['client'])) { 
    header("Location: index.php"); 
    exit; 
}

$error = null;

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize input
    $u = trim($_POST['u'] ?? ''); 
    $p = $_POST['p'] ?? '';
    
    if(!empty($u) && !empty($p)) {
        try {
            // 3. Query the database including 'status' check
            $stmt = $pdo->prepare("SELECT id, username, password, status FROM clients WHERE username = ? OR email = ?");
            $stmt->execute([$u, $u]); // Allowing login via username or email
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 4. Verify Account Existence and Password Hash
            if($user) {
                // Check if account is suspended
                if($user['status'] === 'suspended') {
                    $error = "This account has been suspended. Please contact support.";
                } 
                // Verify the BCRYPT hash (Matches the hashes in your SQL dump)
                else if(password_verify($p, $user['password'])) {
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
        body { font-family: 'Outfit', sans-serif; }
        .glass-effect { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
    </style>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-md">
        <!-- Logo Area -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-2xl shadow-xl shadow-blue-200 mb-4 text-white">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
            </div>
            <h1 class="text-3xl font-bold text-slate-800 tracking-tight">CPanel Login</h1>
            <p class="text-slate-500 font-medium">Vivzon Cloud Hosting</p>
        </div>

        <!-- Form Area -->
        <div class="glass-effect p-8 rounded-[2.5rem] shadow-2xl shadow-slate-200 border border-slate-100">
            <?php if($error): ?>
                <div class="bg-red-50 border border-red-100 text-red-600 p-4 rounded-2xl mb-6 text-sm font-semibold flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <div>
                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2 ml-1">Username or Email</label>
                    <input name="u" type="text" required autofocus
                        class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl outline-none focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all placeholder:text-slate-300" 
                        placeholder="e.g. vivzon_user">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2 ml-1">Password</label>
                    <input name="p" type="password" required
                        class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl outline-none focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all placeholder:text-slate-300" 
                        placeholder="••••••••">
                </div>

                <div class="pt-2">
                    <button type="submit" 
                        class="w-full p-4 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-2xl shadow-lg shadow-blue-600/30 transition-all active:scale-[0.98]">
                        Sign In to Dashboard
                    </button>
                </div>
            </form>
        </div>

        <p class="text-center mt-8 text-slate-400 text-sm">
            &copy; <?php echo date('Y'); ?> Vivzon Cloud Services.
        </p>
    </div>

</body>
</html>
