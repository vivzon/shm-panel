<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['client'];
$cid = $_SESSION['cid'];

// -------- BACKEND HANDLERS --------
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
        // --- APPS HANDLER ---
        if ($action == 'install_app') {
            $app = $_POST['app'];
            $dom_id = (int)$_POST['domain_id'];
            $domain = $pdo->prepare("SELECT domain FROM domains WHERE id=? AND client_id=?")->execute([$dom_id, $cid]) ? $pdo->query("SELECT domain FROM domains WHERE id=$dom_id AND client_id=$cid")->fetchColumn() : false;
            if (!$domain)
                throw new Exception("Invalid Domain");

            $rand = substr(md5(uniqid()), 0, 6);
            $db_name = $username . "_wp_" . $rand;
            $db_user = $username . "_" . $rand;
            $db_pass = bin2hex(random_bytes(8));

            $stmt = $pdo->prepare("INSERT INTO app_installations (client_id, domain_id, app_type, db_name, db_user, db_pass, status) VALUES (?, ?, ?, ?, ?, ?, 'installing')");
            $stmt->execute([$cid, $dom_id, $app, $db_name, $db_user, $db_pass]);

            $cmd = "app-tool install " . escapeshellarg($app) . " " . escapeshellarg($domain) . " " . escapeshellarg($db_name) . " " . escapeshellarg($db_user) . " " . escapeshellarg($db_pass);
            cmd($cmd);

            $res['msg'] = ucfirst($app) . ' installation started';
            echo json_encode($res);
            exit;
        }

        if ($action == 'list_apps') {
            $stmt = $pdo->prepare("SELECT a.*, d.domain FROM app_installations a JOIN domains d ON a.domain_id = d.id WHERE a.client_id = ? ORDER BY a.created_at DESC");
            $stmt->execute([$cid]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // --- FTP HANDLERS ---
        if ($action == 'add_ftp') {
            if ($_POST['pass'] !== $_POST['pass2'])
                throw new Exception("Passwords do not match");

            $ftp_user = strtolower($_POST['ftp_user'] . '@' . $username);
            $pass = $_POST['pass'];
            $home = "/var/www/clients/$username/public_html" . ($_POST['dir'] ? '/' . trim($_POST['dir'], '/') : '');

            $check = $pdo->prepare("SELECT count(*) FROM ftp_users WHERE userid = ?");
            $check->execute([$ftp_user]);
            if ($check->fetchColumn() > 0)
                throw new Exception("FTP User already exists");

            cmd("ftp-tool add-user " . escapeshellarg($username) . " " . escapeshellarg($ftp_user) . " " . escapeshellarg($pass) . " " . escapeshellarg($home));
            $res['msg'] = "FTP account '$ftp_user' created";
            echo json_encode($res);
            exit;
        }

        if ($action == 'del_ftp') {
            $userToDelete = $_POST['user'];
            if (!str_ends_with($userToDelete, "@$username"))
                throw new Exception("Permission Denied");
            
            cmd("ftp-tool delete-user " . escapeshellarg($userToDelete));
            $res['msg'] = 'FTP account deleted';
            echo json_encode($res);
            exit;
        }

        if ($action == 'list_ftp') {
            $stmt = $pdo->prepare("SELECT userid, homedir FROM ftp_users WHERE userid LIKE ?");
            $stmt->execute(["%@$username"]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // --- SECURITY HANDLERS ---
        if ($action == 'add_ssh') {
            $key = trim($_POST['key'] ?? '');
            if (empty($key)) throw new Exception('SSH key cannot be empty');
            cmd("ssh-key add " . escapeshellarg($username) . " " . escapeshellarg($key));
            $res['msg'] = 'SSH key added';
            echo json_encode($res);
            exit;
        }
        if ($action == 'del_ssh') {
            cmd("ssh-key delete " . escapeshellarg($username) . " " . (int) $_POST['line']);
            $res['msg'] = 'SSH key deleted';
            echo json_encode($res);
            exit;
        }
        if ($action == 'list_ssh') {
            $out = cmd("ssh-key list " . escapeshellarg($username));
            $lines = array_values(array_filter(explode("\n", $out)));
            echo json_encode(['status' => 'success', 'data' => $lines]);
            exit;
        }

        if ($action == 'fix_perms') {
            cmd("fix-permissions " . escapeshellarg($username));
            $res['msg'] = 'Permissions fixed';
            echo json_encode($res);
            exit;
        }

        // --- BACKUP HANDLERS ---
        if ($action == 'create_backup') {
            cmd("backup create " . escapeshellarg($username));
            $res['msg'] = 'Backup started';
            echo json_encode($res);
            exit;
        }
        if ($action == 'list_backups') {
            $stmt = $pdo->prepare("
                SELECT b.id, b.filename, b.size_mb, b.status, b.created_at
                FROM backups b
                WHERE b.client_id = ?
                ORDER BY b.created_at DESC LIMIT 50
            ");
            $stmt->execute([$cid]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
        if ($action == 'restore_backup') {
            $file = shm_clean($_POST['file'] ?? '');
            if (!$file) throw new Exception('No backup file specified');
            cmd("backup restore " . escapeshellarg($username) . " " . escapeshellarg($file));
            $res['msg'] = 'Restore started';
            echo json_encode($res);
            exit;
        }

        // --- TROUBLESHOOT HANDLERS ---
        if ($action == 'fix_website' || $action == 'restart_services' || $action == 'fix_config') {
            $did = (int) $_POST['domain_id'];
            $chk = $pdo->prepare("SELECT id, domain FROM domains WHERE id=? AND client_id=?");
            $chk->execute([$did, $cid]);
            $domainData = $chk->fetch();
            if (!$domainData)
                throw new Exception("Access Denied");

            cmd("troubleshoot fix-perms $did");
            cmd("troubleshoot fix-default-page $did");
            cmd("troubleshoot reload-services $did");

            if ($action == 'fix_config') {
                $domain = $domainData['domain'];
                cmd("troubleshoot fix-config $domain");
                $res['msg'] = 'Configuration fixes applied.';
            } else {
                $res['msg'] = 'Action completed.';
            }
            echo json_encode($res);
            exit;
        }

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// -------- FRONTEND DATA --------
$active_tab = $_GET['tab'] ?? 'apps';
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();

include 'layout/header.php';
?>

<!-- Dashboard Header -->
<div style="margin-bottom: 2rem;">
    <h2 style="font-size: 1.5rem; line-height: 2rem; font-weight: 500; color: var(--slate-900); margin-bottom: 0.5rem;">
        System Tools</h2>
    <p style="color: var(--slate-700); font-size: 0.875rem;">Manage applications, security, and backups.</p>
</div>

<!-- TABS -->
<div style="display: flex; border-bottom: 1px solid var(--slate-300); margin-bottom: 2rem; overflow-x: auto;">
    <a href="?tab=apps"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'apps' ? '#3b82f6' : 'transparent' ?>; color: <?= $active_tab == 'apps' ? 'var(--slate-900)' : 'var(--slate-700)' ?>; transition: all 0.2s; white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--slate-900)'"
        onmouseout="this.style.color='<?= $active_tab == 'apps' ? 'var(--slate-900)' : 'var(--slate-700)' ?>'">App
        Installer</a>
    <a href="?tab=ftp"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'ftp' ? '#3b82f6' : 'transparent' ?>; color: <?= $active_tab == 'ftp' ? 'var(--slate-900)' : 'var(--slate-700)' ?>; transition: all 0.2s; white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--slate-900)'"
        onmouseout="this.style.color='<?= $active_tab == 'ftp' ? 'var(--slate-900)' : 'var(--slate-700)' ?>'">FTP
        Manager</a>
    <a href="?tab=security"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'security' ? '#3b82f6' : 'transparent' ?>; color: <?= $active_tab == 'security' ? 'var(--slate-900)' : 'var(--slate-700)' ?>; transition: all 0.2s; white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--slate-900)'"
        onmouseout="this.style.color='<?= $active_tab == 'security' ? 'var(--slate-900)' : 'var(--slate-700)' ?>'">Security
        (SSH)</a>
    <a href="?tab=backups"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'backups' ? '#3b82f6' : 'transparent' ?>; color: <?= $active_tab == 'backups' ? 'var(--slate-900)' : 'var(--slate-700)' ?>; transition: all 0.2s; white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--slate-900)'"
        onmouseout="this.style.color='<?= $active_tab == 'backups' ? 'var(--slate-900)' : 'var(--slate-700)' ?>'">Backups</a>
    <a href="?tab=troubleshoot"
        style="padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-bottom: 2px solid <?= $active_tab == 'troubleshoot' ? '#10b981' : 'transparent' ?>; color: <?= $active_tab == 'troubleshoot' ? 'var(--slate-900)' : 'var(--slate-700)' ?>; transition: all 0.2s; white-space: nowrap; text-decoration: none;"
        onmouseover="this.style.color='var(--slate-900)'"
        onmouseout="this.style.color='<?= $active_tab == 'troubleshoot' ? 'var(--slate-900)' : 'var(--slate-700)' ?>'">Troubleshoot</a>
</div>

<!-- APPS TAB -->
<div id="tab-apps" style="display: <?= $active_tab == 'apps' ? 'block' : 'none' ?>;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
        <!-- Install Form -->
        <div class="glass-card" style="padding: 2rem; height: fit-content;">
            <h3
                style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="download-cloud" style="width: 20px; height: 20px; color: var(--primary);"></i> Install
                Application</h3>
            <form onsubmit="handleAppInstall(event)" style="display: flex; flex-direction: column; gap: 1.25rem;">
                <div>
                    <label
                        style="font-size: 0.75rem; color: var(--slate-500); text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; display: block; margin-bottom: 0.5rem;">Select
                        Domain</label>
                    <select name="domain_id" class="form-select"
                        style="width: 100%; background-color: rgba(255, 255, 255, 0.5); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: var(--radius-lg); padding: 0.875rem 1rem; color: var(--slate-900); font-size: 0.875rem; outline: none; transition: all 0.2s;"
                        onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                        onblur="this.style.borderColor='rgba(255, 255, 255, 0.4)'; this.style.boxShadow='none';">
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= $d['domain'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label
                        style="font-size: 0.75rem; color: var(--slate-500); text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; display: block; margin-bottom: 0.5rem;">Application</label>
                    <select name="app" class="form-select"
                        style="width: 100%; background-color: rgba(255, 255, 255, 0.5); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: var(--radius-lg); padding: 0.875rem 1rem; color: var(--slate-900); font-size: 0.875rem; outline: none; transition: all 0.2s;"
                        onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                        onblur="this.style.borderColor='rgba(255, 255, 255, 0.4)'; this.style.boxShadow='none';">
                        <option value="wordpress">WordPress</option>
                        <option value="laravel">Laravel</option>
                        <option value="codeigniter">CodeIgniter 4</option>
                        <option value="react">React (Vite)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"
                    style="width: 100%; padding: 0.875rem; border-radius: var(--radius-lg); display: flex; justify-content: center; font-weight: 500; margin-top: 0.5rem; transition: transform 0.2s, box-shadow 0.2s;">
                    Install Now
                </button>
            </form>
        </div>

        <!-- Recent Installations -->
        <div class="glass-card table-card" style="padding: 0; overflow: hidden; grid-column: span 2;">
            <div
                style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--slate-200); background-color: var(--slate-50); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading);">Recent
                    Installations</h3>
                <button onclick="loadApps()"
                    style="color: var(--slate-500); background: transparent; border: none; cursor: pointer; padding: 0.5rem; border-radius: var(--radius-md); transition: all 0.2s;"
                    onmouseover="this.style.color='var(--slate-900)'; this.style.backgroundColor='var(--slate-100)';"
                    onmouseout="this.style.color='var(--slate-500)'; this.style.backgroundColor='transparent';"><i
                        data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i></button>
            </div>
            <div class="table-container custom-scrollbar">
                <table class="modern-table w-full text-left border-collapse" style="width: 100%;">
                    <thead
                        style="background-color: var(--slate-50); font-size: 0.75rem; text-transform: uppercase; color: var(--slate-500); font-weight: 800; letter-spacing: 0.05em; border-bottom: 1px solid var(--slate-200);">
                        <tr>
                            <th style="padding: 1rem 1.5rem;">App</th>
                            <th style="padding: 1rem 1.5rem;">Domain</th>
                            <th style="padding: 1rem 1.5rem;">Status</th>
                            <th style="padding: 1rem 1.5rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="app-list">
                        <tr>
                            <td colspan="4" style="padding: 3rem 1.5rem; text-align: center; color: var(--slate-500);">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                    <i data-lucide="loader-2" class="animate-spin"
                                        style="width: 24px; height: 24px; color: var(--primary);"></i>
                                    <span style="font-size: 0.875rem; font-weight: 500;">Loading installations...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- FTP TAB -->
<div id="tab-ftp" style="display: <?= $active_tab == 'ftp' ? 'block' : 'none' ?>;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
        <!-- Add FTP Form -->
        <div class="glass-card" style="padding: 2rem; height: fit-content;">
            <h3
                style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="folder-key" style="width: 20px; height: 20px; color: var(--primary);"></i> Create FTP
                Account</h3>
            <form onsubmit="handleFTPAdd(event)" style="display: flex; flex-direction: column; gap: 1.25rem;">
                <div>
                    <label
                        style="font-size: 0.75rem; color: var(--slate-500); text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; display: block; margin-bottom: 0.5rem;">Username</label>
                    <div style="display: flex; align-items: center; background-color: rgba(255, 255, 255, 0.5); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: var(--radius-lg); overflow: hidden; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"
                        onfocusin="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                        onfocusout="this.style.borderColor='rgba(255, 255, 255, 0.4)'; this.style.boxShadow='inset 0 2px 4px rgba(0,0,0,0.02)';">
                        <input name="ftp_user" required placeholder="user"
                            style="background: transparent; padding: 0.875rem 1rem; width: 100%; color: var(--slate-900); font-size: 0.875rem; outline: none; border: none;">
                        <span
                            style="padding: 0.875rem 1rem; color: var(--slate-500); font-weight: 500; background-color: rgba(248, 250, 252, 0.5); border-left: 1px solid rgba(255, 255, 255, 0.4);">@<?= $username ?></span>
                    </div>
                </div>
                <div>
                    <label
                        style="font-size: 0.75rem; color: var(--slate-500); text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; display: block; margin-bottom: 0.5rem;">Password</label>
                    <input type="password" name="pass" required
                        style="width: 100%; background-color: rgba(255, 255, 255, 0.5); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: var(--radius-lg); padding: 0.875rem 1rem; color: var(--slate-900); font-size: 0.875rem; outline: none; margin-bottom: 0.75rem; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"
                        onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                        onblur="this.style.borderColor='rgba(255, 255, 255, 0.4)'; this.style.boxShadow='inset 0 2px 4px rgba(0,0,0,0.02)';"
                        placeholder="Password">
                    <input type="password" name="pass2" required
                        style="width: 100%; background-color: rgba(255, 255, 255, 0.5); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: var(--radius-lg); padding: 0.875rem 1rem; color: var(--slate-900); font-size: 0.875rem; outline: none; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"
                        onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                        onblur="this.style.borderColor='rgba(255, 255, 255, 0.4)'; this.style.boxShadow='inset 0 2px 4px rgba(0,0,0,0.02)';"
                        placeholder="Confirm Password">
                </div>
                <div>
                    <label
                        style="font-size: 0.75rem; color: var(--slate-500); text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; display: block; margin-bottom: 0.5rem;">Directory
                        (Optional)</label>
                    <input name="dir" placeholder="/public_html"
                        style="width: 100%; background-color: rgba(255, 255, 255, 0.5); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: var(--radius-lg); padding: 0.875rem 1rem; color: var(--slate-900); font-size: 0.875rem; outline: none; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"
                        onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                        onblur="this.style.borderColor='rgba(255, 255, 255, 0.4)'; this.style.boxShadow='inset 0 2px 4px rgba(0,0,0,0.02)';">
                </div>
                <button type="submit" class="btn btn-primary"
                    style="width: 100%; padding: 0.875rem; border-radius: var(--radius-lg); display: flex; justify-content: center; font-weight: 500; margin-top: 0.5rem; transition: transform 0.2s, box-shadow 0.2s;">
                    Create FTP User
                </button>
            </form>
        </div>

        <!-- FTP List -->
        <div class="glass-card table-card" style="padding: 0; overflow: hidden; grid-column: span 2;">
            <div
                style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--slate-200); background-color: var(--slate-50); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading);">FTP Accounts
                </h3>
                <button onclick="loadFTP()"
                    style="color: var(--slate-500); background: transparent; border: none; cursor: pointer; padding: 0.5rem; border-radius: var(--radius-md); transition: all 0.2s;"
                    onmouseover="this.style.color='var(--slate-900)'; this.style.backgroundColor='var(--slate-100)';"
                    onmouseout="this.style.color='var(--slate-500)'; this.style.backgroundColor='transparent';"><i
                        data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i></button>
            </div>
            <div class="table-container custom-scrollbar">
                <table class="modern-table w-full text-left border-collapse" style="width: 100%;">
                    <thead
                        style="background-color: var(--slate-50); font-size: 0.75rem; text-transform: uppercase; color: var(--slate-500); font-weight: 800; letter-spacing: 0.05em; border-bottom: 1px solid var(--slate-200);">
                        <tr>
                            <th style="padding: 1rem 1.5rem;">Username</th>
                            <th style="padding: 1rem 1.5rem;">Home Directory</th>
                            <th style="padding: 1rem 1.5rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="ftp-list">
                        <tr>
                            <td colspan="3" style="padding: 3rem 1.5rem; text-align: center; color: var(--slate-500);">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                    <i data-lucide="loader-2" class="animate-spin"
                                        style="width: 24px; height: 24px; color: var(--primary);"></i>
                                    <span style="font-size: 0.875rem; font-weight: 500;">Loading FTP accounts...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- SECURITY & BACKUPS (Placeholders for now, to be implemented similarly) -->
