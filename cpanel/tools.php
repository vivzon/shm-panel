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
            $dom_id = $_POST['domain_id'];
            $domain = $pdo->query("SELECT domain FROM domains WHERE id=$dom_id AND client_id=$cid")->fetchColumn();
            if (!$domain)
                throw new Exception("Invalid Domain");

            $rand = substr(md5(uniqid()), 0, 6);
            $db_name = $username . "_wp_" . $rand;
            $db_user = $username . "_" . $rand;
            $db_pass = bin2hex(random_bytes(8));

            $stmt = $pdo->prepare("INSERT INTO app_installations (client_id, domain_id, app_type, db_name, db_user, db_pass, status) VALUES (?, ?, ?, ?, ?, ?, 'installing')");
            $stmt->execute([$cid, $dom_id, $app, $db_name, $db_user, $db_pass]);

            $cmd = "app-tool install " . escapeshellarg($app) . " " . escapeshellarg($domain) . " " . escapeshellarg($db_name) . " " . escapeshellarg($db_user) . " " . escapeshellarg($db_pass);
            if (function_exists('cmd'))
                cmd("$cmd > /dev/null 2>&1 &");

            sendResponse($res);
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
            $pass = md5($_POST['pass']);
            $home = "/var/www/clients/$username/public_html" . ($_POST['dir'] ? '/' . trim($_POST['dir'], '/') : '');

            $sys_user_info = function_exists('posix_getpwnam') ? posix_getpwnam($username) : ['uid' => 1000, 'gid' => 1000];
            $uid = $sys_user_info['uid'] ?? 1000;
            $gid = $sys_user_info['gid'] ?? 1000;

            $check = $pdo->prepare("SELECT count(*) FROM ftp_users WHERE userid = ?");
            $check->execute([$ftp_user]);
            if ($check->fetchColumn() > 0)
                throw new Exception("FTP User already exists");

            $pdo->prepare("INSERT INTO ftp_users (userid, passwd, homedir, uid, gid) VALUES (?,?,?,?,?)")->execute([$ftp_user, $pass, $home, $uid, $gid]);
            sendResponse($res);
            exit;
        }

        if ($action == 'del_ftp') {
            $userToDelete = $_POST['user'];
            if (!str_ends_with($userToDelete, "@$username"))
                throw new Exception("Permission Denied");
            $pdo->prepare("DELETE FROM ftp_users WHERE userid = ?")->execute([$userToDelete]);
            sendResponse($res);
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
            cmd("ssh-key add " . escapeshellarg($username) . " " . escapeshellarg($_POST['key']));
            sendResponse($res);
            exit;
        }
        if ($action == 'del_ssh') {
            cmd("ssh-key delete " . escapeshellarg($username) . " " . (int) $_POST['line']);
            sendResponse($res);
            exit;
        }
        if ($action == 'list_ssh') {
            $out = cmd("ssh-key list " . escapeshellarg($username));
            $lines = array_filter(explode("\n", $out));
            echo json_encode(['status' => 'success', 'data' => array_values($lines)]);
            exit;
        }

        if ($action == 'fix_perms') {
            cmd("fix-permissions " . escapeshellarg($username));
            sendResponse($res);
            exit;
        }

        // --- BACKUP HANDLERS ---
        if ($action == 'create_backup') {
            cmd("backup create " . escapeshellarg($username));
            sendResponse($res);
            exit;
        }
        if ($action == 'list_backups') {
            $out = cmd("backup list " . escapeshellarg($username));
            $backups = [];
            foreach (explode("\n", $out) as $line) {
                if (!trim($line))
                    continue;
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 5) {
                    $backups[] = ['name' => end($parts), 'size' => $parts[0], 'date' => $parts[1] . ' ' . $parts[2] . ' ' . $parts[3]];
                }
            }
            echo json_encode(['status' => 'success', 'data' => $backups]);
            exit;
        }
        if ($action == 'restore_backup') {
            cmd("backup restore " . escapeshellarg($username) . " " . escapeshellarg($_POST['file']));
            sendResponse($res);
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
                sendResponse(['status' => 'success', 'msg' => 'Configuration fixes applied.']);
            } else {
                sendResponse($res);
            }
            exit;
        }

    } catch (Exception $e) {
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
<div class="mb-8">
    <h2 class="text-2xl font-bold text-white mb-2">System Tools</h2>
    <p class="text-slate-400 text-sm">Manage applications, security, and backups.</p>
</div>

<!-- TABS -->
<div class="flex border-b border-slate-800 mb-8 overflow-x-auto">
    <a href="?tab=apps"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'apps' ? 'border-blue-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">App
        Installer</a>
    <a href="?tab=ftp"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'ftp' ? 'border-blue-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">FTP
        Manager</a>
    <a href="?tab=security"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'security' ? 'border-blue-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">Security
        (SSH)</a>
    <a href="?tab=backups"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'backups' ? 'border-blue-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">Backups</a>
    <a href="?tab=troubleshoot"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'troubleshoot' ? 'border-emerald-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">Troubleshoot</a>
</div>

<!-- APPS TAB -->
<div id="tab-apps" class="<?= $active_tab == 'apps' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Install Form -->
        <div class="glass-card p-6 h-fit">
            <h3 class="font-bold text-white mb-4">Install Application</h3>
            <form onsubmit="handleAppInstall(event)" class="space-y-4">
                <div>
                    <label class="text-xs text-slate-400 uppercase font-bold">Select Domain</label>
                    <select name="domain_id"
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-white focus:border-blue-500 outline-none">
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= $d['domain'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-slate-400 uppercase font-bold">Application</label>
                    <select name="app"
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-white focus:border-blue-500 outline-none">
                        <option value="wordpress">WordPress</option>
                        <option value="laravel">Laravel</option>
                        <option value="codeigniter">CodeIgniter 4</option>
                        <option value="react">React (Vite)</option>
                    </select>
                </div>
                <button type="submit"
                    class="w-full py-3 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-bold transition shadow-lg shadow-blue-500/20">
                    Install Now
                </button>
            </form>
        </div>

        <!-- Recent Installations -->
        <div class="lg:col-span-2 glass-card p-0 overflow-hidden">
            <div class="p-4 border-b border-white/5 bg-slate-900/50 flex justify-between items-center">
                <h3 class="font-bold text-white">Recent Installations</h3>
                <button onclick="loadApps()" class="text-slate-400 hover:text-white"><i data-lucide="refresh-cw"
                        class="w-4 h-4"></i></button>
            </div>
            <table class="w-full text-left">
                <thead class="bg-slate-900/50 text-[10px] uppercase text-slate-400 font-bold">
                    <tr>
                        <th class="p-4">App</th>
                        <th class="p-4">Domain</th>
                        <th class="p-4">Status</th>
                        <th class="p-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="app-list" class="divide-y divide-white/5 text-sm text-slate-300">
                    <tr>
                        <td colspan="4" class="p-6 text-center text-slate-500">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- FTP TAB -->
<div id="tab-ftp" class="<?= $active_tab == 'ftp' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Add FTP Form -->
        <div class="glass-card p-6 h-fit">
            <h3 class="font-bold text-white mb-4">Create FTP Account</h3>
            <form onsubmit="handleFTPAdd(event)" class="space-y-4">
                <div>
                    <label class="text-xs text-slate-400 uppercase font-bold">Username</label>
                    <div class="flex items-center bg-slate-900 border border-slate-700 rounded-lg overflow-hidden">
                        <input name="ftp_user" required placeholder="user"
                            class="bg-transparent p-3 w-full text-white outline-none">
                        <span
                            class="px-3 text-slate-500 bg-slate-800 border-l border-slate-700 py-3">@<?= $username ?></span>
                    </div>
                </div>
                <div>
                    <label class="text-xs text-slate-400 uppercase font-bold">Password</label>
                    <input type="password" name="pass" required
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-white outline-none mb-2"
                        placeholder="Password">
                    <input type="password" name="pass2" required
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-white outline-none"
                        placeholder="Confirm Password">
                </div>
                <div>
                    <label class="text-xs text-slate-400 uppercase font-bold">Directory (Optional)</label>
                    <input name="dir" placeholder="/public_html"
                        class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-white outline-none">
                </div>
                <button type="submit"
                    class="w-full py-3 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-bold transition shadow-lg shadow-blue-500/20">
                    Create FTP User
                </button>
            </form>
        </div>

        <!-- FTP List -->
        <div class="lg:col-span-2 glass-card p-0 overflow-hidden">
            <div class="p-4 border-b border-white/5 bg-slate-900/50 flex justify-between items-center">
                <h3 class="font-bold text-white">FTP Accounts</h3>
                <button onclick="loadFTP()" class="text-slate-400 hover:text-white"><i data-lucide="refresh-cw"
                        class="w-4 h-4"></i></button>
            </div>
            <table class="w-full text-left">
                <thead class="bg-slate-900/50 text-[10px] uppercase text-slate-400 font-bold">
                    <tr>
                        <th class="p-4">Username</th>
                        <th class="p-4">Home Directory</th>
                        <th class="p-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="ftp-list" class="divide-y divide-white/5 text-sm text-slate-300">
                    <tr>
                        <td colspan="3" class="p-6 text-center text-slate-500">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SECURITY & BACKUPS (Placeholders for now, to be implemented similarly) -->
<div id="tab-security" class="<?= $active_tab == 'security' ? '' : 'hidden' ?>">
    <div class="text-center p-12 text-slate-500">SSH Key Management coming soon.</div>
</div>
<div id="tab-backups" class="<?= $active_tab == 'backups' ? '' : 'hidden' ?>">
    <div class="text-center p-12 text-slate-500">Backup Management coming soon.</div>
</div>

<div id="tab-troubleshoot" class="<?= $active_tab == 'troubleshoot' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- Existing Troubleshoot Buttons -->
        <div class="glass-card p-8 bg-gradient-to-br from-indigo-900/20 to-indigo-900/5 border-indigo-500/20">
            <h3 class="font-bold text-white mb-4">Display Doctor</h3>
            <button onclick="fixWebsite()"
                class="w-full py-4 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold shadow-lg flex items-center justify-center gap-2 transition">
                <i data-lucide="wand-2" class="w-5 h-5"></i> Fix Website Display
            </button>
        </div>

        <div class="glass-card p-8 bg-gradient-to-br from-slate-900/50 to-slate-900/20">
            <h3 class="font-bold text-white mb-4">Restart Services</h3>
            <button onclick="restartServices()"
                class="w-full py-4 bg-slate-700 hover:bg-slate-600 text-white rounded-xl font-bold shadow-lg flex items-center justify-center gap-2 transition">
                <i data-lucide="power" class="w-5 h-5"></i> Restart Services
            </button>
        </div>

        <!-- NEW FIX CONFIG BUTTON -->
        <div class="glass-card p-8 bg-gradient-to-br from-rose-900/20 to-rose-900/5 border-rose-500/20 mt-6">
            <h3 class="font-bold text-white mb-4">Fix Config Issues</h3>
            <button onclick="fixConfig()"
                class="w-full py-4 bg-rose-600 hover:bg-rose-500 text-white rounded-xl font-bold shadow-lg flex items-center justify-center gap-2 transition">
                <i data-lucide="wrench" class="w-5 h-5"></i> Fix Config
            </button>
        </div>
    </div>
</div>

<script>
    // Generic Tool Action Handler
    async function handleToolAction(e, action, callback = null) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin inline mr-2"></i> Processing...`;
        lucide.createIcons();

        const fd = new FormData(e.target);
        fd.append('ajax_action', action);

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
            // Start polling
            if (!window.appPoll) window.appPoll = setInterval(loadApps, 5000);
        });
    }

    async function loadApps() {
        const tbody = document.getElementById('app-list');
        try {
            const fd = new FormData(); fd.append('ajax_action', 'list_apps');
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());

            if (res.status === 'success' && res.data.length > 0) {
                tbody.innerHTML = res.data.map(app => `
<tr class="border-t border-white/5 hover:bg-white/5 transition">
    <td class="p-4 font-bold text-white capitalize">${app.app_type}</td>
    <td class="p-4 text-slate-400">${app.domain}</td>
    <td class="p-4">
        <span class="px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wider ${app.status === 'active' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' :
                        (app.status === 'failed' ? 'bg-red-500/10 text-red-400 border border-red-500/20' :
                            'bg-blue-500/10 text-blue-400 border border-blue-500/20 animate-pulse')
                    }">
            ${app.status}
        </span>
    </td>
    <td class="p-4 text-right">
        ${app.status === 'active' ?
                        `<a href="http://${app.domain}" target="_blank" class="text-blue-400 hover:text-white mr-2"><i
                data-lucide="external-link" class="w-4 h-4"></i></a>` :
                        ''}
    </td>
</tr>
`).join('');
                lucide.createIcons();
            } else {
                tbody.innerHTML = `<tr>
    <td colspan="4" class="p-6 text-center text-slate-500">No recent installations</td>
</tr>`;
            }
        } catch (e) { console.error(e); }
    }

    // FTP Logic
    function handleFTPAdd(e) {
        handleToolAction(e, 'add_ftp', loadFTP);
    }

    async function loadFTP() {
        const tbody = document.getElementById('ftp-list');
        try {
            const fd = new FormData(); fd.append('ajax_action', 'list_ftp');
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());

            if (res.status === 'success' && res.data.length > 0) {
                tbody.innerHTML = res.data.map(user => `
<tr class="border-t border-white/5 hover:bg-white/5 transition">
    <td class="p-4 font-bold text-white">${user.userid}</td>
    <td class="p-4 text-slate-400 font-mono text-xs">${user.homedir}</td>
    <td class="p-4 text-right">
        <button onclick="delFTP('${user.userid}')" class="text-red-400 hover:bg-red-500/10 p-2 rounded transition"><i
                data-lucide="trash-2" class="w-4 h-4"></i></button>
    </td>
</tr>
`).join('');
                lucide.createIcons();
            } else {
                tbody.innerHTML = `<tr>
    <td colspan="3" class="p-6 text-center text-slate-500">No FTP accounts found</td>
</tr>`;
            }
        } catch (e) { console.error(e); }
    }

    async function delFTP(user) {
        if (!confirm('Delete FTP user ' + user + '?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'del_ftp');
        fd.append('user', user);
        await fetch('', { method: 'POST', body: fd });
        showToast('success', 'FTP User Deleted');
        loadFTP();
    }

    // Init
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('tab-apps') && !document.getElementById('tab-apps').classList.contains('hidden')) {
            loadApps();
            // Poll for status updates
            window.appPoll = setInterval(loadApps, 10000);
        }
        if (document.getElementById('tab-ftp') && !document.getElementById('tab-ftp').classList.contains('hidden')) {
            loadFTP();
        }
    });

    // Utility: Prompt domain ID
    function getDomId() {
        let domList = "Available IDs:\n";
        <?php foreach ($domains as $d)
            echo "domList += \"{$d['id']}: {$d['domain']}\\n\";\n"; ?>
        return prompt(`Select Domain ID:\n\n${domList}`);
    }

    // Troubleshoot AJAX
    async function fixWebsite() {
        const did = getDomId(); if (!did) return;
        if (!confirm("This will fix permissions and default pages for this domain. Continue?")) return;
        const fd = new FormData(); fd.append('ajax_action', 'fix_website'); fd.append('domain_id', did);
        await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast('success', 'Website Fixed');
    }
    async function restartServices() {
        const did = getDomId(); if (!did) return;
        const fd = new FormData(); fd.append('ajax_action', 'restart_services'); fd.append('domain_id', did);
        await fetch('', { method: 'POST', body: fd });
        showToast('success', 'Services Restarted');
    }

    // NEW: Fix Config
    async function fixConfig() {
        const did = getDomId(); if (!did) return;
        if (!confirm("This will fix server configuration issues for this domain. Continue?")) return;
        const fd = new FormData(); fd.append('ajax_action', 'fix_config'); fd.append('domain_id', did);
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        if (res.status === 'success') showToast('success', 'Config Fixed', res.msg);
        else showToast('error', 'Failed', res.msg);
    }
</script>

<?php include 'layout/footer.php'; ?>