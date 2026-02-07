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
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        // --- APPS HANDLER ---
        if ($action == 'install_app') {
            $app = $_POST['app'];
            $dom_id = (int) $_POST['domain_id'];

            $stmt = $pdo->prepare("SELECT domain FROM domains WHERE id=? AND client_id=?");
            $stmt->execute([$dom_id, $cid]);
            $domain = $stmt->fetchColumn();

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

            echo json_encode(['status' => 'success', 'msg' => 'App installation started']);
            exit;
        }

        // --- FTP HANDLERS ---
        if ($action == 'add_ftp') {
            if ($_POST['pass'] !== $_POST['pass2'])
                throw new Exception("Passwords do not match");

            $ftp_user = strtolower($_POST['ftp_user'] . '@' . $username);
            $pass = password_hash($_POST['pass'], PASSWORD_BCRYPT);
            $home = "/var/www/clients/$username/public_html" . ($_POST['dir'] ? '/' . trim($_POST['dir'], '/') : '');

            $sys_user_info = function_exists('posix_getpwnam') ? posix_getpwnam($username) : ['uid' => 1000, 'gid' => 1000];
            $uid = $sys_user_info['uid'] ?? 1000;
            $gid = $sys_user_info['gid'] ?? 1000;

            $check = $pdo->prepare("SELECT count(*) FROM ftp_users WHERE userid = ?");
            $check->execute([$ftp_user]);
            if ($check->fetchColumn() > 0)
                throw new Exception("FTP User already exists");

            $pdo->prepare("INSERT INTO ftp_users (userid, passwd, homedir, uid, gid) VALUES (?,?,?,?,?)")->execute([$ftp_user, $pass, $home, $uid, $gid]);
            echo json_encode(['status' => 'success', 'msg' => 'FTP Account Created']);
            exit;
        }

        if ($action == 'del_ftp') {
            $userToDelete = $_POST['user'];
            if (!str_ends_with($userToDelete, "@$username"))
                throw new Exception("Permission Denied");

            $pdo->prepare("DELETE FROM ftp_users WHERE userid = ?")->execute([$userToDelete]);
            echo json_encode(['status' => 'success', 'msg' => 'FTP Account Deleted']);
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
            cmd("shm-manage ssh-key add " . escapeshellarg($username) . " " . escapeshellarg($_POST['key']));
            echo json_encode(['status' => 'success', 'msg' => 'SSH Key Added']);
            exit;
        }
        if ($action == 'del_ssh') {
            cmd("shm-manage ssh-key delete " . escapeshellarg($username) . " " . (int) $_POST['line']);
            echo json_encode(['status' => 'success', 'msg' => 'SSH Key Deleted']);
            exit;
        }
        if ($action == 'list_ssh') {
            $out = cmd("shm-manage ssh-key list " . escapeshellarg($username));
            $lines = array_filter(explode("\n", $out));
            echo json_encode(['status' => 'success', 'data' => array_values($lines)]);
            exit;
        }

        if ($action == 'fix_perms') {
            cmd("shm-manage fix-permissions " . escapeshellarg($username));
            echo json_encode(['status' => 'success', 'msg' => 'Permissions Fixed']);
            exit;
        }

        // --- BACKUP HANDLERS ---
        if ($action == 'create_backup') {
            cmd("shm-manage backup create " . escapeshellarg($username));
            echo json_encode(['status' => 'success', 'msg' => 'Backup Creation Started']);
            exit;
        }
        if ($action == 'list_backups') {
            $out = cmd("shm-manage backup list " . escapeshellarg($username));
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
            cmd("shm-manage backup restore " . escapeshellarg($username) . " " . escapeshellarg($_POST['file']));
            echo json_encode(['status' => 'success', 'msg' => 'Restore Started']);
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

            cmd("shm-manage troubleshoot fix-perms $did");
            cmd("shm-manage troubleshoot fix-default-page $did");
            cmd("shm-manage troubleshoot reload-services $did");

            if ($action == 'fix_config') {
                $domain = $domainData['domain'];
                cmd("shm-manage troubleshoot fix-config $domain");
                echo json_encode(['status' => 'success', 'msg' => 'Configuration fixes applied.']);
            } else {
                echo json_encode(['status' => 'success', 'msg' => 'Troubleshoot actions completed.']);
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

<!-- CONTENT: APPS -->
<div id="tab-apps" class="<?= $active_tab == 'apps' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- WP -->
        <div class="glass-card p-6 bg-gradient-to-br from-blue-900/20 to-transparent border-blue-500/20">
            <div class="flex items-center gap-4 mb-6">
                <div
                    class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-600/20">
                    <i data-lucide="layout" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h3 class="font-bold text-white text-lg">WordPress</h3>
                    <p class="text-slate-500 text-xs">The world's most popular CMS.</p>
                </div>
            </div>
            <button onclick="installApp('wordpress')"
                class="w-full py-3 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-bold transition shadow-lg shadow-blue-600/20">Install
                Now</button>
        </div>
        <!-- Laravel -->
        <div class="glass-card p-6 bg-gradient-to-br from-rose-900/20 to-transparent border-rose-500/20">
            <div class="flex items-center gap-4 mb-6">
                <div
                    class="w-12 h-12 bg-rose-600 rounded-2xl flex items-center justify-center shadow-lg shadow-rose-600/20">
                    <i data-lucide="zap" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h3 class="font-bold text-white text-lg">Laravel</h3>
                    <p class="text-slate-500 text-xs">The PHP Framework for Web Artisans.</p>
                </div>
            </div>
            <button onclick="installApp('laravel')"
                class="w-full py-3 bg-rose-600 hover:bg-rose-500 text-white rounded-xl font-bold transition shadow-lg shadow-rose-600/20">Install
                Now</button>
        </div>
    </div>
</div>

<!-- CONTENT: FTP -->
<div id="tab-ftp" class="<?= $active_tab == 'ftp' ? '' : 'hidden' ?>">
    <div class="glass-card p-6 mb-8">
        <h3 class="font-bold text-white mb-6 flex items-center gap-2"><i data-lucide="user-plus"
                class="w-4 h-4 text-blue-400"></i> Create FTP Account</h3>
        <form onsubmit="addFtp(event)" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <input type="text" name="ftp_user" placeholder="Username" required
                class="bg-slate-900 border-slate-700 text-white p-3 rounded-xl outline-none focus:border-blue-500 transition">
            <input type="password" name="pass" placeholder="Password" required
                class="bg-slate-900 border-slate-700 text-white p-3 rounded-xl outline-none focus:border-blue-500 transition">
            <button type="submit"
                class="bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-xl transition">Create Account</button>
        </form>
    </div>
    <div id="ftp-list-container"></div>
</div>

<!-- CONTENT: SECURITY -->
<div id="tab-security" class="<?= $active_tab == 'security' ? '' : 'hidden' ?>">
    <div class="glass-card p-6">
        <h3 class="font-bold text-white mb-6">Manage SSH Keys</h3>
        <div class="flex flex-col gap-4">
            <textarea id="ssh-key-input" placeholder="Paste your public key here (ssh-rsa ...)"
                class="w-full h-32 bg-slate-900 border border-slate-700 text-slate-300 p-4 rounded-xl font-mono text-xs outline-none focus:border-blue-500 transition"></textarea>
            <button onclick="addSSHKey()"
                class="bg-blue-600 hover:bg-blue-500 text-white py-3 rounded-xl font-bold transition">Add SSH
                Key</button>
        </div>
        <div id="ssh-list" class="mt-8 divide-y divide-slate-800"></div>
    </div>
</div>

<!-- CONTENT: BACKUPS -->
<div id="tab-backups" class="<?= $active_tab == 'backups' ? '' : 'hidden' ?>">
    <!-- PROGRESS BANNER -->
    <div id="backup-status-banner"
        class="hidden mb-6 p-4 rounded-2xl bg-blue-600/10 border border-blue-500/20 flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-blue-600/20 flex items-center justify-center">
            <i data-lucide="loader-2" class="w-5 h-5 text-blue-400 animate-spin"></i>
        </div>
        <div class="flex-1">
            <h4 class="text-sm font-bold text-white uppercase tracking-wider mb-1">Backup in Progress</h4>
            <div class="w-full bg-slate-800 rounded-full h-1.5 mt-2">
                <div id="backup-status-progress" class="bg-blue-500 h-1.5 rounded-full transition-all duration-500"
                    style="width: 10%"></div>
            </div>
            <p id="backup-status-text" class="text-[10px] text-slate-400 font-mono mt-2 italic">Processing...</p>
        </div>
    </div>

    <div class="flex justify-between items-center mb-6">
        <h3 class="font-bold text-white">Your Backups</h3>
        <button onclick="createBackup()"
            class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-lg text-sm font-bold transition">Create
            New</button>
    </div>
    <div class="glass-card overflow-hidden">
        <table class="w-full text-left">
            <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                <tr>
                    <th class="p-4">Filename</th>
                    <th class="p-4">Size</th>
                    <th class="p-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="backup-list" class="divide-y divide-slate-700/50"></tbody>
        </table>
    </div>
</div>

<!-- CONTENT: TROUBLESHOOT -->
<div id="tab-troubleshoot" class="<?= $active_tab == 'troubleshoot' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
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
    </div>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    // --- UTILS ---
    function getDomId() {
        let domList = "Available IDs:\n";
        <?php foreach ($domains as $d)
            echo "domList += \"{$d['id']}: {$d['domain']}\\n\";\n"; ?>
        return prompt(`Select Domain ID:\n\n${domList}`);
    }

    // --- APPS ---
    async function installApp(app) {
        const did = getDomId(); if (!did) return;
        if (!confirm(`Install ${app} on domain ID ${did}? existing files will be backed up.`)) return;
        const fd = new FormData(); fd.append('ajax_action', 'install_app'); fd.append('app', app); fd.append('domain_id', did);
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') showToast('success', 'Installation Started', res.msg);
            else showToast('error', 'Failed', res.msg);
        } catch (e) { showToast('error', 'Server Error'); }
    }

    // --- FTP ---
    async function addFtp(e) {
        e.preventDefault();
        const fd = new FormData(e.target); fd.append('ajax_action', 'add_ftp');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'FTP Created', res.msg);
                e.target.reset();
                loadFtp();
            } else showToast('error', 'Failed', res.msg);
        } catch (e) { showToast('error', 'Server Error'); }
    }

    async function loadFtp() {
        const container = document.getElementById('ftp-list-container');
        const fd = new FormData(); fd.append('ajax_action', 'list_ftp');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                container.innerHTML = `
                    <div class="glass-card overflow-hidden mt-6">
                        <table class="w-full text-left">
                            <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                                <tr><th class="p-4">User</th><th class="p-4">Home</th><th class="p-4 text-right">Action</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                ${res.data.map(f => `
                                    <tr>
                                        <td class="p-4 text-sm font-bold text-white">${f.userid}</td>
                                        <td class="p-4 text-xs text-slate-500">${f.homedir}</td>
                                        <td class="p-4 text-right"><button onclick="delFtp('${f.userid}')" class="text-rose-500 text-[10px] font-bold hover:text-white transition">DELETE</button></td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }
        } catch (e) {}
    }

    async function delFtp(user) {
        if (!confirm(`Delete FTP user ${user}?`)) return;
        const fd = new FormData(); fd.append('ajax_action', 'del_ftp'); fd.append('user', user);
        await fetch('', { method: 'POST', body: fd });
        loadFtp();
    }

    // --- SECURITY ---
    async function addSSHKey() {
        const key = document.getElementById('ssh-key-input').value;
        if (!key) return;
        const fd = new FormData(); fd.append('ajax_action', 'add_ssh'); fd.append('key', key);
        await fetch('', { method: 'POST', body: fd });
        document.getElementById('ssh-key-input').value = '';
        loadSSH();
    }

    async function loadSSH() {
        const container = document.getElementById('ssh-list');
        const fd = new FormData(); fd.append('ajax_action', 'list_ssh');
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        container.innerHTML = res.data.map(line => {
            const parts = line.trim().split(/\s+/);
            const num = parts.shift();
            const keyDesc = parts.slice(0, 3).join(' ') + '...';
            return `
                <div class="flex justify-between items-center py-3">
                    <span class="text-[10px] font-mono text-slate-400">${keyDesc}</span>
                    <button onclick="delSSH(${num})" class="text-rose-400 text-[10px] font-bold hover:text-white transition">REMOVE</button>
                </div>
            `;
        }).join('') || '<p class="text-slate-600 text-sm">No keys found.</p>';
    }

    async function delSSH(num) {
        const fd = new FormData(); fd.append('ajax_action', 'del_ssh'); fd.append('line', num);
        await fetch('', { method: 'POST', body: fd });
        loadSSH();
    }

    // --- BACKUPS ---
    let backupStatusInterval = null;

    async function loadBackups() {
        const list = document.getElementById('backup-list');
        const fd = new FormData(); fd.append('ajax_action', 'list_backups');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = res.data.map(b => `
                <tr class="hover:bg-slate-800/30 transition">
                    <td class="p-4 font-bold text-slate-300 text-sm">${b.name}</td>
                    <td class="p-4 text-slate-400 text-xs">${b.size}</td>
                    <td class="p-4 text-right">
                        <button onclick="restoreBackup('${b.name}')" class="text-blue-400 font-bold text-[10px] uppercase hover:text-white mr-4 transition">Restore</button>
                        <button onclick="deleteBackup('${b.name}')" class="text-rose-400 font-bold text-[10px] uppercase hover:text-white transition">Delete</button>
                    </td>
                </tr>
            `).join('') || '<tr><td colspan="3" class="p-4 text-center text-slate-500">No backups found.</td></tr>';
        } catch (e) {}
    }

    async function createBackup() {
        const fd = new FormData(); fd.append('ajax_action', 'create_backup');
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast('info', 'Backup Started', res.msg);
        checkBackupStatus();
    }

    async function deleteBackup(file) {
        if (!confirm(`Delete backup ${file}?`)) return;
        const fd = new FormData(); fd.append('ajax_action', 'del_backup'); fd.append('file', file);
        await fetch('', { method: 'POST', body: fd });
        loadBackups();
    }

    async function restoreBackup(file) {
        if (!confirm('This will OVERWRITE your current site. Continue?')) return;
        const fd = new FormData(); fd.append('ajax_action', 'restore_backup'); fd.append('file', file);
        showToast('info', 'Restoring...', 'Restore job started.');
        await fetch('', { method: 'POST', body: fd });
    }

    async function checkBackupStatus() {
        const banner = document.getElementById('backup-status-banner');
        const progress = document.getElementById('backup-status-progress');
        const text = document.getElementById('backup-status-text');
        
        const fd = new FormData(); fd.append('ajax_action', 'get_backup_status');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            const s = res.data;
            if (s === 'idle' || s === 'finished' || s === 'failed') {
                banner.classList.add('hidden');
                if (backupStatusInterval) { clearInterval(backupStatusInterval); backupStatusInterval = null; loadBackups(); }
                return;
            }
            banner.classList.remove('hidden');
            if (s === 'dumping_db') { progress.style.width = '30%'; text.innerText = 'Dumping DB...'; }
            else if (s === 'compressing') { progress.style.width = '70%'; text.innerText = 'Compressing...'; }
            if (!backupStatusInterval) backupStatusInterval = setInterval(checkBackupStatus, 3000);
        } catch (e) {}
    }

    // --- TROUBLESHOOT ---
    async function fixWebsite() {
        const did = getDomId(); if (!did) return;
        const fd = new FormData(); fd.append('ajax_action', 'fix_website'); fd.append('domain_id', did);
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast('success', 'Done', res.msg);
    }
    async function restartServices() {
        const did = getDomId(); if (!did) return;
        const fd = new FormData(); fd.append('ajax_action', 'restart_services'); fd.append('domain_id', did);
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast('success', 'Done', res.msg);
    }
    async function fixConfig() {
        const did = getDomId(); if (!did) return;
        const fd = new FormData(); fd.append('ajax_action', 'fix_config'); fd.append('domain_id', did);
        const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
        showToast('success', 'Done', res.msg);
    }

    // INITIAL LOAD
    const activeTab = '<?= $active_tab ?>';
    if (activeTab === 'ftp') loadFtp();
    if (activeTab === 'security') loadSSH();
    if (activeTab === 'backups') { loadBackups(); checkBackupStatus(); }
</script>