<div id="tab-security" style="display: <?= $active_tab == 'security' ? 'block' : 'none' ?>">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
        <!-- Add SSH Key -->
        <div class="glass-card" style="padding: 2rem; height: fit-content;">
            <h3 style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); font-size: 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="key" style="width: 20px; height: 20px; color: var(--primary);"></i> Add SSH Key
            </h3>
            <form onsubmit="handleToolAction(event, 'add_ssh', loadSSH)" style="display: flex; flex-direction: column; gap: 1rem;">
                <textarea name="key" required placeholder="Paste your public key here (ssh-rsa AAAA...)"
                    style="width: 100%; min-height: 120px; background-color: rgba(255,255,255,0.5); border: 1px solid rgba(255,255,255,0.4); border-radius: var(--radius-lg); padding: 0.875rem 1rem; font-family: monospace; font-size: 0.8rem; color: var(--slate-900); outline: none; resize: vertical;"
                    onfocus="this.style.borderColor='var(--primary)'"
                    onblur="this.style.borderColor='rgba(255,255,255,0.4)'"></textarea>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.875rem; border-radius: var(--radius-lg);">Add SSH Key</button>
            </form>
        </div>
        <!-- SSH Key List -->
        <div class="glass-card table-card" style="padding: 0; overflow: hidden; grid-column: span 2;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--slate-200); background-color: var(--slate-50); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading);">Authorized Keys</h3>
                <button onclick="loadSSH()" style="color: var(--slate-500); background: transparent; border: none; cursor: pointer; padding: 0.5rem; border-radius: var(--radius-md); transition: all 0.2s;" onmouseover="this.style.color='var(--slate-900)'; this.style.backgroundColor='var(--slate-100)';" onmouseout="this.style.color='var(--slate-500)'; this.style.backgroundColor='transparent';">
                    <i data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i>
                </button>
            </div>
            <div class="table-container custom-scrollbar">
                <table style="width: 100%; text-align: left; border-collapse: collapse;">
                    <thead style="background-color: var(--slate-50); font-size: 0.75rem; text-transform: uppercase; color: var(--slate-500); font-weight: 800; letter-spacing: 0.05em; border-bottom: 1px solid var(--slate-200);">
                        <tr>
                            <th style="padding: 1rem 1.5rem;">#</th>
                            <th style="padding: 1rem 1.5rem;">Key</th>
                            <th style="padding: 1rem 1.5rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="ssh-list">
                        <tr><td colspan="3" style="padding: 2rem; text-align: center; color: var(--slate-500);">Loading&hellip;</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="tab-backups" style="display: <?= $active_tab == 'backups' ? 'block' : 'none' ?>">
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
        <!-- Create Backup -->
        <div class="glass-card" style="padding: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
            <div>
                <h3 style="font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">Create Backup</h3>
                <p style="font-size: 0.8125rem; color: var(--slate-500);">Creates a full backup of your account files and databases.</p>
            </div>
            <button onclick="createBackup()" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem;">
                <i data-lucide="archive" style="width: 1rem; height: 1rem;"></i> Create Backup Now
            </button>
        </div>
        <!-- Backup List -->
        <div class="glass-card table-card" style="padding: 0; overflow: hidden;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--slate-200); background-color: var(--slate-50); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading);">Your Backups</h3>
                <button onclick="loadBackups()" style="color: var(--slate-500); background: transparent; border: none; cursor: pointer; padding: 0.5rem; border-radius: var(--radius-md); transition: all 0.2s;" onmouseover="this.style.color='var(--slate-900)'; this.style.backgroundColor='var(--slate-100)';" onmouseout="this.style.color='var(--slate-500)'; this.style.backgroundColor='transparent';">
                    <i data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i>
                </button>
            </div>
            <div class="table-container custom-scrollbar">
                <table style="width: 100%; text-align: left; border-collapse: collapse;">
                    <thead style="background-color: var(--slate-50); font-size: 0.75rem; text-transform: uppercase; color: var(--slate-500); font-weight: 800; letter-spacing: 0.05em; border-bottom: 1px solid var(--slate-200);">
                        <tr>
                            <th style="padding: 1rem 1.5rem;">Filename</th>
                            <th style="padding: 1rem 1.5rem;">Size</th>
                            <th style="padding: 1rem 1.5rem;">Status</th>
                            <th style="padding: 1rem 1.5rem;">Created</th>
                            <th style="padding: 1rem 1.5rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="backup-list">
                        <tr><td colspan="5" style="padding: 2rem; text-align: center; color: var(--slate-500);">Loading&hellip;</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="tab-troubleshoot" style="display: <?= $active_tab == 'troubleshoot' ? 'block' : 'none' ?>;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
        <!-- Existing Troubleshoot Buttons -->
        <div class="glass-card"
            style="padding: 2.5rem 2rem; background: linear-gradient(135deg, rgba(49, 46, 129, 0.1) 0%, rgba(49, 46, 129, 0.02) 100%); border-color: rgba(99, 102, 241, 0.15); display: flex; flex-direction: column; align-items: center; text-align: center; gap: 1rem;">
            <div style="width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(99, 102, 241, 0.05) 100%); display: flex; align-items: center; justify-content: center; box-shadow: inset 0 2px 4px rgba(255,255,255,0.5);">
                <i data-lucide="wand-2" style="width: 32px; height: 32px; color: #6366f1;"></i>
            </div>
            <div>
                <h3 style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); font-size: 1.125rem; margin-bottom: 0.25rem;">Display Doctor</h3>
                <p style="font-size: 0.75rem; color: var(--slate-500); line-height: 1.4;">Fixes common website display issues.</p>
            </div>
            <button onclick="fixWebsite()"
                style="width: 100%; padding: 0.875rem; margin-top: 0.5rem; background-color: #4f46e5; color: white; border-radius: var(--radius-lg); font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: all 0.2s; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2), 0 2px 4px -1px rgba(79, 70, 229, 0.1); border: none; cursor: pointer;"
                onmouseover="this.style.backgroundColor='#4338ca'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 15px -3px rgba(79, 70, 229, 0.3), 0 4px 6px -2px rgba(79, 70, 229, 0.15)';"
                onmouseout="this.style.backgroundColor='#4f46e5'; this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(79, 70, 229, 0.2), 0 2px 4px -1px rgba(79, 70, 229, 0.1)';">
                <i data-lucide="wand-2" style="width: 1.25rem; height: 1.25rem;"></i> Fix Website Display
            </button>
        </div>

        <div class="glass-card"
            style="padding: 2.5rem 2rem; background: linear-gradient(135deg, rgba(15, 23, 42, 0.1) 0%, rgba(15, 23, 42, 0.02) 100%); border-color: rgba(71, 85, 105, 0.15); display: flex; flex-direction: column; align-items: center; text-align: center; gap: 1rem;">
            <div style="width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, rgba(71, 85, 105, 0.1) 0%, rgba(71, 85, 105, 0.05) 100%); display: flex; align-items: center; justify-content: center; box-shadow: inset 0 2px 4px rgba(255,255,255,0.5);">
                <i data-lucide="power" style="width: 32px; height: 32px; color: #475569;"></i>
            </div>
            <div>
                <h3 style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); font-size: 1.125rem; margin-bottom: 0.25rem;">Restart Services</h3>
                <p style="font-size: 0.75rem; color: var(--slate-500); line-height: 1.4;">Safely reloads backend web services.</p>
            </div>
            <button onclick="restartServices()"
                style="width: 100%; padding: 0.875rem; margin-top: 0.5rem; background-color: #334155; color: white; border-radius: var(--radius-lg); font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: all 0.2s; box-shadow: 0 4px 6px -1px rgba(51, 65, 85, 0.2), 0 2px 4px -1px rgba(51, 65, 85, 0.1); border: none; cursor: pointer;"
                onmouseover="this.style.backgroundColor='#1e293b'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 15px -3px rgba(51, 65, 85, 0.3), 0 4px 6px -2px rgba(51, 65, 85, 0.15)';"
                onmouseout="this.style.backgroundColor='#334155'; this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(51, 65, 85, 0.2), 0 2px 4px -1px rgba(51, 65, 85, 0.1)';">
                <i data-lucide="power" style="width: 1.25rem; height: 1.25rem;"></i> Restart Services
            </button>
        </div>

        <!-- NEW FIX CONFIG BUTTON -->
        <div class="glass-card"
            style="padding: 2.5rem 2rem; background: linear-gradient(135deg, rgba(225, 29, 72, 0.1) 0%, rgba(225, 29, 72, 0.02) 100%); border-color: rgba(244, 63, 94, 0.15); display: flex; flex-direction: column; align-items: center; text-align: center; gap: 1rem;">
             <div style="width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, rgba(244, 63, 94, 0.1) 0%, rgba(244, 63, 94, 0.05) 100%); display: flex; align-items: center; justify-content: center; box-shadow: inset 0 2px 4px rgba(255,255,255,0.5);">
                <i data-lucide="wrench" style="width: 32px; height: 32px; color: #f43f5e;"></i>
            </div>
            <div>
                <h3 style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); font-size: 1.125rem; margin-bottom: 0.25rem;">Fix Config Issues</h3>
                <p style="font-size: 0.75rem; color: var(--slate-500); line-height: 1.4;">Rebuilds server configuration files.</p>
            </div>
            <button onclick="fixConfig()"
                style="width: 100%; padding: 0.875rem; margin-top: 0.5rem; background-color: #e11d48; color: white; border-radius: var(--radius-lg); font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: all 0.2s; box-shadow: 0 4px 6px -1px rgba(225, 29, 72, 0.2), 0 2px 4px -1px rgba(225, 29, 72, 0.1); border: none; cursor: pointer;"
                onmouseover="this.style.backgroundColor='#be123c'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 15px -3px rgba(225, 29, 72, 0.3), 0 4px 6px -2px rgba(225, 29, 72, 0.15)';"
                onmouseout="this.style.backgroundColor='#e11d48'; this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(225, 29, 72, 0.2), 0 2px 4px -1px rgba(225, 29, 72, 0.1)';">
                <i data-lucide="wrench" style="width: 1.25rem; height: 1.25rem;"></i> Fix Config
            </button>
        </div>
    </div>
