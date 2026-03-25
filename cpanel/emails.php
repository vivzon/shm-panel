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
            
            $client_user = $pdo->query("SELECT username FROM clients WHERE id = $cid")->fetchColumn();
            $email = $_POST['user'] . "@" . $_POST['domain'];
            
            cmd("mail-tool add-user " . escapeshellarg($client_user) . " " . escapeshellarg($email) . " " . escapeshellarg($_POST['pass']));
            
            sendResponse($res);
            exit;
        }

        if ($action == 'delete_email') {
            $email = $_POST['email'];
            $check = $pdo->prepare("SELECT m.id FROM mail_users m JOIN mail_domains md ON m.domain_id = md.id JOIN domains d ON md.domain = d.domain WHERE m.email = ? AND d.client_id = ?");
            $check->execute([$email, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            cmd("mail-tool delete-user " . escapeshellarg($email));
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

            cmd("mail-tool reset-pass " . escapeshellarg($email) . " " . escapeshellarg($pass));
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
        <h2
            style="font-size: 1.5rem; font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); margin-bottom: 2rem;">
            Create Email
            Account</h2>
        <form onsubmit="handleGeneric(event, 'add_email')"
            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <?= csrf_field() ?>
            <input name="user" required placeholder="mailbox name" class="form-input"
                style="padding: 0.75rem 1rem; border-radius: var(--radius-lg); border: 1px solid rgba(255, 255, 255, 0.4); background: rgba(255, 255, 255, 0.5); font-size: 0.875rem; transition: all 0.2s; outline: none; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"
                onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                onblur="this.style.borderColor='rgba(255, 255, 255, 0.4)'; this.style.boxShadow='inset 0 2px 4px rgba(0,0,0,0.02)';">
            <select name="domain" class="form-select"
                style="padding: 0.75rem 1rem; border-radius: var(--radius-lg); border: 1px solid rgba(255, 255, 255, 0.4); background: rgba(255, 255, 255, 0.5); color: var(--slate-900); font-size: 0.875rem; transition: all 0.2s; outline: none;"
                onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                onblur="this.style.borderColor='rgba(255, 255, 255, 0.4)'; this.style.boxShadow='none';">
                <?php foreach ($domains as $d): ?>
                    <option value="<?= $d['domain'] ?>">@
                        <?= $d['domain'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input name="pass" type="password" required placeholder="Password" class="form-input"
                style="padding: 0.75rem 1rem; border-radius: var(--radius-lg); border: 1px solid rgba(255, 255, 255, 0.4); background: rgba(255, 255, 255, 0.5); font-size: 0.875rem; transition: all 0.2s; outline: none; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"
                onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                onblur="this.style.borderColor='rgba(255, 255, 255, 0.4)'; this.style.boxShadow='inset 0 2px 4px rgba(0,0,0,0.02)';">
            <button class="btn btn-primary"
                style="padding: 0.75rem; font-weight: 500; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: transform 0.2s; box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2), 0 4px 6px -4px rgba(37, 99, 235, 0.1);"><i
                    data-lucide="mail-plus" style="width: 1.25rem; height: 1.25rem;"></i> Create
                Mailbox</button>
        </form>
    </div>

    <!-- LIST -->
    <div class="glass-card table-card" style="padding: 0; overflow: hidden;">
        <div class="table-container custom-scrollbar">
            <table class="modern-table w-full text-left border-collapse" style="width: 100%;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--slate-200); background-color: var(--slate-50);">
                        <th
                            style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase;">
                            Active Email Account</th>
                        <th
                            style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase; text-align: right;">
                            Webmail / Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_emails)): ?>
                        <tr>
                            <td colspan="2" style="text-align: center; padding: 3rem 1.5rem; color: var(--slate-500);">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                    <i data-lucide="mail" style="width: 48px; height: 48px; opacity: 0.5;"></i>
                                    <span>No email accounts found.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($my_emails as $mail): ?>
                            <tr style="border-bottom: 1px solid var(--slate-100); transition: background-color 0.2s;"
                                onmouseover="this.style.backgroundColor='var(--slate-50)'"
                                onmouseout="this.style.backgroundColor='transparent'">
                                <td style="padding: 1rem 1.5rem;">
                                    <div
                                        style="font-weight: 500; color: var(--slate-900); font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem;">
                                        <i data-lucide="mail"
                                            style="width: 1.25rem; height: 1.25rem; color: var(--slate-400);"></i>
                                        <?= htmlspecialchars($mail['email']) ?>
                                    </div>
                                </td>
                                <td
                                    style="padding: 1rem 1.5rem; text-align: right; white-space: nowrap; display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;">
                                    <a href="http://webmail.<?= $base_domain ?>" target="_blank"
                                        style="font-size: 0.75rem; font-weight: 500; color: var(--primary); text-transform: uppercase; text-decoration: none; display: flex; align-items: center; gap: 0.25rem; transition: opacity 0.2s;"
                                        onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'"><i
                                            data-lucide="external-link" style="width: 14px; height: 14px;"></i> Login</a>

                                    <button onclick="resetPassword('reset_mail_pass', 'email', '<?= $mail['email'] ?>')"
                                        style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: var(--radius-md); padding: 0.5rem; cursor: pointer; color: var(--accent-orange); transition: all 0.2s; display: flex; align-items: center; justify-content: center; margin-left: 0.75rem;"
                                        title="Reset Password"
                                        onmouseover="this.style.backgroundColor='rgba(245, 158, 11, 0.2)'; this.style.borderColor='rgba(245, 158, 11, 0.3)';"
                                        onmouseout="this.style.backgroundColor='rgba(245, 158, 11, 0.1)'; this.style.borderColor='rgba(245, 158, 11, 0.2)';"><i
                                            data-lucide="key" style="width: 16px; height: 16px;"></i></button>

                                    <button onclick="deleteAction('delete_email', 'email', '<?= $mail['email'] ?>')"
                                        style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius-md); padding: 0.5rem; cursor: pointer; color: var(--accent-red); transition: all 0.2s; display: flex; align-items: center; justify-content: center;"
                                        title="Delete Mailbox"
                                        onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.2)'; this.style.borderColor='rgba(239, 68, 68, 0.3)';"
                                        onmouseout="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'; this.style.borderColor='rgba(239, 68, 68, 0.2)';"><i
                                            data-lucide="trash-2" style="width: 16px; height: 16px;"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem;">
            <div style="font-size: 0.75rem; color: var(--slate-700); font-weight: 500;">
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