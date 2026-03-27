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
            if (!check_rate_limit('add_ftp', 10, 3600))
                throw new Exception("Too many attempts. Please wait an hour.");
            
            if (empty($_POST['ftp_user']) || empty($_POST['sys_user']) || empty($_POST['pass']))
                throw new Exception("All fields are required");

            if ($_POST['pass'] !== $_POST['pass2'])
                throw new Exception("Passwords do not match");

            $sys_user = shm_clean($_POST['sys_user']);
            $ftp_user = shm_clean($_POST['ftp_user']) . '@' . $sys_user; 
            $pass = md5($_POST['pass']);

            $home = "/var/www/clients/$sys_user/public_html";

            if (function_exists('posix_getpwnam')) {
                $sys_user_info = @posix_getpwnam($sys_user);
                if (!$sys_user_info)
                    throw new Exception("System user '$sys_user' not found on server");
                $uid = $sys_user_info['uid'];
                $gid = $sys_user_info['gid'];
            } else {
                $uid = 1000;
                $gid = 1000;
            }

            $check = $pdo->prepare("SELECT count(*) FROM ftp_users WHERE userid = ?");
            $check->execute([$ftp_user]);
            if ($check->fetchColumn() > 0)
                throw new Exception("FTP User already exists");

            $pdo->prepare("INSERT INTO ftp_users (userid, passwd, homedir, uid, gid) VALUES (?,?,?,?,?)")->execute([$ftp_user, $pass, $home, $uid, $gid]);
            $res['msg'] = "FTP account '$ftp_user' created";
            echo json_encode($res);
            exit;
        }

        if ($action == 'list_ftp') {
            $stmt = $pdo->query("SELECT userid, homedir FROM ftp_users ORDER BY userid ASC");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($action == 'del_ftp') {
            $pdo->prepare("DELETE FROM ftp_users WHERE userid = ?")->execute([$_POST['user']]);
            $res['msg'] = 'FTP account deleted';
            echo json_encode($res);
            exit;
        }

        // --- MAIL HANDLERS ---
        if ($action == 'add_mail') {
            if (!check_rate_limit('add_mail', 10, 3600))
                throw new Exception("Too many attempts. Please wait an hour.");

            $prefix = shm_clean($_POST['prefix'] ?? '');
            $domain = shm_clean($_POST['domain'] ?? '');
            $full = $prefix . "@" . $domain;
            
            if (!is_valid_email($full))
                throw new Exception("Invalid email format");

            $pass = password_hash($_POST['mail_pass'], PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("SELECT id, client_id FROM mail_domains WHERE domain = ?");
            $stmt->execute([$domain]);
            $dinfo = $stmt->fetch();
            
            if (!$dinfo)
                throw new Exception("Domain '$domain' not found in mail system");
            
            $did = $dinfo['id'];
            $client_id = $dinfo['client_id'];

            $pdo->prepare("INSERT INTO mail_users (client_id, domain_id, email, password) VALUES (?,?,?,?)")->execute([$client_id, $did, $full, $pass]);
            $res['msg'] = "Mailbox '$full' created";
            echo json_encode($res);
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
            $res['msg'] = 'Mailbox deleted';
            echo json_encode($res);
            exit;
        }

        // --- BACKUP HANDLER ---
        if ($action == 'list_backups') {
            $stmt = $pdo->query("
                SELECT b.id, b.filename, b.size_mb, b.status, b.created_at,
                       c.username
                FROM backups b
                LEFT JOIN clients c ON b.client_id = c.id
                ORDER BY b.created_at DESC
                LIMIT 100
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($action == 'set_php_handler') {
            $user = shm_clean($_POST['sys_user']);
            $ver = shm_clean($_POST['php_version']);

            $stmt = $pdo->prepare("SELECT domain FROM domains WHERE client_id = (SELECT id FROM clients WHERE username=?) LIMIT 1");
            $stmt->execute([$user]);
            $dom = $stmt->fetchColumn();

            if (!$dom)
                throw new Exception("No domain found for user '$user'");

            echo json_encode(['status' => 'success', 'msg' => "PHP Version update initiated for $dom"]);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            cmd("php-tool set-version " . escapeshellarg($user) . " " . escapeshellarg($dom) . " " . escapeshellarg($ver));
            exit;
        }

        if ($action == 'set_network_card') {
            $iface = shm_clean($_POST['interface']);
            echo json_encode(['status' => 'success', 'msg' => "Network interface update initiated for $iface"]);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            cmd("network-tool set-interface " . escapeshellarg($iface));
            exit;
        }
        if ($action == 'fix_permissions') {
            // Fetch all domains with their document roots
            $all_domains = $pdo->query("
                SELECT d.id, d.domain, d.document_root, c.username
                FROM domains d
                JOIN clients c ON d.client_id = c.id
                ORDER BY c.username ASC, d.domain ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($all_domains)) throw new Exception('No domains found in database');

            $results = [];
            foreach ($all_domains as $dom) {
                $docroot = rtrim($dom['document_root'], '/');
                if (empty($docroot)) {
                    $results[] = ['domain' => $dom['domain'], 'status' => 'skipped', 'msg' => 'No document_root set'];
                    continue;
                }
                if (!is_dir($docroot)) {
                    $results[] = ['domain' => $dom['domain'], 'status' => 'warning', 'msg' => 'Directory does not exist: ' . $docroot];
                    continue;
                }

                $ok = true;
                $errors = [];

                // Try chown via shm-manage (requires sudo)
                try {
                    cmd('webdir-fix-perms ' . escapeshellarg($dom['username']) . ' ' . escapeshellarg($docroot));
                } catch (Throwable $e) {
                    // Fallback: try exec directly
                    if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
                        $out = []; $rc = 0;
                        exec('sudo chown -R www-data:www-data ' . escapeshellarg($docroot) . ' 2>&1', $out, $rc);
                        exec('sudo chmod -R 755 ' . escapeshellarg($docroot) . ' 2>&1', $out2, $rc2);
                        if ($rc !== 0 || $rc2 !== 0) {
                            $errors[] = 'chown/chmod failed: ' . implode(', ', array_merge($out, $out2 ?? []));
                            $ok = false;
                        }
                    } else {
                        // Last resort: PHP chmod (can only fix read bits, not ownership)
                        $iter = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($docroot, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::SELF_FIRST
                        );
                        foreach ($iter as $fileObj) {
                            @chmod($fileObj->getPathname(), $fileObj->isDir() ? 0755 : 0644);
                        }
                        @chmod($docroot, 0755);
                        $errors[] = 'Note: chown requires root. Only chmod applied via PHP.';
                    }
                }

                $results[] = [
                    'domain' => $dom['domain'],
                    'user'   => $dom['username'],
                    'path'   => $docroot,
                    'status' => $ok ? 'ok' : 'partial',
                    'msg'    => $ok ? 'Permissions fixed' : implode('; ', $errors)
                ];
            }

            echo json_encode(['status' => 'success', 'results' => $results]);
            exit;
        }


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
    <h2 style="font-size: 1.5rem; font-weight: 500; color: var(--text-primary); margin-bottom: 0.5rem;">System Tools
    </h2>
    <p style="color: var(--text-secondary); font-size: 0.875rem;">Configure system services and accounts.</p>
</div>

<!-- TABS -->
<div
    style="display: flex; border-bottom: 1px solid var(--border-color); margin-bottom: 2rem; overflow-x: auto; scrollbar-width: none;">
    <a href="?tab=ftp"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'ftp' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'ftp' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'ftp' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        FTP Manager
    </a>
    <a href="?tab=mail"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'mail' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'mail' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'mail' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        Mail Manager
    </a>
    <a href="?tab=php"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'php' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'php' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'php' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        PHP Config
    </a>
    <a href="?tab=network"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'network' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'network' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'network' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        Network Settings
    </a>
    <a href="?tab=backups"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'backups' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'backups' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'backups' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        Backups
    </a>
    <a href="?tab=permissions"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'permissions' ? 'var(--primary)' : 'transparent' ?>; color: <?= $active_tab == 'permissions' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>; transition: all var(--transition-normal); white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--text-primary)'"
        onmouseout="this.style.color='<?= $active_tab == 'permissions' ? 'var(--text-primary)' : 'var(--text-secondary)' ?>'">
        🔧 Fix Permissions
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
                style="font-size: 1.25rem; font-weight: 500; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; color: var(--text-primary); font-family: var(--font-heading);">
                <div
                    style="display: flex; align-items: center; justify-content: center;padding: 0.5rem; background: rgba(59, 130, 246, 0.1); border-radius: 0.5rem; border: 1px solid rgba(59, 130, 246, 0.2); color: var(--primary);">
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
                style="font-size: 1.25rem; font-weight: 500; margin-bottom: 1.5rem; color: var(--text-primary); font-family: var(--font-heading);">
                Existing Accounts</h3>
            <div style="overflow-y: auto; flex: 1; max-height: 400px;" class="custom-scrollbar">
                <table style="width: 100%; text-align: left; border-collapse: collapse;">
                    <thead
                        style="background: var(--bg-body); font-size: 0.625rem; font-weight: 500; text-transform: uppercase; color: var(--text-secondary); position: sticky; top: 0; backdrop-filter: blur(12px);">
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
                style="font-size: 1.25rem; font-weight: 500; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; color: var(--text-primary); font-family: var(--font-heading);">
                <div
                    style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; background: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; border: 1px solid rgba(16, 185, 129, 0.2); color: var(--accent-emerald);">
                    <i data-lucide="mail-plus" style="width: 1.25rem; height: 1.25rem;"></i>
                </div>
                Create Email Account
            </h3>
            <form onsubmit="handleToolAction(event, 'add_mail', loadMail)"
                style="display: flex; flex-direction: column; gap: 1rem; position: relative; z-index: 10;">
                <div style="display: flex; gap: 0.5rem;">
                    <input name="prefix" required placeholder="user" class="form-input"
                        style="flex: 1; border-radius: 0.75rem; text-align: right;">
                    <div style="display: flex; align-items: center; color: var(--text-secondary); font-weight: 500;">@
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
                style="font-size: 1.25rem; font-weight: 500; margin-bottom: 1.5rem; color: var(--text-primary); font-family: var(--font-heading);">
                Existing Mailboxes</h3>
            <div style="overflow-y: auto; flex: 1; max-height: 400px;" class="custom-scrollbar">
                <table style="width: 100%; text-align: left; border-collapse: collapse;">
                    <thead
                        style="background: var(--bg-body); font-size: 0.625rem; font-weight: 500; text-transform: uppercase; color: var(--text-secondary); position: sticky; top: 0; backdrop-filter: blur(12px);">
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
            style="font-size: 1.25rem; font-weight: 500; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; color: var(--text-primary); font-family: var(--font-heading);">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; background: rgba(168, 85, 247, 0.1); border-radius: 0.5rem; border: 1px solid rgba(168, 85, 247, 0.2); color: var(--accent-purple);">
                <i data-lucide="code" style="width: 1.25rem; height: 1.25rem;"></i>
            </div>
            PHP Handlers
        </h3>
        <form onsubmit="handleToolAction(event, 'set_php_handler')"
            style="display: flex; flex-direction: column; gap: 1rem; position: relative; z-index: 10;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label
                        style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500; text-transform: uppercase; margin-bottom: 0.25rem; display: block;">User
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
                        style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500; text-transform: uppercase; margin-bottom: 0.25rem; display: block;">PHP
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
            style="font-size: 1.25rem; font-weight: 500; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; color: var(--text-primary); font-family: var(--font-heading);">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; background: rgba(249, 115, 22, 0.1); border-radius: 0.5rem; border: 1px solid rgba(249, 115, 22, 0.2); color: #f97316;">
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

</div>

<!-- CONTENT: BACKUPS -->
<div style="display: <?= $active_tab == 'backups' ? 'block' : 'none' ?>;">
    <div class="glass-card animate-slide-right hover-glow"
        style="border-radius: 1.5rem; overflow: hidden;">
        <div style="padding: 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <h3 style="font-size: 1.125rem; font-weight: 500; color: var(--text-primary); display: flex; align-items: center; gap: 0.75rem;">
                <div style="padding: 0.5rem; background: rgba(16,185,129,0.1); border-radius: 0.5rem; color: var(--accent-emerald);">
                    <i data-lucide="archive" style="width: 1.25rem; height: 1.25rem;"></i>
                </div>
                All Backups
            </h3>
            <span id="backup-count" style="font-size: 0.75rem; color: var(--text-secondary);"></span>
        </div>
        <div style="overflow-x: auto;" class="custom-scrollbar">
            <table style="width: 100%; text-align: left; border-collapse: collapse;">
                <thead style="background: var(--bg-body); font-size: 0.625rem; font-weight: 500; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.04em;">
                    <tr>
                        <th style="padding: 0.875rem 1.25rem;">Account</th>
                        <th style="padding: 0.875rem 1.25rem;">Filename</th>
                        <th style="padding: 0.875rem 1.25rem;">Size</th>
                        <th style="padding: 0.875rem 1.25rem;">Status</th>
                        <th style="padding: 0.875rem 1.25rem;">Created</th>
                    </tr>
                </thead>
                <tbody id="backup-list">
                    <tr><td colspan="5" style="padding: 2rem; text-align: center; color: var(--text-secondary);">Loading&hellip;</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'layout/footer.php'; ?>

<!-- CONTENT: PERMISSIONS -->
<div style="display: <?= $active_tab == 'permissions' ? 'block' : 'none' ?>;">
    <div class="glass-card animate-slide-right hover-glow"
        style="padding: 2rem; border-radius: 1.5rem; position: relative; overflow: hidden;">
        <div style="position: absolute; right: -2.5rem; top: -2.5rem; width: 10rem; height: 10rem; background: rgba(245, 158, 11, 0.1); border-radius: 9999px; filter: blur(24px);"></div>
        <h3 style="font-size: 1.25rem; font-weight: 500; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.75rem; color: var(--text-primary); font-family: var(--font-heading);">
            <div style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; background: rgba(245, 158, 11, 0.1); border-radius: 0.5rem; border: 1px solid rgba(245, 158, 11, 0.2); color: #f59e0b;">
                <i data-lucide="shield-check" style="width: 1.25rem; height: 1.25rem;"></i>
            </div>
            Fix Permissions — All Domains
        </h3>
        <p style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 1.5rem; line-height: 1.6;">
            Applies <code style="background:rgba(255,255,255,0.08);padding:0.1rem 0.4rem;border-radius:0.25rem;">chown www-data:www-data</code> and
            <code style="background:rgba(255,255,255,0.08);padding:0.1rem 0.4rem;border-radius:0.25rem;">chmod 755</code>
            to every domain's document root registered in the database.
        </p>
        <div style="padding: 0.875rem 1rem; border-radius: 0.75rem; background: rgba(245, 158, 11, 0.07); border: 1px solid rgba(245, 158, 11, 0.2); margin-bottom: 1.5rem; font-size: 0.8125rem; color: rgba(253, 186, 116, 0.9);">
            ⚠️ <strong>Requires root/sudo</strong> access via <code>shm-manage</code>. If not available, only <code>chmod</code> is applied via PHP as a fallback.
        </div>
        <button onclick="runFixPerms()" id="btn-fix-perms" class="btn btn-primary" style="padding: 0.875rem 2rem; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); display: inline-flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="zap" style="width: 1rem; height: 1rem;"></i>
            Fix All Domain Permissions
        </button>
        <div id="fix-perms-results" style="margin-top: 1.5rem; display: none;">
            <h4 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.75rem;">Results</h4>
            <div id="fix-perms-list" style="display: flex; flex-direction: column; gap: 0.5rem;"></div>
        </div>
    </div>
</div>
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
                            <td style="padding: 1rem; font-family: monospace; font-size: 0.75rem; color: var(--primary); font-weight: 500;">${u.userid}</td>
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

    async function loadBackups() {
        const list = document.getElementById('backup-list');
        if (!list) return;

        const fd = new FormData();
        fd.append('ajax_action', 'list_backups');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';

            if (res.data && res.data.length > 0) {
                document.getElementById('backup-count').textContent = res.data.length + ' backup(s)';

                const statusBadge = (s) => {
                    const cfg = {
                        completed: ['rgba(16,185,129,0.1)', '#10b981', 'check-circle'],
                        running:   ['rgba(59,130,246,0.1)',  '#3b82f6', 'loader'],
                        failed:    ['rgba(239,68,68,0.1)',   '#ef4444', 'alert-circle'],
                    };
                    const [bg, color, icon] = cfg[s] || ['rgba(100,100,100,0.1)', 'var(--text-secondary)', 'help-circle'];
                    return `<span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.2rem 0.65rem;border-radius:9999px;background:${bg};color:${color};font-size:0.7rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">
                                <i data-lucide="${icon}" style="width:0.75rem;height:0.75rem;"></i>${s}
                            </span>`;
                };

                res.data.forEach(b => {
                    const sizeTxt = b.size_mb ? parseFloat(b.size_mb).toFixed(1) + ' MB' : '—';
                    const date = b.created_at ? new Date(b.created_at).toLocaleString() : '—';
                    list.innerHTML += `
                        <tr style="border-bottom:1px solid var(--border-color);transition:background 0.15s;" onmouseover="this.style.background='var(--bg-body)'" onmouseout="this.style.background='transparent'">
                            <td style="padding:1rem 1.25rem;">
                                <span style="font-size:0.8125rem;font-family:monospace;font-weight:500;color:var(--text-primary);">${b.username ?? '—'}</span>
                            </td>
                            <td style="padding:1rem 1.25rem;font-family:monospace;font-size:0.75rem;color:var(--text-secondary);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${b.filename ?? ''}">${b.filename ?? '—'}</td>
                            <td style="padding:1rem 1.25rem;font-size:0.8125rem;color:var(--text-secondary);">${sizeTxt}</td>
                            <td style="padding:1rem 1.25rem;">${statusBadge(b.status ?? 'unknown')}</td>
                            <td style="padding:1rem 1.25rem;font-size:0.8125rem;color:var(--text-secondary);white-space:nowrap;">${date}</td>
                        </tr>
                    `;
                });
                lucide.createIcons();
            } else {
                document.getElementById('backup-count').textContent = '0 backups';
                list.innerHTML = '<tr><td colspan="5" style="padding:3rem;text-align:center;color:var(--text-secondary);font-style:italic;">No backups found.</td></tr>';
            }
        } catch (e) {
            list.innerHTML = '<tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--accent-red);">Error loading backups.</td></tr>';
        }
    }

    // Initial Load: only fetch data for the active tab
    document.addEventListener('DOMContentLoaded', () => {
        const activeTab = new URLSearchParams(window.location.search).get('tab') || 'ftp';
        if (activeTab === 'ftp')     loadFTP();
        if (activeTab === 'mail')    loadMail();
        if (activeTab === 'backups') loadBackups();
    });

    async function runFixPerms() {
        const btn = document.getElementById('btn-fix-perms');
        const resultsBox = document.getElementById('fix-perms-results');
        const list = document.getElementById('fix-perms-list');

        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader" style="width:1rem;height:1rem;animation:spin 1s linear infinite;"></i> Fixing permissions...';
        lucide.createIcons();
        resultsBox.style.display = 'none';
        list.innerHTML = '';

        const fd = new FormData();
        fd.append('ajax_action', 'fix_permissions');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            resultsBox.style.display = 'block';

            if (res.status === 'success' && res.results) {
                const statusCfg = {
                    ok:      { bg: 'rgba(16,185,129,0.08)', border: 'rgba(16,185,129,0.25)', color: '#10b981', icon: 'check-circle' },
                    partial: { bg: 'rgba(245,158,11,0.08)', border: 'rgba(245,158,11,0.25)', color: '#f59e0b', icon: 'alert-triangle' },
                    warning: { bg: 'rgba(245,158,11,0.08)', border: 'rgba(245,158,11,0.25)', color: '#f59e0b', icon: 'alert-triangle' },
                    skipped: { bg: 'rgba(100,116,139,0.08)', border: 'rgba(100,116,139,0.2)', color: 'var(--text-secondary)', icon: 'minus-circle' },
                };
                res.results.forEach(r => {
                    const cfg = statusCfg[r.status] || statusCfg.skipped;
                    list.innerHTML += `
                        <div style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1rem;border-radius:0.75rem;background:${cfg.bg};border:1px solid ${cfg.border};">
                            <i data-lucide="${cfg.icon}" style="width:1rem;height:1rem;color:${cfg.color};flex-shrink:0;"></i>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:0.875rem;font-weight:500;color:var(--text-primary);">${r.domain}</div>
                                <div style="font-size:0.75rem;color:var(--text-secondary);font-family:monospace;">${r.path ?? ''}</div>
                            </div>
                            <span style="font-size:0.75rem;color:${cfg.color};white-space:nowrap;">${r.msg}</span>
                        </div>`;
                });
                lucide.createIcons();
                const ok = res.results.filter(r => r.status === 'ok').length;
                const total = res.results.length;
                showToast('success', `Fixed ${ok} of ${total} domains`);
            } else {
                showToast('error', res.msg || 'Fix failed');
            }
        } catch (e) {
            showToast('error', 'Communication error: ' + e.message);
        }

        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="zap" style="width:1rem;height:1rem;"></i> Fix All Domain Permissions';
        lucide.createIcons();
    }

</script>