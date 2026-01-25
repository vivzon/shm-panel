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
            $dom_id = $_POST['domain_id'];
            $domain = $pdo->query("SELECT domain FROM domains WHERE id=$dom_id AND client_id=$cid")->fetchColumn();
            if (!$domain)
                throw new Exception("Invalid Domain");

            // Generate DB Credentials
            $rand = substr(md5(uniqid()), 0, 6);
            $db_name = $username . "_wp_" . $rand; // e.g. client_wp_123456
            $db_user = $username . "_" . $rand;
            $db_pass = bin2hex(random_bytes(8));

            // Record Installation
            $stmt = $pdo->prepare("INSERT INTO app_installations (client_id, domain_id, app_type, db_name, db_user, db_pass, status) VALUES (?, ?, ?, ?, ?, ?, 'installing')");
            $stmt->execute([$cid, $dom_id, $app, $db_name, $db_user, $db_pass]);

            // Call Backend: shm-manage app-tool install <APP> <DOMAIN> <DB> <USER> <PASS>
            $cmd = "app-tool install " . escapeshellarg($app) . " " . escapeshellarg($domain) . " " . escapeshellarg($db_name) . " " . escapeshellarg($db_user) . " " . escapeshellarg($db_pass);

            if (function_exists('cmd')) {
                cmd("$cmd > /dev/null 2>&1 &");
            }

            sendResponse($res);
            exit;
        }

        // --- FTP HANDLERS ---
        if ($action == 'add_ftp') {
            if ($_POST['pass'] !== $_POST['pass2'])
                throw new Exception("Passwords do not match");
            $ftp_user = strtolower($_POST['ftp_user'] . '@' . $username); // Enforce user@client format

            // Password: Check valid FTP Login (MD5 is common for Pure/ProFTPD)
            $pass = md5($_POST['pass']);

            $home = "/var/www/clients/$username/public_html" . ($_POST['dir'] ? '/' . trim($_POST['dir'], '/') : '');

            // Get System User UID/GID to ensure file permissions work
            if (function_exists('posix_getpwnam')) {
                $sys_user_info = posix_getpwnam($username);
                if (!$sys_user_info)
                    throw new Exception("System user not found");
                $uid = $sys_user_info['uid'];
                $gid = $sys_user_info['gid'];
            } else {
                // Fallback for Windows or non-POSIX envs
                $uid = 1000;
                $gid = 1000;
            }

            $check = $pdo->prepare("SELECT count(*) FROM ftp_users WHERE userid = ?");
            $check->execute([$ftp_user]);
            if ($check->fetchColumn() > 0)
                throw new Exception("FTP User already exists");

            // Assuming table has uid/gid columns. If not, this might error, but it's standard.
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
            $suffix = "@$username";
            $stmt = $pdo->prepare("SELECT userid, homedir FROM ftp_users WHERE userid LIKE ?");
            $stmt->execute(["%$suffix"]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // --- SECURITY HANDLERS ---
        if ($action == 'add_ssh') {
            cmd("shm-manage ssh-key add " . escapeshellarg($username) . " " . escapeshellarg($_POST['key']));
            sendResponse($res);
            exit;
        }
        if ($action == 'del_ssh') {
            cmd("shm-manage ssh-key delete " . escapeshellarg($username) . " " . (int) $_POST['line']);
            sendResponse($res);
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
            sendResponse($res);
            exit;
        }

        // --- BACKUP HANDLERS ---
        if ($action == 'create_backup') {
            cmd("shm-manage backup create " . escapeshellarg($username));
            sendResponse($res);
            exit;
        }
        if ($action == 'list_backups') {
            $out = cmd("shm-manage backup list " . escapeshellarg($username));
            $backups = [];
            foreach (explode("\n", $out) as $line) {
                if (!trim($line))
                    continue;
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 5) {
                    $backups[] = [
                        'name' => end($parts),
                        'size' => $parts[0],
                        'date' => $parts[1] . ' ' . $parts[2] . ' ' . $parts[3]
                    ];
                }
            }
            echo json_encode(['status' => 'success', 'data' => $backups]);
            exit;
        }
        if ($action == 'restore_backup') {
            cmd("shm-manage backup restore " . escapeshellarg($username) . " " . escapeshellarg($_POST['file']));
            sendResponse($res);
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

<div class="mb-8">
    <h2 class="text-2xl font-bold text-white mb-2">System Tools</h2>
    <p class="text-slate-400 text-sm">Manage applications, security, and backups.</p>
</div>

<!-- TABS -->
<div class="flex border-b border-slate-800 mb-8 overflow-x-auto">
    <a href="?tab=apps"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'apps' ? 'border-blue-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
        App Installer
    </a>
    <a href="?tab=ftp"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'ftp' ? 'border-blue-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
        FTP Manager
    </a>
    <a href="?tab=security"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'security' ? 'border-blue-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
        Security (SSH)
    </a>
    <a href="?tab=backups"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'backups' ? 'border-blue-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
        Backups
    </a>
</div>

<!-- CONTENT: APPS -->
<div id="tab-apps" class="<?= $active_tab == 'apps' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php
        $apps = [
            'wordpress' => ['WordPress', 'The world\'s most popular CMS.', 'bg-blue-600'],
            'laravel' => ['Laravel', 'The PHP Framework for Web Artisans.', 'bg-red-600'],
            'codeigniter' => ['CodeIgniter', 'Powerful PHP framework with a small footprint.', 'bg-orange-600'],
            'react' => ['React App', 'Create React App boilerplate.', 'bg-cyan-500']
        ];
        foreach ($apps as $key => $info): ?>
            <div class="glass-card p-8 relative overflow-hidden group hover:-translate-y-1 transition duration-500">
                <div
                    class="absolute -right-6 -top-6 w-32 h-32 <?= $info[2] ?>/20 rounded-full blur-3xl group-hover:bg-opacity-40 transition">
                </div>
                <h3 class="text-xl font-bold text-white mb-2 relative z-10"><?= $info[0] ?></h3>
                <p class="text-slate-400 text-sm mb-6 relative z-10 h-10"><?= $info[1] ?></p>
                <button onclick="openAppModal('<?= $key ?>', '<?= $info[0] ?>')"
                    class="w-full py-3 <?= $info[2] ?> hover:opacity-90 text-white font-bold rounded-xl shadow-lg transition relative z-10">
                    Install
                </button>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- CONTENT: FTP -->
<div id="tab-ftp" class="<?= $active_tab == 'ftp' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- CREATE FTP -->
        <div class="glass-card p-8">
            <h3 class="font-bold mb-4 text-white">Create FTP Account</h3>
            <!-- Use dedicated handler to avoid generic errors -->
            <form onsubmit="handleFTPCreate(event)">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Username</label>
                        <div
                            class="flex items-center bg-slate-900/50 rounded-xl border border-slate-700 overflow-hidden">
                            <input name="ftp_user" required placeholder="user"
                                class="w-full bg-transparent p-3 outline-none text-white text-sm text-right">
                            <span
                                class="bg-slate-800 p-3 text-slate-400 text-sm border-l border-slate-700">@<?= $username ?></span>
                        </div>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Directory</label>
                        <input name="dir" placeholder="/public_html"
                            class="w-full bg-slate-900/50 p-3 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <input name="pass" type="password" required placeholder="Password"
                        class="w-full bg-slate-900/50 p-3 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white text-sm">
                    <input name="pass2" type="password" required placeholder="Confirm"
                        class="w-full bg-slate-900/50 p-3 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white text-sm">
                </div>
                <button type="submit"
                    class="w-full bg-blue-600 text-white px-6 py-3 rounded-xl font-bold shadow-lg hover:bg-blue-500 transition">Create
                    Account</button>
            </form>
        </div>

        <!-- LIST FTP -->
        <div class="glass-card p-8">
            <h3 class="font-bold mb-6 text-white text-lg">Active FTP Accounts</h3>
            <div class="overflow-y-auto max-h-[300px] custom-scrollbar">
                <table class="w-full text-left">
                    <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                        <tr>
                            <th class="p-3">User</th>
                            <th class="p-3">Path</th>
                            <th class="p-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody id="ftp-list" class="divide-y divide-slate-700/50">
                        <tr>
                            <td colspan="3" class="p-4 text-center text-slate-500">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- CONTENT: SECURITY -->
<div id="tab-security" class="<?= $active_tab == 'security' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <div class="glass-card p-8">
            <h3 class="font-bold mb-4 text-white">Add Public Key</h3>
            <textarea id="ssh-key-input" placeholder="ssh-rsa AAAA..." rows="4"
                class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-white font-mono text-xs mb-4"></textarea>
            <button onclick="addSSHKey()"
                class="bg-blue-600 text-white px-6 py-3 rounded-xl font-bold shadow-lg hover:bg-blue-500 transition">Add
                Key</button>
        </div>
        <div class="glass-card p-8">
            <h3 class="font-bold mb-6 text-white text-lg">Authorized Keys</h3>
            <div id="ssh-list" class="space-y-2">
                <div class="animate-pulse flex space-x-4">
                    <div class="h-4 bg-slate-700 rounded w-3/4"></div>
                </div>
            </div>

            <div class="mt-8 pt-8 border-t border-slate-700/50">
                <h3 class="font-bold mb-4 text-white">Troubleshooting</h3>
                <button onclick="fixPerms()"
                    class="w-full py-3 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl font-bold flex items-center justify-center gap-2 transition border border-slate-700">
                    <i data-lucide="wrench" class="w-4"></i> Fix File Permissions
                </button>
                <p class="text-center text-[10px] text-slate-500 mt-2">Run this if you encounter "Permission Denied"
                    errors.</p>
            </div>
        </div>
    </div>
</div>

<!-- CONTENT: BACKUPS -->
<div id="tab-backups" class="<?= $active_tab == 'backups' ? '' : 'hidden' ?>">
    <div class="flex justify-between items-center mb-8">
        <h3 class="text-xl font-bold text-white">Archives</h3>
        <button onclick="createBackup()"
            class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-3 rounded-xl font-bold shadow-lg shadow-blue-600/20 flex items-center gap-2 transition">
            <i data-lucide="plus-circle" class="w-4"></i> Create Backup
        </button>
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
            <tbody id="backup-list" class="divide-y divide-slate-700/50">
                <tr>
                    <td class="p-4 text-center text-slate-500" colspan="3">Loading...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    // --- APPS LOGIC ---
    async function openAppModal(app, appName) {
        let domList = "Available IDs:\n";
        <?php foreach ($domains as $d)
            echo "domList += \"{$d['id']}: {$d['domain']}\\n\";\n"; ?>
        const domainId = prompt(`Install ${appName} to which domain? (Enter Domain ID)\n\n${domList}`);
        if (!domainId) return;

        if (!confirm(`WARNING: This will OVERWRITE existing content in the public_html folder.\n\nAre you sure you want to install ${appName}?`)) return;

        const fd = new FormData();
        fd.append('ajax_action', 'install_app');
        fd.append('app', app);
        fd.append('domain_id', domainId);

        showToast('info', 'Installation Started', 'Background process started.');
        try {
            await fetch('', { method: 'POST', body: fd });
            showToast('success', 'Command Sent', 'Installation command queued.');
        } catch (e) {
            showToast('warning', 'Check Logs', 'Request sent but check status.');
        }
    }

    // --- FTP LOGIC ---
    // Defined explicitly to handle form submission cleanly
    async function handleFTPCreate(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '...';

        const fd = new FormData(e.target);
        fd.append('ajax_action', 'add_ftp');

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Created', 'FTP Account created.');
                e.target.reset(); // Clear form
                loadFTP();
            } else {
                showToast('error', 'Failed', res.msg);
            }
        } catch (e) {
            showToast('error', 'Error', 'Server Error');
        }
        btn.disabled = false;
        btn.innerHTML = oldHtml;
    }

    async function loadFTP() {
        const list = document.getElementById('ftp-list');
        if (!list) return;
        const fd = new FormData(); fd.append('ajax_action', 'list_ftp');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';
            if (res.data && res.data.length > 0) {
                res.data.forEach(u => {
                    list.innerHTML += `
                        <tr class="hover:bg-slate-800/30 transition group">
                            <td class="p-3 font-mono text-xs text-blue-300">${u.userid}</td>
                            <td class="p-3 text-slate-500 text-xs truncate max-w-[150px]">${u.homedir}</td>
                            <td class="p-3 text-right">
                                <button onclick="delFTP('${u.userid}')" class="text-red-400 opacity-50 group-hover:opacity-100 hover:text-red-300 transition"><i data-lucide="trash-2" class="w-4"></i></button>
                            </td>
                        </tr>
                     `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-slate-500">No FTP accounts found.</td></tr>';
            }
        } catch (e) { list.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-red-400">Error loading.</td></tr>'; }
    }

    async function delFTP(user) {
        if (!confirm('Delete FTP user ' + user + '?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'del_ftp');
        fd.append('user', user);
        await fetch('', { method: 'POST', body: fd });
        showToast('success', 'Deleted');
        loadFTP();
    }

    // --- SECURITY LOGIC ---
    async function loadSSH() {
        const list = document.getElementById('ssh-list');
        const fd = new FormData(); fd.append('ajax_action', 'list_ssh');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';
            if (res.data && res.data.length > 0) {
                res.data.forEach((line, i) => {
                    list.innerHTML += `
                        <div class="flex items-center justify-between p-4 bg-slate-900/50 rounded-xl border border-slate-700/50 mb-2">
                            <div class="font-mono text-xs text-slate-300 truncate w-3/4">${line}</div>
                            <button onclick="delSSHKey('${parseInt(line)}')" class="p-2 text-red-400 hover:bg-red-500/10 rounded-lg transition"><i data-lucide="trash-2" class="w-4"></i></button>
                        </div>`;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<div class="text-center text-slate-500 py-4">No SSH keys found.</div>';
            }
        } catch (e) { list.innerHTML = '<div class="text-center text-red-400">Error loading keys.</div>'; }
    }

    async function addSSHKey() {
        const key = document.getElementById('ssh-key-input').value;
        if (!key) return;
        const fd = new FormData();
        fd.append('ajax_action', 'add_ssh');
        fd.append('key', key);
        await fetch('', { method: 'POST', body: fd });
        document.getElementById('ssh-key-input').value = '';
        showToast('success', 'Key Added');
        loadSSH();
    }

    async function delSSHKey(line) {
        if (!confirm('Delete this key?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'del_ssh');
        fd.append('line', line);
        await fetch('', { method: 'POST', body: fd });
        showToast('success', 'Key Deleted');
        loadSSH();
    }

    async function fixPerms() {
        if (!confirm('This will reset file permissions for your entire account. Continue?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'fix_perms');
        showToast('info', 'Processing...', 'Fixing permissions...');
        await fetch('', { method: 'POST', body: fd });
        showToast('success', 'Done', 'Permissions have been reset.');
    }

    // --- BACKUPS LOGIC ---
    async function loadBackups() {
        const list = document.getElementById('backup-list');
        const fd = new FormData(); fd.append('ajax_action', 'list_backups');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';
            if (res.data && res.data.length > 0) {
                res.data.forEach(b => {
                    list.innerHTML += `
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-bold text-slate-300">${b.name}</td>
                            <td class="p-4 text-slate-400 text-xs">${b.size}</td>
                            <td class="p-4 text-right">
                                <button onclick="restoreBackup('${b.name}')" class="text-blue-400 font-bold text-xs uppercase hover:text-white mr-4 transition">Restore</button>
                            </td>
                        </tr>`;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-slate-500">No backups found.</td></tr>';
            }
        } catch (e) { list.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-red-400">Error loading.</td></tr>'; }
    }

    async function createBackup() {
        const fd = new FormData(); fd.append('ajax_action', 'create_backup');
        showToast('info', 'Processing...', 'Backup started.');
        await fetch('', { method: 'POST', body: fd });
        setTimeout(loadBackups, 2000);
    }

    async function restoreBackup(file) {
        if (!confirm('Restoring will overwrite current files and DBs. Continue?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'restore_backup');
        fd.append('file', file);
        showToast('info', 'Processing...', 'Restore job started.');
        await fetch('', { method: 'POST', body: fd });
        showToast('success', 'Restore Initiated');
    }

    // Init based on active tab
    <?php if ($active_tab == 'security'): ?>
        loadSSH();
    <?php elseif ($active_tab == 'backups'): ?>
        loadBackups();
    <?php elseif ($active_tab == 'ftp'): ?>
        loadFTP();
    <?php endif; ?>

</script>