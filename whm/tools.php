<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Action processed'];

    try {
        verify_csrf();
        // --- FTP HANDLERS ---
        if ($action == 'add_ftp') {
            if ($_POST['pass'] !== $_POST['pass2'])
                throw new Exception("Passwords do not match");

            $sys_user = $_POST['sys_user'];
            $ftp_user = $_POST['ftp_user'] . '@' . $sys_user; // Enforce user@client
            $pass = md5($_POST['pass']);

            // Default home to /var/www/clients/user/public_html
            $home = "/var/www/clients/$sys_user/public_html";

            // Get System User UID/GID
            if (function_exists('posix_getpwnam')) {
                $sys_user_info = posix_getpwnam($sys_user);
                if (!$sys_user_info)
                    throw new Exception("System user not found on server");
                $uid = $sys_user_info['uid'];
                $gid = $sys_user_info['gid'];
            } else {
                // Fallback for Windows Dev
                $uid = 1000;
                $gid = 1000;
            }

            $check = $pdo->prepare("SELECT count(*) FROM ftp_users WHERE userid = ?");
            $check->execute([$ftp_user]);
            if ($check->fetchColumn() > 0)
                throw new Exception("FTP User already exists");

            $pdo->prepare("INSERT INTO ftp_users (userid, passwd, homedir, uid, gid) VALUES (?,?,?,?,?)")->execute([$ftp_user, $pass, $home, $uid, $gid]);
            sendResponse($res);
            exit;
        }

        if ($action == 'list_ftp') {
            $stmt = $pdo->query("SELECT userid, homedir FROM ftp_users ORDER BY userid ASC");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($action == 'del_ftp') {
            $pdo->prepare("DELETE FROM ftp_users WHERE userid = ?")->execute([$_POST['user']]);
            sendResponse($res);
            exit;
        }

        // --- MAIL HANDLERS ---
        if ($action == 'add_mail') {
            $full = $_POST['prefix'] . "@" . $_POST['domain'];
            $pass = password_hash($_POST['mail_pass'], PASSWORD_BCRYPT);
            $did = $pdo->query("SELECT id FROM mail_domains WHERE domain = '{$_POST['domain']}'")->fetchColumn();
            if (!$did)
                throw new Exception("Domain not found for mail");
            // Get client_id from domain
            $client_id = $pdo->query("SELECT client_id FROM mail_domains WHERE id = $did")->fetchColumn();
            if (!$client_id)
                throw new Exception("Client not found for domain");
            $pdo->prepare("INSERT INTO mail_users (client_id, domain_id, email, password) VALUES (?,?,?,?)")->execute([$client_id, $did, $full, $pass]);
            sendResponse($res);
            exit;
        }

        if ($action == 'list_mail') {
            $stmt = $pdo->query("SELECT m.id, m.email, d.domain FROM mail_users m JOIN mail_domains d ON m.domain_id = d.id ORDER BY m.email ASC");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($action == 'del_mail') {
            $id = (int) $_POST['id'];
            $pdo->prepare("DELETE FROM mail_users WHERE id = ?")->execute([$id]);
            // Optional: Call backend to remove physical mailbox if needed
            sendResponse($res);
            exit;
        }

        if ($action == 'set_php_handler') {
            $user = $_POST['sys_user'];
            $ver = $_POST['php_version'];

            // Get domain for this user to update vhost
            $dom = $pdo->query("SELECT domain FROM domains WHERE client_id = (SELECT id FROM clients WHERE username='$user') LIMIT 1")->fetchColumn();

            if (!$dom)
                throw new Exception("No domain found for user");

            cmd("php-tool set-version " . escapeshellarg($user) . " " . escapeshellarg($dom) . " " . escapeshellarg($ver));

            echo json_encode(['status' => 'success', 'msg' => "PHP Version set to $ver for $dom"]);
            exit;
        }

        if ($action == 'set_network_card') {
            $iface = $_POST['interface'];
            cmd("network-tool set-interface " . escapeshellarg($iface));
            echo json_encode(['status' => 'success', 'msg' => "Primary Interface updated to $iface"]);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Data Handling
$clients = $pdo->query("SELECT * FROM clients")->fetchAll(PDO::FETCH_ASSOC);
$mail_domains = $pdo->query("SELECT * FROM mail_domains")->fetchAll(PDO::FETCH_ASSOC);
$php_versions = ['8.1', '8.2', '8.3'];

$active_tab = $_GET['tab'] ?? 'ftp';

include 'layout/header.php';
?>

<div style="margin-bottom: 2rem;">
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">System Tools
    </h2>
    <p style="color: var(--text-secondary); font-size: 0.875rem;">Configure system services and accounts.</p>
</div>

<!-- TABS -->
<div
    style="display: flex; border-bottom: 1px solid var(--border-color); margin-bottom: 2rem; overflow-x: auto; scrollbar-width: none;">
    <a href="?tab=ftp"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 700; border-bottom: 2px solid <?= $active_tab == 'ftp' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'ftp' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'ftp' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        FTP Manager
    </a>
    <a href="?tab=mail"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 700; border-bottom: 2px solid <?= $active_tab == 'mail' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'mail' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'mail' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        Mail Manager
    </a>
    <a href="?tab=php"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 700; border-bottom: 2px solid <?= $active_tab == 'php' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'php' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'php' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        PHP Config
    </a>
    <a href="?tab=network"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 700; border-bottom: 2px solid <?= $active_tab == 'network' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'network' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'network' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        Network Settings
    </a>
</div>

<!-- CONTENT: FTP -->
<div style="display: <?= $active_tab == 'ftp' ? 'block' : 'none' ?>;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 2rem;">
        <!-- CREATE FTP -->
        <div class="glass-card animate-slide-right hover-glow"
            style="padding: 2rem; border-radius: 1.5rem; position: relative; overflow: hidden;">
            <div
                style="position: absolute; right: -2.5rem; top: -2.5rem; width: 10rem; height: 10rem; background: rgba(37, 99, 235, 0.1); border-radius: 9999px; filter: blur(24px);">
            </div>
            <h3
                style="font-size: 1.25rem; font-weight: 700; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; color: var(--text-primary); font-family: var(--font-heading);">
                <div
                    style="padding: 0.5rem; background: rgba(59, 130, 246, 0.1); border-radius: 0.5rem; border: 1px solid rgba(59, 130, 246, 0.2); color: var(--primary);">
                    <i data-lucide="folder-up" style="width: 1.25rem; height: 1.25rem;"></i>
                </div>
                Create FTP Account
            </h3>
            <form onsubmit="handleToolAction(event, 'add_ftp', loadFTP)"
                style="display: flex; flex-direction: column; gap: 1rem; position: relative; z-index: 10;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <input name="ftp_user" required placeholder="Pre-fix (e.g. dev)" class="form-input"
                        style="width: 100%; border-radius: 0.75rem;">
                    <select name="sys_user" required class="form-input"
                        style="width: 100%; border-radius: 0.75rem; cursor: pointer;">
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['username'] ?>">@<?= $c['username'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <input name="pass" required type="password" placeholder="Password" class="form-input"
                        style="width: 100%; border-radius: 0.75rem;">
                    <input name="pass2" required type="password" placeholder="Confirm" class="form-input"
                        style="width: 100%; border-radius: 0.75rem;">
                </div>
                <button type="submit" class="btn btn-primary"
                    style="width: 100%; padding: 0.875rem; border-radius: 0.75rem; margin-top: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                    Create FTP User
                </button>
            </form>
        </div>

        <!-- LIST FTP -->
        <div class="glass-card animate-slide-right hover-glow"
            style="padding: 2rem; border-radius: 1.5rem; position: relative; overflow: hidden; display: flex; flex-direction: column; height: 100%; animation-delay: 0.1s;">
            <h3
                style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; color: var(--text-primary); font-family: var(--font-heading);">
                Existing Accounts</h3>
            <div style="overflow-y: auto; flex: 1; max-height: 400px;" class="custom-scrollbar">
                <table style="width: 100%; text-align: left; border-collapse: collapse;">
                    <thead
                        style="background: var(--bg-body); font-size: 0.625rem; font-weight: 700; text-transform: uppercase; color: var(--text-secondary); position: sticky; top: 0; backdrop-filter: blur(12px);">
                        <tr>
                            <th style="padding: 0.75rem;">User</th>
                            <th style="padding: 0.75rem;">Home</th>
                            <th style="padding: 0.75rem; text-align: right;"></th>
                        </tr>
                    </thead>
                    <tbody id="ftp-list" class="divide-y divide-slate-700/50">
                        <tr>
                            <td colspan="3" style="padding: 1rem; text-align: center; color: var(--text-secondary);">
                                Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- CONTENT: MAIL -->
<div style="display: <?= $active_tab == 'mail' ? 'block' : 'none' ?>;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 2rem;">
        <!-- CREATE MAIL -->
        <div class="glass-card animate-slide-right hover-glow"
            style="padding: 2rem; border-radius: 1.5rem; position: relative; overflow: hidden;">
            <div
                style="position: absolute; right: -2.5rem; top: -2.5rem; width: 10rem; height: 10rem; background: rgba(16, 185, 129, 0.1); border-radius: 9999px; filter: blur(24px);">
            </div>
            <h3
                style="font-size: 1.25rem; font-weight: 700; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; color: var(--text-primary); font-family: var(--font-heading);">
                <div
                    style="padding: 0.5rem; background: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; border: 1px solid rgba(16, 185, 129, 0.2); color: var(--accent-emerald);">
                    <i data-lucide="mail-plus" style="width: 1.25rem; height: 1.25rem;"></i>
                </div>
                Create Email Account
            </h3>
            <form onsubmit="handleToolAction(event, 'add_mail', loadMail)"
                style="display: flex; flex-direction: column; gap: 1rem; position: relative; z-index: 10;">
                <div style="display: flex; gap: 0.5rem;">
                    <input name="prefix" required placeholder="user" class="form-input"
                        style="flex: 1; border-radius: 0.75rem; text-align: right;">
                    <div style="display: flex; align-items: center; color: var(--text-secondary); font-weight: 700;">@
                    </div>
                    <select name="domain" required class="form-input"
                        style="flex: 1; border-radius: 0.75rem; cursor: pointer;">
                        <?php foreach ($mail_domains as $d): ?>
                            <option value="<?= $d['domain'] ?>"><?= $d['domain'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Hidden fake fields to prevent chrome autocomplete mess -->
                <input style="display:none" type="text" name="fakeusernameremembered" />
                <input style="display:none" type="password" name="fakepasswordremembered" />

                <input name="mail_pass" required type="password" placeholder="Password" autocomplete="new-password"
                    class="form-input" style="width: 100%; border-radius: 0.75rem; margin-bottom: 0.5rem;">
                <button type="submit" class="btn btn-primary"
                    style="width: 100%; padding: 0.875rem; border-radius: 0.75rem; margin-top: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">Create
                    Mailbox</button>
            </form>
        </div>

        <!-- LIST MAIL -->
        <div class="glass-card animate-slide-right hover-glow"
            style="padding: 2rem; border-radius: 1.5rem; position: relative; overflow: hidden; display: flex; flex-direction: column; height: 100%; animation-delay: 0.1s;">
            <h3
                style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; color: var(--text-primary); font-family: var(--font-heading);">
                Existing Mailboxes</h3>
            <div style="overflow-y: auto; flex: 1; max-height: 400px;" class="custom-scrollbar">
                <table style="width: 100%; text-align: left; border-collapse: collapse;">
                    <thead
                        style="background: var(--bg-body); font-size: 0.625rem; font-weight: 700; text-transform: uppercase; color: var(--text-secondary); position: sticky; top: 0; backdrop-filter: blur(12px);">
                        <tr>
                            <th style="padding: 0.75rem;">Email Address</th>
                            <th style="padding: 0.75rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="mail-list" class="divide-y divide-slate-700/50">
                        <tr>
                            <td colspan="2" style="padding: 1rem; text-align: center; color: var(--text-secondary);">
                                Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- CONTENT: PHP -->
<div style="display: <?= $active_tab == 'php' ? 'block' : 'none' ?>;">
    <div class="glass-card animate-slide-right hover-glow"
        style="padding: 2rem; border-radius: 1.5rem; position: relative; overflow: hidden; max-width: 42rem;">
        <div
            style="position: absolute; right: -2.5rem; top: -2.5rem; width: 10rem; height: 10rem; background: rgba(147, 51, 234, 0.1); border-radius: 9999px; filter: blur(24px);">
        </div>
        <h3
            style="font-size: 1.25rem; font-weight: 700; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; color: var(--text-primary); font-family: var(--font-heading);">
            <div
                style="padding: 0.5rem; background: rgba(168, 85, 247, 0.1); border-radius: 0.5rem; border: 1px solid rgba(168, 85, 247, 0.2); color: var(--accent-purple);">
                <i data-lucide="code" style="width: 1.25rem; height: 1.25rem;"></i>
            </div>
            PHP Handlers
        </h3>
        <form onsubmit="handleToolAction(event, 'set_php_handler')"
            style="display: flex; flex-direction: column; gap: 1rem; position: relative; z-index: 10;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label
                        style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 700; text-transform: uppercase; margin-bottom: 0.25rem; display: block;">User
                        / Site</label>
                    <select name="sys_user" required class="form-input"
                        style="width: 100%; border-radius: 0.75rem; font-family: monospace; font-size: 0.875rem;">
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['username'] ?>"><?= $c['username'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label
                        style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 700; text-transform: uppercase; margin-bottom: 0.25rem; display: block;">PHP
                        Version</label>
                    <select name="php_version" required class="form-input"
                        style="width: 100%; border-radius: 0.75rem; font-family: monospace; font-size: 0.875rem;">
                        <?php foreach ($php_versions as $v): ?>
                            <option value="<?= $v ?>">PHP <?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"
                style="width: 100%; padding: 0.875rem; border-radius: 0.75rem; margin-top: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                Update Handlers
            </button>
        </form>
    </div>
</div>

<!-- CONTENT: NETWORK -->
<div style="display: <?= $active_tab == 'network' ? 'block' : 'none' ?>;">
    <div class="glass-card animate-slide-right hover-glow"
        style="padding: 2rem; border-radius: 1.5rem; position: relative; overflow: hidden; max-width: 42rem;">
        <div
            style="position: absolute; right: -2.5rem; top: -2.5rem; width: 10rem; height: 10rem; background: rgba(234, 88, 12, 0.1); border-radius: 9999px; filter: blur(24px);">
        </div>
        <h3
            style="font-size: 1.25rem; font-weight: 700; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; color: var(--text-primary); font-family: var(--font-heading);">
            <div
                style="padding: 0.5rem; background: rgba(249, 115, 22, 0.1); border-radius: 0.5rem; border: 1px solid rgba(249, 115, 22, 0.2); color: #f97316;">
                <i data-lucide="network" style="width: 1.25rem; height: 1.25rem;"></i>
            </div>
            Network Config
        </h3>
        <form onsubmit="handleToolAction(event, 'set_network_card')"
            style="display: flex; flex-direction: column; gap: 1rem; position: relative; z-index: 10;">
            <div
                style="padding: 1rem; border-radius: 0.75rem; background: rgba(249, 115, 22, 0.05); border: 1px solid rgba(249, 115, 22, 0.1); margin-bottom: 1rem;">
                <p style="font-size: 0.75rem; color: rgba(253, 186, 116, 0.8); line-height: 1.625;">
                    <strong style="color: #fb923c;">Warning:</strong> Incorrect network settings may lock you out of the
                    admin panel. Proceed with caution.
                </p>
            </div>
            <select name="interface" class="form-input"
                style="width: 100%; border-radius: 0.75rem; font-family: monospace;">
                <option value="eth0">eth0 (Standard Cloud)</option>
                <option value="eth1">eth1 (Secondary)</option>
                <option value="ens3">ens3 (KVM)</option>
                <option value="ens18">ens18 (Proxmox)</option>
            </select>
            <button type="submit" class="btn btn-primary"
                style="width: 100%; padding: 0.875rem; border-radius: 0.75rem; margin-top: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">Update
                Interface</button>
        </form>
    </div>
</div>

<?php include 'layout/footer.php'; ?>
<script>
    // Generic Handler for Tool Actions
    async function handleToolAction(e, action, callback = null) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin inline mr-2"></i> Processing...';
        lucide.createIcons();

        const fd = new FormData(e.target);
        fd.append('ajax_action', action);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', res.msg || 'Operation successful');
                e.target.reset();
                if (callback) callback();
            } else {
                showToast('error', res.msg || 'Operation failed');
            }
        } catch (e) {
            showToast('error', 'Communication Error');
        }
        btn.disabled = false;
        btn.innerHTML = oldHtml;
    }

    // FTP Loader
    async function loadFTP() {
        const list = document.getElementById('ftp-list');
        if (!list || list.offsetParent === null) return; // Only load if visible
        const fd = new FormData(); fd.append('ajax_action', 'list_ftp');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';
            if (res.data && res.data.length > 0) {
                res.data.forEach(u => {
                    list.innerHTML += `
                        <tr style="transition: all var(--transition-normal); border-bottom: 1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-body)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 1rem; font-family: monospace; font-size: 0.75rem; color: var(--primary); font-weight: 700;">${u.userid}</td>
                            <td style="padding: 1rem; color: var(--text-secondary); font-size: 0.75rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 150px; font-family: monospace;">${u.homedir}</td>
                            <td style="padding: 1rem; text-align: right;">
                                <button onclick="delFTP('${u.userid}')" style="padding: 0.5rem; border-radius: 0.5rem; color: var(--accent-red); transition: all 0.2s; cursor: pointer;" onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'; this.style.color='#ef4444';" onmouseout="this.style.backgroundColor='transparent'; this.style.color='var(--accent-red)';"><i data-lucide="trash-2" style="width: 1rem; height: 1rem;"></i></button>
                            </td>
                        </tr>
                     `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<tr><td colspan="3" style="padding: 2rem; text-align: center; color: var(--text-secondary); font-style: italic; font-size: 0.875rem;">No FTP accounts found.</td></tr>';
            }
        } catch (e) { list.innerHTML = '<tr><td colspan="3" style="padding: 1rem; text-align: center; color: var(--accent-red);">Error loading data.</td></tr>'; }
    }

    // Mail Loader
    async function loadMail() {
        const list = document.getElementById('mail-list');
        if (!list || list.offsetParent === null) return; // Only load if visible
        const fd = new FormData(); fd.append('ajax_action', 'list_mail');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';
            if (res.data && res.data.length > 0) {
                res.data.forEach(u => {
                    list.innerHTML += `
                        <tr style="transition: all var(--transition-normal); border-bottom: 1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-surface)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 1rem; font-size: 0.875rem; color: var(--text-primary); font-weight: 500;">
                                ${u.email}
                                <div style="font-size: 0.625rem; color: var(--text-secondary); font-family: monospace; margin-top: 0.125rem;">${u.domain}</div>
                            </td>
                            <td style="padding: 1rem; text-align: right;">
                                <button onclick="delMail(${u.id}, '${u.email}')" style="padding: 0.5rem; border-radius: 0.5rem; color: var(--accent-red); transition: all 0.2s; cursor: pointer;" onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'; this.style.color='#ef4444';" onmouseout="this.style.backgroundColor='transparent'; this.style.color='var(--accent-red)';"><i data-lucide="trash-2" style="width: 1rem; height: 1rem;"></i></button>
                            </td>
                        </tr>
                     `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<tr><td colspan="2" style="padding: 2rem; text-align: center; color: var(--text-secondary); font-style: italic; font-size: 0.875rem;">No mailboxes found.</td></tr>';
            }
        } catch (e) { list.innerHTML = '<tr><td colspan="2" style="padding: 1rem; text-align: center; color: var(--accent-red);">Error loading data.</td></tr>'; }
    }

    async function delFTP(user) {
        if (!confirm('Permanent Delete: ' + user + '?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'del_ftp');
        fd.append('user', user);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        await fetch('', { method: 'POST', body: fd });
        showToast('success', 'FTP Account Deleted');
        loadFTP();
    }

    async function delMail(id, email) {
        if (!confirm('Permanent Delete: ' + email + '?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'del_mail');
        fd.append('id', id);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        await fetch('', { method: 'POST', body: fd });
        showToast('success', 'Mailbox Deleted');
        loadMail();
    }

    // Initial Load based on Active Tab
    document.addEventListener('DOMContentLoaded', () => {
        loadFTP();
        loadMail();
    });

</script>