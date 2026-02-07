<?php
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/AuthHelper.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}

$cid = $_SESSION['cid'];
$auth = new AuthHelper($pdo);
$error = null;
$success = null;

// Get current 2FA status
$stmt = $pdo->prepare("SELECT two_fa_secret FROM clients WHERE id = ?");
$stmt->execute([$cid]);
$user = $stmt->fetch();
$is_2fa_enabled = !empty($user['two_fa_secret']);

// Handle Post Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['enable_2fa'])) {
        // 1. Generate Secret
        if (!$is_2fa_enabled) {
            $secret = $auth->generateSecret();
            $_SESSION['temp_2fa_secret'] = $secret;
        }
    } elseif (isset($_POST['confirm_2fa'])) {
        // 2. Verify Code to Enable
        $code = $_POST['code'] ?? '';
        $secret = $_SESSION['temp_2fa_secret'] ?? '';

        if ($auth->verifyTOTP($secret, $code)) {
            $stmt = $pdo->prepare("UPDATE clients SET two_fa_secret = ? WHERE id = ?");
            $stmt->execute([$secret, $cid]);
            $is_2fa_enabled = true;
            $success = "Two-Factor Authentication Enabled!";
            unset($_SESSION['temp_2fa_secret']);
        } else {
            $error = "Invalid Code. Please scan the QR code and try again.";
        }
    } elseif (isset($_POST['disable_2fa'])) {
        // 3. Disable 2FA
        $stmt = $pdo->prepare("UPDATE clients SET two_fa_secret = NULL WHERE id = ?");
        $stmt->execute([$cid]);
        $is_2fa_enabled = false;
        $success = "Two-Factor Authentication Disabled.";
    }
}

include 'layout/header.php';
?>

<div class="space-y-6">
    <div class="flex justify-between items-center border-b border-white/5 pb-6">
        <div>
            <h2 class="text-3xl font-bold text-white font-heading tracking-tight mb-2">Security Settings</h2>
            <p class="text-slate-400">Manage your account security and authentication methods.</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 font-bold">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 font-bold">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <div class="glass-card p-8 max-w-2xl">
        <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-3">
            <div class="p-2 bg-blue-500/10 rounded-lg text-blue-400"><i data-lucide="shield-check" class="w-6 h-6"></i>
            </div>
            Two-Factor Authentication (2FA)
        </h3>

        <?php if ($is_2fa_enabled): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/20 p-6 rounded-xl mb-6">
                <div class="flex items-center gap-4 text-emerald-400 font-bold mb-2">
                    <i data-lucide="check-circle" class="w-6 h-6"></i>
                    Status: ENABLED
                </div>
                <p class="text-slate-400 text-sm">Your account is secured with 2FA. You will be asked for a code when
                    logging in.</p>
            </div>

            <form method="POST">
                <button type="submit" name="disable_2fa"
                    class="px-6 py-3 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 rounded-xl font-bold transition flex items-center gap-2">
                    <i data-lucide="shield-off" class="w-4 h-4"></i> Disable 2FA
                </button>
            </form>

        <?php elseif (isset($_SESSION['temp_2fa_secret'])): ?>
            <!-- Setup Step 2: Scan QR & Confirm -->
            <?php
            $secret = $_SESSION['temp_2fa_secret'];
            $app_name = "SHM Panel (" . $_SESSION['client'] . ")";
            $qr_url = "https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=otpauth://totp/" . urlencode($app_name) . "?secret=" . $secret . "&issuer=SHMPanel";
            ?>

            <div class="grid md:grid-cols-2 gap-8">
                <div class="bg-white p-4 rounded-xl inline-block w-fit">
                    <img src="<?= $qr_url ?>" alt="QR Code">
                </div>
                <div class="space-y-4">
                    <div>
                        <h4 class="text-white font-bold mb-2">1. Scan QR Code</h4>
                        <p class="text-slate-400 text-sm">Open Google Authenticator or Authy App and scan this QR code.</p>
                    </div>
                    <div>
                        <h4 class="text-white font-bold mb-2">2. Enter Code</h4>
                        <p class="text-slate-400 text-sm mb-3">Enter the 6-digit code from the app to verify.</p>

                        <form method="POST" class="flex gap-3">
                            <input type="text" name="code"
                                class="bg-slate-900 border border-slate-700 text-white rounded-xl px-4 py-3 w-32 text-center tracking-widest font-mono text-lg outline-none focus:border-blue-500"
                                placeholder="000000" maxlength="6" required>
                            <button type="submit" name="confirm_2fa"
                                class="px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-bold shadow-lg shadow-blue-600/20 transition">
                                Verify & Enable
                            </button>
                        </form>
                    </div>

                    <div class="pt-4 border-t border-white/5">
                        <p class="text-xs text-slate-500">Manual Key: <code
                                class="bg-slate-900 px-2 py-1 rounded text-slate-300 select-all"><?= $secret ?></code></p>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Setup Step 1: Start -->
            <p class="text-slate-400 mb-6">Add an extra layer of security to your account by requiring a code from your
                phone when logging in.</p>
            <form method="POST">
                <button type="submit" name="enable_2fa"
                    class="px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-bold shadow-lg shadow-blue-600/20 transition flex items-center gap-2">
                    <i data-lucide="shield" class="w-4 h-4"></i> Setup 2FA
                </button>
            </form>
        <?php endif; ?>

    </div>
</div>

<?php include 'layout/footer.php'; ?>