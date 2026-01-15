<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        $limits = $pdo->query("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();

        if ($action == 'add_db') {
            $curr = $pdo->query("SELECT COUNT(*) FROM client_databases WHERE client_id = $cid")->fetchColumn();
            if ($curr >= $limits['max_databases'])
                throw new Exception("Plan database limit reached.");

            $db_name = $username . "_" . preg_replace('/[^a-z0-9_]/', '', $_POST['db_name']);
            $domain_id = !empty($_POST['domain_id']) ? (int) $_POST['domain_id'] : "NULL";

            $pdo->prepare("INSERT INTO client_databases (client_id, domain_id, db_name) VALUES (?, ?, ?)")->execute([$cid, $domain_id == "NULL" ? null : $domain_id, $db_name]);
            sendResponse($res);
            cmd("mysql-tool create-db " . escapeshellarg($db_name));
            exit;
        }

        if ($action == 'add_db_user') {
            $db_user = $username . "_" . preg_replace('/[^a-z0-9_]/', '', $_POST['db_user']);
            $pdo->prepare("INSERT INTO client_db_users (client_id, db_user) VALUES (?, ?)")->execute([$cid, $db_user]);
            sendResponse($res);
            cmd("mysql-tool create-user " . escapeshellarg($db_user) . " " . escapeshellarg($_POST['db_pass']) . " " . escapeshellarg($_POST['target_db']));
            exit;
        }

        if ($action == 'delete_db') {
            $db_name = $_POST['db_name'];
            $check = $pdo->prepare("SELECT id FROM client_databases WHERE db_name = ? AND client_id = ?");
            $check->execute([$db_name, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM client_databases WHERE db_name = ?")->execute([$db_name]);
            sendResponse($res);
            cmd("mysql-tool delete-db " . escapeshellarg($db_name));
            exit;
        }

        if ($action == 'reset_db_pass') {
            $db_user = $_POST['db_user'];
            $pass = $_POST['new_pass'];

            // Check ownership
            $check = $pdo->prepare("SELECT id FROM client_db_users WHERE db_user = ? AND client_id = ?");
            $check->execute([$db_user, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            sendResponse($res);
            cmd("mysql-tool reset-pass " . escapeshellarg($db_user) . " " . escapeshellarg($pass));
            exit;
        }

        // Generic Delete for Users? Not in original logic but good to have eventually.

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Data Handling
try {
    $my_dbs = $pdo->query("SELECT cd.*, d.domain FROM client_databases cd LEFT JOIN domains d ON cd.domain_id = d.id WHERE cd.client_id = $cid ORDER BY d.domain DESC")->fetchAll();
} catch (PDOException $e) {
    $my_dbs = $pdo->query("SELECT *, NULL as domain FROM client_databases WHERE client_id = $cid")->fetchAll();
}
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();

// Get Base Domain for Links (phpMyAdmin)
$server_host = $_SERVER['HTTP_HOST'];
$parts = explode('.', $server_host);
if (count($parts) >= 2) {
    $base_domain = implode('.', array_slice($parts, -2));
} else {
    $base_domain = $server_host; // fallback
}

include 'layout/header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-8">
    <!-- CREATE DB FORM -->
    <div class="space-y-8">
        <div>
            <h3 class="font-bold mb-4 text-white">Create Database</h3>
            <form onsubmit="handleGeneric(event, 'add_db')" class="glass-card p-6 space-y-4">
                <div class="flex items-center bg-slate-900/50 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-4 py-4 bg-slate-800 text-slate-400 font-mono text-sm border-r border-slate-700">
                        <?= $username ?>_
                    </div>
                    <input name="db_name" required placeholder="dbname"
                        class="w-full bg-transparent p-4 outline-none text-white placeholder-slate-600">
                </div>
                <select name="domain_id"
                    class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-slate-300">
                    <option value="">Global (No Domain Associated)</option>
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= $d['id'] ?>">Associate with
                            <?= $d['domain'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button
                    class="w-full bg-blue-600 text-white p-4 rounded-xl font-bold hover:bg-blue-500 transition shadow-lg shadow-blue-600/20">Create
                    Database</button>
            </form>
        </div>

        <div>
            <h3 class="font-bold mb-4 text-white">Create Database User</h3>
            <form onsubmit="handleGeneric(event, 'add_db_user')" class="glass-card p-6 space-y-4">
                <div class="flex items-center bg-slate-900/50 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-4 py-4 bg-slate-800 text-slate-400 font-mono text-sm border-r border-slate-700">
                        <?= $username ?>_
                    </div>
                    <input name="db_user" required placeholder="dbuser"
                        class="w-full bg-transparent p-4 outline-none text-white placeholder-slate-600">
                </div>
                <input name="db_pass" type="password" required placeholder="Password"
                    class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-white placeholder-slate-600 transition">
                <select name="target_db"
                    class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-slate-300">
                    <?php foreach ($my_dbs as $db): ?>
                        <option value="<?= $db['db_name'] ?>">Access to:
                            <?= $db['db_name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button
                    class="w-full bg-slate-800 text-white p-4 rounded-xl font-bold hover:bg-slate-700 transition border border-slate-700">Create
                    User</button>
            </form>
        </div>
    </div>

    <!-- DB LIST & USERS -->
    <div class="md:col-span-2 space-y-8">
        <div>
            <h3 class="font-bold mb-4 text-white">Your Databases</h3>
            <div class="glass-card overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400 tracking-widest">
                        <tr>
                            <th class="p-6">Database Name</th>
                            <th class="p-6 text-right">Login / Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_dbs as $db): ?>
                            <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                                <td class="p-6">
                                    <div class="font-bold text-slate-200">
                                        <?= $db['db_name'] ?>
                                    </div>
                                    <?php if ($db['domain']): ?>
                                        <div class="text-xs text-blue-400 flex items-center gap-1 mt-1"><i data-lucide="link"
                                                class="w-3"></i>
                                            <?= $db['domain'] ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-xs text-slate-500 italic mt-1">Global Database</div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-6 text-right">
                                    <a href="http://phpmyadmin.<?= $base_domain ?>" target="_blank"
                                        class="text-blue-400 font-bold text-xs mr-4 uppercase hover:text-blue-300">phpMyAdmin</a>
                                    <button onclick="deleteAction('delete_db', 'db_name', '<?= $db['db_name'] ?>')"
                                        class="text-red-400 hover:bg-red-500/10 p-2 rounded-lg transition"><i
                                            data-lucide="trash-2" class="w-4"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <h3 class="font-bold mb-4 text-white">Database Users</h3>
            <div class="glass-card overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                        <tr>
                            <th class="p-6">User</th>
                            <th class="p-6 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $db_users = $pdo->query("SELECT * FROM client_db_users WHERE client_id = $cid")->fetchAll();
                        foreach ($db_users as $u): ?>
                            <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                                <td class="p-6 font-bold text-slate-300">
                                    <?= $u['db_user'] ?>
                                </td>
                                <td class="p-6 text-right">
                                    <button onclick="resetPassword('reset_db_pass', 'db_user', '<?= $u['db_user'] ?>')"
                                        class="text-orange-400 hover:bg-orange-500/10 p-2 rounded-lg transition mr-2"><i
                                            data-lucide="key" class="w-4 h-4"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    async function deleteAction(action, key, val) {
        if (!confirm("Permanent Action: Are you sure?")) return;
        const fd = new FormData();
        fd.append('ajax_action', action);
        fd.append(key, val);

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Deleted', 'Item deleted successfully.');
                setTimeout(() => forceReload(), 1000);
            } else {
                showToast('error', 'Delete Failed', res.msg || 'Could not delete item.');
            }
        } catch (e) {
            showToast('error', 'Error', 'System error during deletion.');
        }
    }

    async function resetPassword(action, keyName, keyValue) {
        const newPass = prompt("Enter new password for " + keyValue + ":");
        if (!newPass) return;

        const fd = new FormData();
        fd.append('ajax_action', action);
        fd.append(keyName, keyValue);
        fd.append('new_pass', newPass);

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Password Updated', 'The password has been changed successfully.');
            } else {
                showToast('error', 'Update Failed', res.msg);
            }
        } catch (e) {
            showToast('error', 'Error', 'System error during password reset.');
        }
    }
</script>