</div>

<script>
    const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Generic Tool Action Handler
    async function handleToolAction(e, action, callback = null) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader-2" style="width: 1rem; height: 1rem; display: inline-block; margin-right: 0.5rem; vertical-align: middle; animation: spin 1s linear infinite;"></i> Processing...`;
        lucide.createIcons();

        const fd = new FormData(e.target);
        fd.append('ajax_action', action);
        fd.append('csrf_token', CSRF());

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', res.msg || 'Success');
                e.target.reset();
                if (callback) callback();
            } else {
                showToast('error', res.msg || 'Error');
            }
        } catch (err) {
            showToast('error', 'System Error');
            console.error(err);
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    // Apps Logic
    function handleAppInstall(e) {
        handleToolAction(e, 'install_app', () => {
            loadApps();
            if (!window.appPoll) window.appPoll = setInterval(loadApps, 5000);
        });
    }

    async function loadApps() {
        const tbody = document.getElementById('app-list');
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'list_apps');
            fd.append('csrf_token', CSRF());
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());

            if (res.status === 'success' && res.data.length > 0) {
                tbody.innerHTML = res.data.map(app => `
<tr style="border-bottom: 1px solid var(--slate-100); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='var(--slate-50)'" onmouseout="this.style.backgroundColor='transparent'">
    <td style="padding: 1.25rem 1.5rem; font-weight: 800; color: var(--slate-900); text-transform: capitalize; font-size: 0.875rem;">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="box" style="width: 16px; height: 16px; color: var(--slate-400);"></i>
            ${app.app_type}
        </div>
    </td>
    <td style="padding: 1.25rem 1.5rem; color: var(--slate-700); font-size: 0.875rem; font-weight: 500;">${app.domain}</td>
    <td style="padding: 1.25rem 1.5rem;">
        <span style="padding: 0.25rem 0.625rem; border-radius: 9999px; font-size: 0.6875rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; display: inline-flex; align-items: center; gap: 0.25rem; ${app.status === 'active' ? 'background-color: rgba(16,185,129,0.1); color: #059669; border: 1px solid rgba(16,185,129,0.2);' : (app.status === 'failed' ? 'background-color: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.2);' : 'background-color: rgba(59,130,246,0.1); color: #2563eb; border: 1px solid rgba(59,130,246,0.2);')}">
            ${app.status === 'installing' ? '<i data-lucide="loader-2" class="animate-spin" style="width: 10px; height: 10px;"></i>' : ''}
            ${app.status}
        </span>
    </td>
    <td style="padding: 1.25rem 1.5rem; text-align: right;">
        ${app.status === 'active' ? `<a href="http://${app.domain}" target="_blank" style="padding: 0.5rem; color: var(--primary); background: rgba(37,99,235,0.1); border: 1px solid rgba(37,99,235,0.2); border-radius: var(--radius-md); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center;"><i data-lucide="external-link" style="width: 16px; height: 16px;"></i></a>` : ''}
    </td>
</tr>`).join('');
                lucide.createIcons();
            } else {
                tbody.innerHTML = `<tr><td colspan="4" style="padding: 3rem 1.5rem; text-align: center; color: var(--slate-500);">
    <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
        <i data-lucide="inbox" style="width: 48px; height: 48px; opacity: 0.5;"></i>
        <span>No recent installations found.</span>
    </div></td></tr>`;
                lucide.createIcons();
            }
        } catch (e) { console.error(e); }
    }

    // FTP Logic
    function handleFTPAdd(e) { handleToolAction(e, 'add_ftp', loadFTP); }

    async function loadFTP() {
        const tbody = document.getElementById('ftp-list');
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'list_ftp');
            fd.append('csrf_token', CSRF());
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());

            if (res.status === 'success' && res.data.length > 0) {
                tbody.innerHTML = res.data.map(user => `
<tr style="border-bottom: 1px solid var(--slate-100); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='var(--slate-50)'" onmouseout="this.style.backgroundColor='transparent'">
    <td style="padding: 1.25rem 1.5rem; font-weight: 800; color: var(--slate-900); font-size: 0.875rem;">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="user" style="width: 16px; height: 16px; color: var(--slate-400);"></i>
            ${user.userid}
        </div>
    </td>
    <td style="padding: 1.25rem 1.5rem; color: var(--primary); font-family: monospace; font-size: 0.8125rem; font-weight: 500;">${user.homedir}</td>
    <td style="padding: 1.25rem 1.5rem; text-align: right;">
        <button onclick="delFTP('${user.userid}')" style="padding: 0.5rem; color: var(--accent-red); background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); border-radius: var(--radius-md); transition: all 0.2s; cursor: pointer; display: inline-flex;" onmouseover="this.style.backgroundColor='rgba(239,68,68,0.2)';" onmouseout="this.style.backgroundColor='rgba(239,68,68,0.1)';"><i data-lucide="trash-2" style="width: 16px; height: 16px;"></i></button>
    </td>
</tr>`).join('');
                lucide.createIcons();
            } else {
                tbody.innerHTML = `<tr><td colspan="3" style="padding: 3rem; text-align: center; color: var(--slate-500);">No FTP accounts found.</td></tr>`;
            }
        } catch (e) { console.error(e); }
    }

    async function delFTP(user) {
        if (!confirm('Delete FTP user ' + user + '?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'del_ftp');
        fd.append('user', user);
        fd.append('csrf_token', CSRF());
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast(res.status === 'success' ? 'success' : 'error', res.msg || 'FTP User Deleted');
        loadFTP();
    }

    // SSH Logic
    async function loadSSH() {
        const tbody = document.getElementById('ssh-list');
        if (!tbody) return;
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'list_ssh');
            fd.append('csrf_token', CSRF());
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success' && res.data.length > 0) {
                tbody.innerHTML = res.data.map((key, i) => `
<tr style="border-bottom:1px solid var(--slate-100);">
    <td style="padding:1rem 1.5rem; color:var(--slate-500); font-size:0.75rem; font-weight:800;">${i + 1}</td>
    <td style="padding:1rem 1.5rem; font-family:monospace; font-size:0.75rem; color:var(--slate-700); max-width:400px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${key}">${key}</td>
    <td style="padding:1rem 1.5rem; text-align:right;">
        <button onclick="delSSH(${i + 1})" style="padding:0.5rem; color:var(--accent-red); background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.2); border-radius:var(--radius-md); cursor:pointer; display:inline-flex;"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button>
    </td>
</tr>`).join('');
                lucide.createIcons();
            } else {
                tbody.innerHTML = `<tr><td colspan="3" style="padding:3rem;text-align:center;color:var(--slate-500);">No SSH keys found.</td></tr>`;
            }
        } catch(e) { console.error(e); }
    }

    async function delSSH(line) {
        if (!confirm('Delete SSH key #' + line + '?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'del_ssh');
        fd.append('line', line);
        fd.append('csrf_token', CSRF());
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast(res.status === 'success' ? 'success' : 'error', res.msg || 'SSH key deleted');
        loadSSH();
    }

    // Backup Logic
    async function createBackup() {
        if (!confirm('Create a full backup of your account now?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'create_backup');
        fd.append('csrf_token', CSRF());
        showToast('info', 'Backup started…');
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast(res.status === 'success' ? 'success' : 'error', res.msg);
        setTimeout(loadBackups, 2000);
    }

    async function loadBackups() {
        const tbody = document.getElementById('backup-list');
        if (!tbody) return;
        const fd = new FormData();
        fd.append('ajax_action', 'list_backups');
        fd.append('csrf_token', CSRF());
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success' && res.data.length > 0) {
                const badge = (s) => {
                    const cfg = { completed:['rgba(16,185,129,0.1)','#059669'], running:['rgba(59,130,246,0.1)','#2563eb'], failed:['rgba(239,68,68,0.1)','#dc2626'] };
                    const [bg, color] = cfg[s] || ['rgba(100,100,100,0.1)','var(--slate-500)'];
                    return `<span style="padding:0.2rem 0.6rem;border-radius:9999px;background:${bg};color:${color};font-size:0.65rem;font-weight:800;text-transform:uppercase;">${s}</span>`;
                };
                tbody.innerHTML = res.data.map(b => `
<tr style="border-bottom:1px solid var(--slate-100);transition:background 0.2s;" onmouseover="this.style.backgroundColor='var(--slate-50)'" onmouseout="this.style.backgroundColor='transparent'">
    <td style="padding:1rem 1.5rem;font-family:monospace;font-size:0.75rem;color:var(--slate-700);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${b.filename??''}">${b.filename??'—'}</td>
    <td style="padding:1rem 1.5rem;font-size:0.8125rem;color:var(--slate-500);">${b.size_mb ? parseFloat(b.size_mb).toFixed(1)+' MB' : '—'}</td>
    <td style="padding:1rem 1.5rem;">${badge(b.status??'unknown')}</td>
    <td style="padding:1rem 1.5rem;font-size:0.8125rem;color:var(--slate-500);white-space:nowrap;">${b.created_at ? new Date(b.created_at).toLocaleString() : '—'}</td>
    <td style="padding:1rem 1.5rem;text-align:right;">
        ${b.status==='completed' && b.filename ? `<button onclick="restoreBackup('${b.filename}')" style="padding:0.4rem 0.875rem;font-size:0.75rem;font-weight:600;color:#2563eb;background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.2);border-radius:var(--radius-md);cursor:pointer;transition:all 0.2s;" onmouseover="this.style.background='rgba(59,130,246,0.2)'" onmouseout="this.style.background='rgba(59,130,246,0.1)'">Restore</button>` : ''}
    </td>
</tr>`).join('');
                lucide.createIcons();
            } else {
                tbody.innerHTML = `<tr><td colspan="5" style="padding:3rem;text-align:center;color:var(--slate-500);">No backups found. Create your first backup above.</td></tr>`;
            }
        } catch(e) { console.error(e); }
    }

    async function restoreBackup(file) {
        if (!confirm('Restore from ' + file + '? This will overwrite current files.')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'restore_backup');
        fd.append('file', file);
        fd.append('csrf_token', CSRF());
        showToast('info', 'Restore started…');
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast(res.status === 'success' ? 'success' : 'error', res.msg);
    }

    // Utility: domain selector via <select>
    function getDomId() {
        <?php if (!empty($domains)): ?>
        const opts = [<?php foreach ($domains as $d) echo "{id:{$d['id']},name:'{$d['domain']}'},"; ?>];
        return new Promise(resolve => {
            const sel = document.createElement('select');
            sel.style.cssText = 'width:100%;padding:0.5rem;font-size:0.875rem;';
            opts.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.id; opt.textContent = o.name;
                sel.appendChild(opt);
            });
            if (confirm('Select domain — press OK then choose from the dialog.\n\nDomains: ' + opts.map(o=>o.name).join(', '))) {
                // fallback simple prompt
                const id = prompt('Enter domain ID:\n' + opts.map(o=>`${o.id}: ${o.name}`).join('\n'));
                resolve(id);
            } else { resolve(null); }
        });
        <?php else: ?>
        alert('No domains found on your account.');
        return Promise.resolve(null);
        <?php endif; ?>
    }

    // Troubleshoot AJAX
    async function fixWebsite() {
        const did = await getDomId(); if (!did) return;
        if (!confirm('Fix permissions and default pages for this domain?')) return;
        const fd = new FormData(); fd.append('ajax_action', 'fix_website'); fd.append('domain_id', did); fd.append('csrf_token', CSRF());
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast(res.status === 'success' ? 'success' : 'error', res.msg || 'Website Fixed');
    }

    async function restartServices() {
        const did = await getDomId(); if (!did) return;
        const fd = new FormData(); fd.append('ajax_action', 'restart_services'); fd.append('domain_id', did); fd.append('csrf_token', CSRF());
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast(res.status === 'success' ? 'success' : 'error', res.msg || 'Services Restarted');
    }

    async function fixConfig() {
        const did = await getDomId(); if (!did) return;
        if (!confirm('Fix server configuration for this domain?')) return;
        const fd = new FormData(); fd.append('ajax_action', 'fix_config'); fd.append('domain_id', did); fd.append('csrf_token', CSRF());
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast(res.status === 'success' ? 'success' : 'error', res.msg || 'Config Fixed');
    }

    // Init: load data for active tab only
    document.addEventListener('DOMContentLoaded', () => {
        const tab = new URLSearchParams(window.location.search).get('tab') || 'apps';
        if (tab === 'apps')     { loadApps(); window.appPoll = setInterval(loadApps, 10000); }
        if (tab === 'ftp')      loadFTP();
        if (tab === 'security') loadSSH();
        if (tab === 'backups')  loadBackups();
    });
</script>

<?php include 'layout/footer.php'; ?>
