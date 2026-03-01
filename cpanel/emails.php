<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    // CSRF Protection
    try {
        verify_csrf();
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }

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
                $pdo->prepare("INSERT INTO mail_domains (client_id, domain) VALUES (?, ?)")->execute([$cid, $_POST['domain']]);
                $did = $pdo->lastInsertId();
            }
            $pdo->prepare("INSERT INTO mail_users (client_id, domain_id, email, password) VALUES (?, ?, ?, ?)")->execute([$cid, $did, $_POST['user'] . "@" . $_POST['domain'], password_hash($_POST['pass'], PASSWORD_BCRYPT)]);
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

<div style="display: flex; flex-direction: column; gap: 2.5rem;">
    <!-- CREATE EMAIL -->
    <div class="glass-card" style="padding: 2.5rem;">
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--slate-900); margin-bottom: 2rem;">Create Email
            Account</h2>
        <form onsubmit="handleGeneric(event, 'add_email')"
            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <?= csrf_field() ?>
            <input name="user" required placeholder="mailbox name" class="form-input">
            <select name="domain" class="form-select">
                <?php foreach ($domains as $d): ?>
                    <option value="<?= $d['domain'] ?>">@
                        <?= $d['domain'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input name="pass" type="password" required placeholder="Password" class="form-input">
            <button class="btn btn-primary"
                style="box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2), 0 4px 6px -4px rgba(37, 99, 235, 0.1);">Create
                Mailbox</button>
        </form>
    </div>

    <!-- LIST -->
    <div class="glass-card" style="overflow-x: auto;">
        <table class="table" style="width: 100%;">
            <thead>
                <tr>
                    <th>Active Email Account</th>
                    <th style="text-align: right;">Webmail / Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($my_emails as $mail): ?>
                    <tr>
                        <td style="font-weight: 700; color: var(--slate-700);">
                            <?= $mail['email'] ?>
                        </td>
                        <td style="text-align: right;">
                            <a href="http://webmail.<?= $base_domain ?>" target="_blank"
                                style="color: var(--primary); font-weight: 700; font-size: 0.75rem; margin-right: 1rem; text-transform: uppercase; letter-spacing: -0.05em; text-decoration: none; transition: color 0.2s;"
                                onmouseover="this.style.color='#93c5fd'"
                                onmouseout="this.style.color='var(--primary)'">Login</a>
                            <button onclick="resetPassword('reset_mail_pass', 'email', '<?= $mail['email'] ?>')"
                                style="color: var(--accent-orange); background: transparent; border: none; padding: 0.5rem; border-radius: var(--radius-md); cursor: pointer; transition: background 0.2s; margin-right: 0.5rem;"
                                onmouseover="this.style.backgroundColor='rgba(249, 115, 22, 0.1)'"
                                onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="key"
                                    style="width: 16px; height: 16px;"></i></button>
                            <button onclick="deleteAction('delete_email', 'email', '<?= $mail['email'] ?>')"
                                style="color: var(--accent-red); background: transparent; border: none; padding: 0.5rem; border-radius: var(--radius-md); cursor: pointer; transition: background 0.2s;"
                                onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'"
                                onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="trash-2"
                                    style="width: 16px; height: 16px;"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem;">
            <div style="font-size: 0.75rem; color: var(--slate-700); font-weight: 700;">
                Page <?= $page ?> of <?= $total_pages ?>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="btn btn-secondary" style="font-size: 0.75rem;">Previous</a>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="btn btn-secondary" style="font-size: 0.75rem;">Next</a>
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