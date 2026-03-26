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
        // CSRF Protection
        try {
            verify_csrf();
        } catch (Exception $e) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
            exit;
        }

        // Get Client Limits
        $stmt = $pdo->prepare("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = ?");
        $stmt->execute([$cid]);
        $limits = $stmt->fetch();

        // --- Action: Add Database ---
        if ($action == 'add_db') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_databases WHERE client_id = ?");
            $stmt->execute([$cid]);
            if ($stmt->fetchColumn() >= $limits['max_databases'])
                throw new Exception("Plan database limit reached.");

            $db_suffix = preg_replace('/[^a-z0-9_]/', '', $_POST['db_name']);
            $db_name = $username . "_" . $db_suffix;
            $domain_id = !empty($_POST['domain_id']) ? (int) $_POST['domain_id'] : null;

            $pdo->prepare("INSERT INTO client_databases (client_id, domain_id, db_name) VALUES (?, ?, ?)")->execute([$cid, $domain_id, $db_name]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name`");
            } else {
                cmd("mysql-tool create-db " . escapeshellarg($db_name));
            }
            echo json_encode($res);
            exit;
        }

        // --- Action: Delete Database ---
        if ($action == 'delete_db') {
            $db_name = $_POST['db_name'];
            $check = $pdo->prepare("SELECT id FROM client_databases WHERE db_name = ? AND client_id = ?");
            $check->execute([$db_name, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM client_databases WHERE db_name = ?")->execute([$db_name]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pdo->exec("DROP DATABASE IF EXISTS `$db_name`");
            } else {
                cmd("mysql-tool delete-db " . escapeshellarg($db_name));
            }
            echo json_encode($res);
            exit;
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
            echo json_encode($res);
            exit;
        }

        // --- Action: Delete Database User ---
        if ($action == 'delete_db_user') {
            $db_user = $_POST['db_user'];
            $check = $pdo->prepare("SELECT id FROM client_db_users WHERE db_user = ? AND client_id = ?");
            $check->execute([$db_user, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM client_db_users WHERE db_user = ?")->execute([$db_user]);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $pdo->exec("DROP USER IF EXISTS '$db_user'@'localhost'");
            } else {
                cmd("mysql-tool delete-user " . escapeshellarg($db_user));
            }
            echo json_encode($res);
            exit;
        }

        // --- Action: Reset Password ---
        if ($action == 'reset_db_pass') {
            $db_user = $_POST['db_user'];
            $pass = $_POST['new_pass'];

            $check = $pdo->prepare("SELECT id FROM client_db_users WHERE db_user = ? AND client_id = ?");
            $check->execute([$db_user, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $quoted_pass = $pdo->quote($pass);
                $pdo->exec("ALTER USER '$db_user'@'localhost' IDENTIFIED BY $quoted_pass");
                $pdo->exec("FLUSH PRIVILEGES");
            } else {
                cmd("mysql-tool reset-pass " . escapeshellarg($db_user) . " " . escapeshellarg($pass));
            }
            echo json_encode($res);
            exit;
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



<div style="display:grid;grid-template-columns:1fr;gap:2rem;margin-bottom:2rem;">

    <!-- Page Header -->
    <div style="border-bottom:1px solid var(--border-color);padding-bottom:1.5rem;">
        <h2 style="font-size:1.875rem;font-weight:500;color:var(--text-primary);font-family:'Lexend',sans-serif;letter-spacing:-0.025em;margin-bottom:0.5rem;">Databases</h2>
        <p style="color:var(--text-secondary);">Manage MySQL databases and users for your applications.</p>
    </div>

    <!-- LEFT SIDE: FORMS -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:2rem;">
        <!-- CREATE DATABASE -->
        <div class="glass-card" style="padding:1.75rem;">
            <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);font-family:var(--font-heading);margin-bottom:1.25rem;display:flex;align-items:center;gap:0.5rem;">
                <i data-lucide="database" style="width:18px;height:18px;color:var(--primary);"></i> Create Database
            </h3>
            <form onsubmit="handleSubmit(event, 'add_db')" style="display:flex;flex-direction:column;gap:1rem;">
                <?= csrf_field() ?>
                <div>
                    <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Database Name</label>
                    <div style="display:flex;align-items:center;border:1px solid var(--border-color);border-radius:var(--input-radius);overflow:hidden;background:var(--bg-surface);transition:border-color 0.2s,box-shadow 0.2s;"
                        onfocusin="this.style.borderColor='var(--primary)';this.style.boxShadow='0 0 0 3px var(--primary-light)';"
                        onfocusout="this.style.borderColor='var(--border-color)';this.style.boxShadow='none';">
                        <div style="padding:0 0.875rem;background:var(--bg-body);color:var(--text-secondary);font-family:monospace;font-size:0.8125rem;border-right:1px solid var(--border-color);height:var(--input-height-md);display:flex;align-items:center;white-space:nowrap;font-weight:600;"><?= htmlspecialchars($username) ?>_</div>
                        <input name="db_name" required placeholder="dbname" style="flex:1;background:transparent;padding:0 0.875rem;border:none;outline:none;color:var(--text-primary);font-size:0.875rem;height:var(--input-height-md);">
                    </div>
                </div>
                <div>
                    <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Link to Domain (optional)</label>
                    <select name="domain_id" class="form-input">
                        <option value="">Global (No Domain)</option>
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['domain']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;gap:0.5rem;">
                    <i data-lucide="plus-circle" style="width:1rem;height:1rem;"></i> Create Database
                </button>
            </form>
        </div>

        <!-- CREATE USER -->
        <div class="glass-card" style="padding:1.75rem;">
            <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);font-family:var(--font-heading);margin-bottom:1.25rem;display:flex;align-items:center;gap:0.5rem;">
                <i data-lucide="user-plus" style="width:18px;height:18px;color:var(--primary);"></i> Create DB User
            </h3>
            <form onsubmit="handleSubmit(event, 'add_db_user')" style="display:flex;flex-direction:column;gap:1rem;">
                <?= csrf_field() ?>
                <div>
                    <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Username</label>
                    <div style="display:flex;align-items:center;border:1px solid var(--border-color);border-radius:var(--input-radius);overflow:hidden;background:var(--bg-surface);transition:border-color 0.2s,box-shadow 0.2s;"
                        onfocusin="this.style.borderColor='var(--primary)';this.style.boxShadow='0 0 0 3px var(--primary-light)';"
                        onfocusout="this.style.borderColor='var(--border-color)';this.style.boxShadow='none';">
                        <div style="padding:0 0.875rem;background:var(--bg-body);color:var(--text-secondary);font-family:monospace;font-size:0.8125rem;border-right:1px solid var(--border-color);height:var(--input-height-md);display:flex;align-items:center;white-space:nowrap;font-weight:600;"><?= htmlspecialchars($username) ?>_</div>
                        <input name="db_user" required placeholder="username" style="flex:1;background:transparent;padding:0 0.875rem;border:none;outline:none;color:var(--text-primary);font-size:0.875rem;height:var(--input-height-md);">
                    </div>
                </div>
                <div>
                    <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Password</label>
                    <input name="db_pass" type="password" required placeholder="••••••••" class="form-input">
                </div>
                <div>
                    <label style="font-size:0.625rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:0.375rem;">Assign to Database</label>
                    <select name="target_db" class="form-input">
                        <?php foreach ($my_dbs as $db): ?>
                            <option value="<?= $db['db_name'] ?>"><?= htmlspecialchars($db['db_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary" style="width:100%;gap:0.5rem;">
                    <i data-lucide="user-plus" style="width:1rem;height:1rem;"></i> Create User
                </button>
            </form>
        </div>
    </div>

    <!-- RIGHT SIDE: TABLES -->
    <div style="display: flex; flex-direction: column; gap: 2rem;">
        <!-- DATABASE LIST -->
        <div>
            <h3
                style="font-size: 1.25rem; font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); margin-bottom: 1rem;">
                Your Databases</h3>
            <div class="glass-card table-card" style="padding: 0; overflow: hidden;">
                <div class="table-container custom-scrollbar">
                    <table class="modern-table w-full text-left border-collapse" style="width: 100%;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border-color);background:var(--bg-body);">
                                <th
                                    style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase;">
                                    Name</th>
                                <th
                                    style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase; text-align: right;">
                                    Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($my_dbs)): ?>
                                <tr>
                                    <td colspan="2"
                                        style="text-align: center; padding: 3rem 1.5rem; color: var(--slate-500);">
                                        <div
                                            style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                            <i data-lucide="database" style="width: 48px; height: 48px; opacity: 0.5;"></i>
                                            <span>No databases found.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($my_dbs as $db): ?>
                                    <tr style="border-bottom:1px solid var(--border-color);transition:background-color 0.2s;"
                                        onmouseover="this.style.backgroundColor='var(--bg-body)'"
                                        onmouseout="this.style.backgroundColor='transparent'">
                                        <td style="padding: 1rem 1.5rem;">
                                            <div
                                                style="font-weight: 500; color: var(--slate-900); font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem;">
                                                <i data-lucide="database"
                                                    style="width: 1.25rem; height: 1.25rem; color: var(--slate-400);"></i>
                                                <?= htmlspecialchars($db['db_name']) ?>
                                            </div>
                                            <div
                                                style="font-size: 0.75rem; color: var(--primary); margin-left: 1.75rem; margin-top: 0.25rem; font-weight: 500;">
                                                <?= $db['domain'] ? htmlspecialchars($db['domain']) : 'Global' ?>
                                            </div>
                                        </td>
                                        <td
                                            style="padding: 1rem 1.5rem; text-align: right; white-space: nowrap; display: flex; align-items: center; justify-content: flex-end; gap: 1rem;">
                                            <a href="http://phpmyadmin.<?= $base_domain ?>" target="_blank"
                                                style="font-size: 0.75rem; font-weight: 500; color: var(--primary); text-transform: uppercase; text-decoration: none; display: flex; align-items: center; gap: 0.25rem; transition: opacity 0.2s;"
                                                onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'"><i
                                                    data-lucide="external-link" style="width: 14px; height: 14px;"></i>
                                                Login</a>
                                            <button
                                                onclick="handleDeleteAction('delete_db', 'db_name', '<?= $db['db_name'] ?>', this)"
                                                style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius-md); padding: 0.5rem; cursor: pointer; color: var(--accent-red); transition: all 0.2s; display: flex; align-items: center; justify-content: center;"
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
        </div>

        <!-- USER LIST -->
        <div>
            <h3
                style="font-size: 1.25rem; font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); margin-bottom: 1rem;">
                Database Users</h3>
            <div class="glass-card table-card" style="padding: 0; overflow: hidden;">
                <div class="table-container custom-scrollbar">
                    <table class="modern-table w-full text-left border-collapse" style="width: 100%;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border-color);background:var(--bg-body);">
                                <th
                                    style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase;">
                                    Username</th>
                                <th
                                    style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase; text-align: right;">
                                    Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $db_users = $pdo->prepare("SELECT * FROM client_db_users WHERE client_id = ?");
                            $db_users->execute([$cid]);
                            $users_list = $db_users->fetchAll();

                            if (empty($users_list)): ?>
                                <tr>
                                    <td colspan="2"
                                        style="text-align: center; padding: 3rem 1.5rem; color: var(--slate-500);">
                                        <div
                                            style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                            <i data-lucide="users" style="width: 48px; height: 48px; opacity: 0.5;"></i>
                                            <span>No database users found.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else:
                                foreach ($users_list as $u): ?>
                                    <tr style="border-bottom:1px solid var(--border-color);transition:background-color 0.2s;"
                                        onmouseover="this.style.backgroundColor='var(--bg-body)'"
                                        onmouseout="this.style.backgroundColor='transparent'">
                                        <td
                                            style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-900); font-size: 0.875rem;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <i data-lucide="user"
                                                    style="width: 1.25rem; height: 1.25rem; color: var(--slate-400);"></i>
                                                <?= htmlspecialchars($u['db_user']) ?>
                                            </div>
                                        </td>
                                        <td
                                            style="padding: 1rem 1.5rem; text-align: right; display: flex; justify-content: flex-end; gap: 0.5rem;">
                                            <button onclick="handleResetPass('<?= $u['db_user'] ?>', this)"
                                                style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: var(--radius-md); padding: 0.5rem; cursor: pointer; color: var(--accent-orange); transition: all 0.2s; display: flex; align-items: center; justify-content: center;"
                                                title="Reset Password"
                                                onmouseover="this.style.backgroundColor='rgba(245, 158, 11, 0.2)'; this.style.borderColor='rgba(245, 158, 11, 0.3)';"
                                                onmouseout="this.style.backgroundColor='rgba(245, 158, 11, 0.1)'; this.style.borderColor='rgba(245, 158, 11, 0.2)';"><i
                                                    data-lucide="key" style="width: 16px; height: 16px;"></i></button>
                                            <button
                                                onclick="handleDeleteAction('delete_db_user', 'db_user', '<?= $u['db_user'] ?>', this)"
                                                style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius-md); padding: 0.5rem; cursor: pointer; color: var(--accent-red); transition: all 0.2s; display: flex; align-items: center; justify-content: center;"
                                                title="Delete User"
                                                onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.2)'; this.style.borderColor='rgba(239, 68, 68, 0.3)';"
                                                onmouseout="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'; this.style.borderColor='rgba(239, 68, 68, 0.2)';"><i
                                                    data-lucide="trash-2" style="width: 16px; height: 16px;"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
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
        fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Success', res.msg || 'Done');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', 'Error', res.msg || 'Action failed.');
                btn.disabled = false;
                btn.classList.remove('btn-loading');
                btn.innerText = originalText;
            }
        } catch (e) { showToast('error', 'Error', 'Server connection failed.'); }
    }

    /**
     * Handle Delete for both DB and User
     */
    async function handleDeleteAction(action, key, val, btn) {
        if (!confirm(`Are you sure you want to delete ${val}?`)) return;

        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"></i>';
        if (window.lucide) lucide.createIcons();

        const fd = new FormData();
        fd.append('ajax_action', action);
        fd.append(key, val);
        fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Deleted', val + ' deleted successfully.');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast('error', 'Delete Failed', res.msg);
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                if (window.lucide) lucide.createIcons();
            }
        } catch (e) { showToast('error', 'Error', 'Connection error.'); }
    }

    /**
     * Handle Password Reset
     */
    async function handleResetPass(user, btn) {
        const newPass = prompt(`Enter new password for ${user}:`);
        if (!newPass) return;

        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i data-lucide="loader-2" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"></i>';
        if (window.lucide) lucide.createIcons();

        const fd = new FormData();
        fd.append('ajax_action', 'reset_db_pass');
        fd.append('db_user', user);
        fd.append('new_pass', newPass);
        fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            showToast(res.status === 'success' ? 'success' : 'error',
                      res.status === 'success' ? 'Password Updated' : 'Failed',
                      res.msg || 'Done');
        } catch (e) { showToast('error', 'Error', 'Reset failed.'); }

        btn.innerHTML = originalHtml;
        if (window.lucide) lucide.createIcons();
    }
</script>

<?php include 'layout/footer.php'; ?>