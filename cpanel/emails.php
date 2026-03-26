<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
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
            echo json_encode($res);
            exit;
        }

        if ($action == 'delete_email') {
            $email = $_POST['email'];
            $check = $pdo->prepare("SELECT m.id FROM mail_users m JOIN mail_domains md ON m.domain_id = md.id JOIN domains d ON md.domain = d.domain WHERE m.email = ? AND d.client_id = ?");
            $check->execute([$email, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");
            cmd("mail-tool delete-user " . escapeshellarg($email));
            echo json_encode($res);
            exit;
        }

        if ($action == 'reset_mail_pass') {
            $email = $_POST['email'];
            $pass  = $_POST['new_pass'];
            $check = $pdo->prepare("SELECT m.id FROM mail_users m JOIN mail_domains md ON m.domain_id = md.id JOIN domains d ON md.domain = d.domain WHERE m.email = ? AND d.client_id = ?");
            $check->execute([$email, $cid]);
            if (!$check->fetch()) throw new Exception("Access Denied");
            cmd("mail-tool reset-pass " . escapeshellarg($email) . " " . escapeshellarg($pass));
            echo json_encode($res);
            exit;
        }

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Pagination
$page     = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$total_emails = $pdo->query("SELECT COUNT(*) FROM mail_users mu JOIN mail_domains md ON mu.domain_id = md.id WHERE md.domain IN (SELECT domain FROM domains WHERE client_id = $cid)")->fetchColumn();
$total_pages  = ceil($total_emails / $per_page);

$domains   = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();
$my_emails = $pdo->query("SELECT mu.* FROM mail_users mu JOIN mail_domains md ON mu.domain_id = md.id WHERE md.domain IN (SELECT domain FROM domains WHERE client_id = $cid) LIMIT $per_page OFFSET $offset")->fetchAll();

$server_host = $_SERVER['HTTP_HOST'];
$parts = explode('.', $server_host);
$base_domain = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $server_host;

include 'layout/header.php';
?>

<div style="display:flex;flex-direction:column;gap:2.5rem;">

    <!-- Page Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:1rem;border-bottom:1px solid var(--border-color);padding-bottom:1.5rem;">
        <div>
            <h2 style="font-size:1.875rem;font-weight:500;color:var(--text-primary);font-family:'Lexend',sans-serif;letter-spacing:-0.025em;margin-bottom:0.5rem;">Email Accounts</h2>
            <p style="color:var(--text-secondary);">Create and manage mailboxes for your domains.</p>
        </div>
        <div style="font-size:0.8125rem;color:var(--text-muted);">
            <span style="font-weight:600;color:var(--text-primary);"><?= $total_emails ?></span> / <?= $limits['max_emails'] ?? '∞' ?> accounts used
        </div>
    </div>

    <!-- CREATE EMAIL -->
    <div class="glass-card" style="padding:2rem;">
        <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);font-family:var(--font-heading);margin-bottom:1.5rem;display:flex;align-items:center;gap:0.5rem;">
            <i data-lucide="mail-plus" style="width:18px;height:18px;color:var(--primary);"></i>
            Create Mailbox
        </h3>
        <form onsubmit="handleAddEmail(event)" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;" id="form-add-email">
            <?= csrf_field() ?>
            <div>
                <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Mailbox Name</label>
                <input name="user" required placeholder="e.g. info, support" class="form-input">
            </div>
            <div>
                <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Domain</label>
                <select name="domain" class="form-input form-select" style="appearance:none;">
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= $d['domain'] ?>">@<?= htmlspecialchars($d['domain']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Password</label>
                <input name="pass" type="password" required placeholder="••••••••" class="form-input">
            </div>
            <div style="display:flex;align-items:flex-end;">
                <button class="btn btn-primary" style="width:100%;height:var(--input-height-md);gap:0.5rem;">
                    <i data-lucide="plus-circle" style="width:1rem;height:1rem;"></i> Create Mailbox
                </button>
            </div>
        </form>
    </div>

    <!-- LIST -->
    <div class="glass-card table-card" style="padding:0;overflow:hidden;">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border-color);background:var(--bg-body);display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-weight:700;color:var(--text-primary);font-family:var(--font-heading);">Active Email Accounts</h3>
            <button onclick="location.reload()" style="color:var(--text-muted);background:transparent;border:none;cursor:pointer;padding:0.5rem;border-radius:var(--radius-md);transition:all 0.2s;" onmouseover="this.style.color='var(--text-primary)';this.style.backgroundColor='var(--bg-body)';" onmouseout="this.style.color='var(--text-muted)';this.style.backgroundColor='transparent';">
                <i data-lucide="refresh-cw" style="width:1rem;height:1rem;"></i>
            </button>
        </div>
        <div class="table-container custom-scrollbar">
            <table style="width:100%;border-collapse:collapse;">
                <thead style="background:var(--bg-body);font-size:0.75rem;text-transform:uppercase;color:var(--text-secondary);font-weight:700;letter-spacing:0.05em;border-bottom:1px solid var(--border-color);">
                    <tr>
                        <th style="padding:1rem 1.5rem;text-align:left;">Email Address</th>
                        <th style="padding:1rem 1.5rem;text-align:right;">Webmail / Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_emails)): ?>
                        <tr>
                            <td colspan="2" style="text-align:center;padding:3rem 1.5rem;color:var(--text-muted);">
                                <div style="display:flex;flex-direction:column;align-items:center;gap:0.5rem;">
                                    <i data-lucide="mail" style="width:48px;height:48px;opacity:0.4;"></i>
                                    <span>No email accounts yet. Create your first mailbox above.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: foreach ($my_emails as $mail): ?>
                        <tr style="border-bottom:1px solid var(--border-color);transition:background-color 0.2s;" onmouseover="this.style.backgroundColor='var(--bg-body)'" onmouseout="this.style.backgroundColor='transparent'">
                            <td style="padding:1rem 1.5rem;">
                                <div style="font-weight:600;color:var(--text-primary);font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
                                    <i data-lucide="mail" style="width:1rem;height:1rem;color:var(--text-muted);"></i>
                                    <?= htmlspecialchars($mail['email']) ?>
                                </div>
                            </td>
                            <td style="padding:1rem 1.5rem;text-align:right;">
                                <div style="display:flex;align-items:center;justify-content:flex-end;gap:0.5rem;">
                                    <a href="http://webmail.<?= $base_domain ?>" target="_blank"
                                        style="font-size:0.75rem;font-weight:600;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:0.25rem;padding:0.375rem 0.75rem;border:1px solid rgba(37,99,235,0.25);border-radius:var(--radius-md);background:rgba(37,99,235,0.05);transition:all 0.2s;"
                                        onmouseover="this.style.backgroundColor='rgba(37,99,235,0.15)';" onmouseout="this.style.backgroundColor='rgba(37,99,235,0.05)';">
                                        <i data-lucide="external-link" style="width:12px;height:12px;"></i> Webmail
                                    </a>
                                    <button onclick="resetMailPass('<?= htmlspecialchars($mail['email'], ENT_QUOTES) ?>')"
                                        style="padding:0.5rem;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);border-radius:var(--radius-md);cursor:pointer;color:var(--accent-amber);transition:all 0.2s;display:flex;align-items:center;"
                                        title="Reset Password"
                                        onmouseover="this.style.backgroundColor='rgba(245,158,11,0.2)';" onmouseout="this.style.backgroundColor='rgba(245,158,11,0.1)';">
                                        <i data-lucide="key" style="width:16px;height:16px;"></i>
                                    </button>
                                    <button onclick="deleteEmail('<?= htmlspecialchars($mail['email'], ENT_QUOTES) ?>')"
                                        style="padding:0.5rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:var(--radius-md);cursor:pointer;color:var(--accent-red);transition:all 0.2s;display:flex;align-items:center;"
                                        title="Delete Mailbox"
                                        onmouseover="this.style.backgroundColor='rgba(239,68,68,0.2)';" onmouseout="this.style.backgroundColor='rgba(239,68,68,0.1)';">
                                        <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:0.75rem;color:var(--text-secondary);">Page <?= $page ?> of <?= $total_pages ?></div>
        <div style="display:flex;gap:0.5rem;">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="btn btn-secondary" style="font-size:0.75rem;">Previous</a>
            <?php endif; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?>" class="btn btn-secondary" style="font-size:0.75rem;">Next</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'layout/footer.php'; ?>

