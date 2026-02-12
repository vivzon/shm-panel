<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        $limits = $pdo->query("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();

        if ($action == 'add_email') {
            $curr = $pdo->query("SELECT COUNT(*) FROM mail_users WHERE domain_id IN (SELECT id FROM mail_domains WHERE domain IN (SELECT domain FROM domains WHERE client_id = $cid))")->fetchColumn();
            if ($curr >= $limits['max_emails'])
                throw new Exception("Email limit reached.");
            $did = $pdo->query("SELECT id FROM mail_domains WHERE domain = '{$_POST['domain']}'")->fetchColumn();
            if (!$did) {
                // Should exist if domain exists, but just in case
                $pdo->prepare("INSERT INTO mail_domains (domain) VALUES (?)")->execute([$_POST['domain']]);
                $did = $pdo->lastInsertId();
            }
            $pdo->prepare("INSERT INTO mail_users (domain_id, email, password) VALUES (?, ?, ?)")->execute([$did, $_POST['user'] . "@" . $_POST['domain'], password_hash($_POST['pass'], PASSWORD_BCRYPT)]);
            sendResponse($res);
            exit;
        }

        if ($action == 'delete_email') {
            $email = $_POST['email'];
            $check = $pdo->prepare("SELECT m.id FROM mail_users m JOIN mail_domains md ON m.domain_id = md.id JOIN domains d ON md.domain = d.domain WHERE m.email = ? AND d.client_id = ?");
            $check->execute([$email, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM mail_users WHERE email = ?")->execute([$email]);
            sendResponse($res);
            exit;
        }

        if ($action == 'reset_mail_pass') {
            $email = $_POST['email'];
            $pass = $_POST['new_pass'];

            // Check ownership
            $check = $pdo->prepare("SELECT m.id FROM mail_users m JOIN mail_domains md ON m.domain_id = md.id JOIN domains d ON md.domain = d.domain WHERE m.email = ? AND d.client_id = ?");
            $check->execute([$email, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("UPDATE mail_users SET password = ? WHERE email = ?")->execute([password_hash($pass, PASSWORD_BCRYPT), $email]);
            sendResponse($res);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Data Handling
// Pagination
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)
    $page = 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Count Total
$total_emails = $pdo->query("SELECT COUNT(*) FROM mail_users mu JOIN mail_domains md ON mu.domain_id = md.id WHERE md.domain IN (SELECT domain FROM domains WHERE client_id = $cid)")->fetchColumn();
$total_pages = ceil($total_emails / $per_page);

$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();
$my_emails = $pdo->query("SELECT mu.* FROM mail_users mu JOIN mail_domains md ON mu.domain_id = md.id WHERE md.domain IN (SELECT domain FROM domains WHERE client_id = $cid) LIMIT $per_page OFFSET $offset")->fetchAll();

// Base Domain for Webmail Link
$server_host = $_SERVER['HTTP_HOST'];
$parts = explode('.', $server_host);
if (count($parts) >= 2) {
    $base_domain = implode('.', array_slice($parts, -2));
} else {
    $base_domain = $server_host;
}

include 'layout/header.php';
?>

<div class="space-y-10">
    <!-- CREATE EMAIL -->
    <div class="glass-card p-10">
        <h2 class="text-2xl font-bold mb-8 text-white">Create Email Account</h2>
        <form onsubmit="handleGeneric(event, 'add_email')" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <input name="user" required placeholder="mailbox name"
                class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-white placeholder-slate-600 transition">
            <select name="domain"
                class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-slate-300">
                <?php foreach ($domains as $d): ?>
                    <option value="<?= $d['domain'] ?>">@
                        <?= $d['domain'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input name="pass" type="password" required placeholder="Password"
                class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-white placeholder-slate-600 transition">
            <button
                class="bg-blue-600 text-white rounded-xl font-bold shadow-lg shadow-blue-600/20 hover:bg-blue-500 transition">Create
                Mailbox</button>
        </form>
    </div>

    <!-- LIST -->
    <div class="glass-card overflow-hidden">
        <table class="w-full text-left">
            <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                <tr>
                    <th class="p-6">Active Email Account</th>
                    <th class="p-6 text-right">Webmail / Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($my_emails as $mail): ?>
                    <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                        <td class="p-6 font-bold text-slate-300">
                            <?= $mail['email'] ?>
                        </td>
                        <td class="p-6 text-right">
                            <a href="http://webmail.<?= $base_domain ?>" target="_blank"
                                class="text-blue-400 font-bold text-xs mr-4 uppercase tracking-tighter hover:text-blue-300">Login</a>
                            <button onclick="resetPassword('reset_mail_pass', 'email', '<?= $mail['email'] ?>')"
                                class="text-orange-400 hover:bg-orange-500/10 p-2 rounded-lg transition mr-2"><i
                                    data-lucide="key" class="w-4 h-4"></i></button>
                            <button onclick="deleteAction('delete_email', 'email', '<?= $mail['email'] ?>')"
                                class="text-red-400 hover:bg-red-500/10 p-2 rounded-lg transition"><i data-lucide="trash-2"
                                    class="w-4"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="flex justify-between items-center mt-6">
            <div class="text-xs text-slate-500 font-bold">
                Page <?= $page ?> of <?= $total_pages ?>
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>"
                        class="bg-slate-800 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-slate-700 transition">Previous</a>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>"
                        class="bg-slate-800 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-slate-700 transition">Next</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
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
                showToast('error', 'Delete Failed', res.msg);
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