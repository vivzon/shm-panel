<?php
require_once __DIR__ . '/../shared/config.php';

// 1. Authentication Check
if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

// 2. Security: Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 3. AJAX Action Handler
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        // CSRF Validation
        if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Security token mismatch. Please refresh.");
        }

        // Get Client Limits
        $stmt = $pdo->prepare("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = ?");
        $stmt->execute([$cid]);
        $limits = $stmt->fetch();

        // --- Action: Add Database ---
        if ($action == 'add_db') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_databases WHERE client_id = ?");
            $stmt->execute([$cid]);
            if ($stmt->fetchColumn() >= $limits['max_databases']) throw new Exception("Plan database limit reached.");

            $db_suffix = preg_replace('/[^a-z0-9_]/', '', $_POST['db_name']);
            $db_name = $username . "_" . $db_suffix;
            $domain_id = !empty($_POST['domain_id']) ? (int) $_POST['domain_id'] : null;

            $pdo->prepare("INSERT INTO client_databases (client_id, domain_id, db_name) VALUES (?, ?, ?)")->execute([$cid, $domain_id, $db_name]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name`");
            } else {
                cmd("mysql-tool create-db " . escapeshellarg($db_name));
            }
            echo json_encode($res); exit;
        }

        // --- Action: Delete Database ---
        if ($action == 'delete_db') {
            $db_name = $_POST['db_name'];
            $check = $pdo->prepare("SELECT id FROM client_databases WHERE db_name = ? AND client_id = ?");
            $check->execute([$db_name, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM client_databases WHERE db_name = ?")->execute([$db_name]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pdo->exec("DROP DATABASE IF EXISTS `$db_name`");
            } else {
                cmd("mysql-tool delete-db " . escapeshellarg($db_name));
            }
            echo json_encode($res); exit;
        }

        // --- Action: Add Database User ---
        if ($action == 'add_db_user') {
            $user_suffix = preg_replace('/[^a-z0-9_]/', '', $_POST['db_user']);
            $db_user = $username . "_" . $user_suffix;
            $pass = $_POST['db_pass'];
            $target_db = $_POST['target_db'];

            $pdo->prepare("INSERT INTO client_db_users (client_id, db_user) VALUES (?, ?)")->execute([$cid, $db_user]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $quoted_pass = $pdo->quote($pass);
                $pdo->exec("CREATE USER IF NOT EXISTS '$db_user'@'localhost' IDENTIFIED BY $quoted_pass");
                $pdo->exec("GRANT ALL PRIVILEGES ON `$target_db`.* TO '$db_user'@'localhost'");
                $pdo->exec("FLUSH PRIVILEGES");
            } else {
                cmd("mysql-tool create-user " . escapeshellarg($db_user) . " " . escapeshellarg($pass) . " " . escapeshellarg($target_db));
            }
            echo json_encode($res); exit;
        }

        // --- Action: Delete Database User ---
        if ($action == 'delete_db_user') {
            $db_user = $_POST['db_user'];
            $check = $pdo->prepare("SELECT id FROM client_db_users WHERE db_user = ? AND client_id = ?");
            $check->execute([$db_user, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM client_db_users WHERE db_user = ?")->execute([$db_user]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pdo->exec("DROP USER IF EXISTS '$db_user'@'localhost'");
            } else {
                cmd("mysql-tool delete-user " . escapeshellarg($db_user));
            }
            echo json_encode($res); exit;
        }

        // --- Action: Reset Password ---
        if ($action == 'reset_db_pass') {
            $db_user = $_POST['db_user'];
            $pass = $_POST['new_pass'];

            $check = $pdo->prepare("SELECT id FROM client_db_users WHERE db_user = ? AND client_id = ?");
            $check->execute([$db_user, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $quoted_pass = $pdo->quote($pass);
                $pdo->exec("ALTER USER '$db_user'@'localhost' IDENTIFIED BY $quoted_pass");
                $pdo->exec("FLUSH PRIVILEGES");
            } else {
                cmd("mysql-tool reset-pass " . escapeshellarg($db_user) . " " . escapeshellarg($pass));
            }
            echo json_encode($res); exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// 4. Data Retrieval & Pagination
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$total_dbs = $pdo->query("SELECT COUNT(*) FROM client_databases WHERE client_id = $cid")->fetchColumn();
$total_pages = ceil($total_dbs / $per_page);

$my_dbs = $pdo->query("SELECT cd.*, d.domain FROM client_databases cd LEFT JOIN domains d ON cd.domain_id = d.id WHERE cd.client_id = $cid ORDER BY cd.id DESC LIMIT $per_page OFFSET $offset")->fetchAll();
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();

$base_domain = implode('.', array_slice(explode('.', $_SERVER['HTTP_HOST']), -2));

include 'layout/header.php';
?>

<style>
    .btn-loading { pointer-events: none; opacity: 0.6; }
</style>

<div class="grid grid-cols-1 md:grid-cols-3 gap-8">
    <!-- LEFT SIDE: FORMS -->
    <div class="space-y-8">
        <!-- CREATE DATABASE -->
        <div>
            <h3 class="font-bold mb-4 text-white">Create Database</h3>
            <form onsubmit="handleSubmit(event, 'add_db')" class="glass-card p-6 space-y-4">
                <div class="flex items-center bg-slate-900/50 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-4 py-4 bg-slate-800 text-slate-400 font-mono text-sm border-r border-slate-700"><?= htmlspecialchars($username) ?>_</div>
                    <input name="db_name" required placeholder="dbname" class="w-full bg-transparent p-4 outline-none text-white placeholder-slate-600">
                </div>
                <select name="domain_id" class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-slate-300">
                    <option value="">Global (No Domain)</option>
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['domain']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full bg-blue-600 text-white p-4 rounded-xl font-bold hover:bg-blue-500 transition">Create Database</button>
            </form>
        </div>

        <!-- CREATE USER -->
        <div>
            <h3 class="font-bold mb-4 text-white">Create User</h3>
            <form onsubmit="handleSubmit(event, 'add_db_user')" class="glass-card p-6 space-y-4">
                <div class="flex items-center bg-slate-900/50 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-4 py-4 bg-slate-800 text-slate-400 font-mono text-sm border-r border-slate-700"><?= htmlspecialchars($username) ?>_</div>
                    <input name="db_user" required placeholder="dbuser" class="w-full bg-transparent p-4 outline-none text-white placeholder-slate-600">
                </div>
                <input name="db_pass" type="password" required placeholder="Password" class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-white">
                <select name="target_db" class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-slate-300">
                    <?php foreach ($my_dbs as $db): ?>
                        <option value="<?= $db['db_name'] ?>"><?= $db['db_name'] ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full bg-slate-800 text-white p-4 rounded-xl font-bold border border-slate-700">Create User</button>
            </form>
        </div>
    </div>

    <!-- RIGHT SIDE: TABLES -->
    <div class="md:col-span-2 space-y-8">
        <!-- DATABASE LIST -->
        <div>
            <h3 class="font-bold mb-4 text-white">Your Databases</h3>
            <div class="glass-card overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                        <tr><th class="p-6">Name</th><th class="p-6 text-right">Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_dbs as $db): ?>
                            <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                                <td class="p-6">
                                    <div class="font-bold text-slate-200"><?= htmlspecialchars($db['db_name']) ?></div>
                                    <div class="text-xs text-blue-400"><?= $db['domain'] ? htmlspecialchars($db['domain']) : 'Global' ?></div>
                                </td>
                                <td class="p-6 text-right">
                                    <a href="http://phpmyadmin.<?= $base_domain ?>" target="_blank" class="text-xs font-bold text-blue-400 mr-4 uppercase">Login</a>
                                    <button onclick="handleDeleteAction('delete_db', 'db_name', '<?= $db['db_name'] ?>', this)" class="text-red-400 p-2"><i data-lucide="trash-2" class="w-4"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- USER LIST -->
        <div>
            <h3 class="font-bold mb-4 text-white">Database Users</h3>
            <div class="glass-card overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                        <tr><th class="p-6">Username</th><th class="p-6 text-right">Action</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $db_users = $pdo->prepare("SELECT * FROM client_db_users WHERE client_id = ?");
                        $db_users->execute([$cid]);
                        foreach ($db_users->fetchAll() as $u): ?>
                            <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                                <td class="p-6 font-bold text-slate-300"><?= htmlspecialchars($u['db_user']) ?></td>
                                <td class="p-6 text-right flex justify-end gap-2">
                                    <button onclick="handleResetPass('<?= $u['db_user'] ?>', this)" class="text-orange-400 p-2" title="Reset Password"><i data-lucide="key" class="w-4"></i></button>
                                    <button onclick="handleDeleteAction('delete_db_user', 'db_user', '<?= $u['db_user'] ?>', this)" class="text-red-400 p-2" title="Delete User"><i data-lucide="trash-2" class="w-4"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Global form submission handler
 */
async function handleSubmit(e, action) {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    const originalText = btn.innerText;

    btn.disabled = true;
    btn.classList.add('btn-loading');
    btn.innerText = 'Processing...';

    const fd = new FormData(e.target);
    fd.append('ajax_action', action);
    fd.append('token', '<?= $_SESSION['csrf_token'] ?>');

    try {
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        if (res.status === 'success') {
            showToast('success', res.msg);
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('error', res.msg);
            btn.disabled = false;
            btn.classList.remove('btn-loading');
            btn.innerText = originalText;
        }
    } catch (e) { showToast('error', 'Server error occurred'); }
}

/**
 * Handle Delete for both DB and User
 */
async function handleDeleteAction(action, key, val, btn) {
    if (!confirm(`Are you sure you want to delete ${val}?`)) return;

    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" class="w-4 animate-spin"></i>';
    if(window.lucide) lucide.createIcons();

    const fd = new FormData();
    fd.append('ajax_action', action);
    fd.append(key, val);
    fd.append('token', '<?= $_SESSION['csrf_token'] ?>');

    try {
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        if (res.status === 'success') {
            showToast('success', 'Deleted successfully');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('error', res.msg);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            if(window.lucide) lucide.createIcons();
        }
    } catch (e) { showToast('error', 'Connection error'); }
}

/**
 * Handle Password Reset
 */
async function handleResetPass(user, btn) {
    const newPass = prompt(`Enter new password for ${user}:`);
    if (!newPass) return;

    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader-2" class="w-4 animate-spin"></i>';
    if(window.lucide) lucide.createIcons();

    const fd = new FormData();
    fd.append('ajax_action', 'reset_db_pass');
    fd.append('db_user', user);
    fd.append('new_pass', newPass);
    fd.append('token', '<?= $_SESSION['csrf_token'] ?>');

    try {
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast(res.status, res.msg);
    } catch (e) { showToast('error', 'Reset failed'); }

    btn.innerHTML = originalHtml;
    if(window.lucide) lucide.createIcons();
}
</script>

<?php include 'layout/footer.php'; ?>