<script>
function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="csrf_token"]')?.value || '';
}

async function handleAddEmail(e) {
    e.preventDefault();
    const form = e.target;
    const btn  = form.querySelector('button');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" style="width:1rem;height:1rem;animation:spin 1s linear infinite;display:inline-block;margin-right:0.3rem;"></i>Creating…';
    lucide.createIcons();
    const fd = new FormData(form);
    fd.append('ajax_action', 'add_email');
    try {
        const res = await fetch('', { method:'POST', body:fd }).then(r => r.json());
        if (res.status === 'success') {
            showToast('success', 'Mailbox Created', res.msg || 'Email account created successfully.');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('error', 'Failed', res.msg || 'Could not create mailbox.');
        }
    } catch(err) {
        showToast('error', 'Error', 'Server connection failed.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

async function deleteEmail(email) {
    if (!confirm('Delete ' + email + '? This is permanent.')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_email');
    fd.append('email', email);
    fd.append('csrf_token', getCsrf());
    try {
        const res = await fetch('', { method:'POST', body:fd }).then(r => r.json());
        if (res.status === 'success') {
            showToast('success', 'Deleted', 'Mailbox removed.');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('error', 'Failed', res.msg);
        }
    } catch(e) { showToast('error', 'Error', 'Deletion failed.'); }
}

async function resetMailPass(email) {
    const newPass = prompt('New password for ' + email + ':');
    if (!newPass) return;
    const fd = new FormData();
    fd.append('ajax_action', 'reset_mail_pass');
    fd.append('email', email);
    fd.append('new_pass', newPass);
    fd.append('csrf_token', getCsrf());
    try {
        const res = await fetch('', { method:'POST', body:fd }).then(r => r.json());
        showToast(res.status === 'success' ? 'success' : 'error',
                  res.status === 'success' ? 'Password Updated' : 'Failed',
                  res.msg || 'Done');
    } catch(e) { showToast('error', 'Error', 'Reset failed.'); }
}
</script>