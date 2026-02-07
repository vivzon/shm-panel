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
        // --- FTP HANDLERS ---
        if ($action == 'add_ftp') {
            if ($_POST['pass'] !== $_POST['pass2'])
                throw new Exception("Passwords do not match");

            $sys_user = $_POST['sys_user'];
            $ftp_user = $_POST['ftp_user'] . '@' . $sys_user; // Enforce user@client
            $pass = password_hash($_POST['pass'], PASSWORD_BCRYPT);

            // Default home to /var/www/clients/user/public_html
            $home = "/var/www/clients/$sys_user/public_html";

            // Get System User UID/GID
            $sys_user_info = function_exists('posix_getpwnam') ? posix_getpwnam($sys_user) : null;
            if (!$sys_user_info && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN')
                throw new Exception("System user not found on server");
            
            $uid = $sys_user_info['uid'] ?? 33;
            $gid = $sys_user_info['gid'] ?? 33;

            $check = $pdo->prepare("SELECT count(*) FROM ftp_users WHERE userid = ?");
            $check->execute([$ftp_user]);
            if ($check->fetchColumn() > 0)
                throw new Exception("FTP User already exists");

            $pdo->prepare("INSERT INTO ftp_users (userid, passwd, homedir, uid, gid) VALUES (?,?,?,?,?)")->execute([$ftp_user, $pass, $home, $uid, $gid]);
            echo json_encode(['status' => 'success', 'msg' => 'FTP Account Created Successfully']);
            exit;
        }

        if ($action == 'list_ftp') {
            $stmt = $pdo->query("SELECT userid, homedir FROM ftp_users ORDER BY userid ASC");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($action == 'del_ftp') {
            $pdo->prepare("DELETE FROM ftp_users WHERE userid = ?")->execute([$_POST['user']]);
            echo json_encode(['status' => 'success', 'msg' => 'FTP Account Deleted']);
            exit;
        }

        // --- MAIL HANDLERS ---
        if ($action == 'add_mail') {
            $full = $_POST['prefix'] . "@" . $_POST['domain'];
            $pass = password_hash($_POST['mail_pass'], PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("SELECT id FROM mail_domains WHERE domain = ?");
            $stmt->execute([$_POST['domain']]);
            $did = $stmt->fetchColumn();
            
            if (!$did)
                throw new Exception("Domain not found for mail");
            
            $pdo->prepare("INSERT INTO mail_users (domain_id, email, password) VALUES (?,?,?)")->execute([$did, $full, $pass]);
            echo json_encode(['status' => 'success', 'msg' => 'Mailbox Created Successfully']);
            exit;
        }

        if ($action == 'set_php_handler') {
            $php_ver = $_POST['php_version'];
            $sys_user = $_POST['sys_user'];
            
            if (!in_array($php_ver, ['8.1', '8.2', '8.3'])) throw new Exception("Invalid PHP Version");
            
            // In a real scenario, we find domains belonging to this user or update a global config
            // For now, let's sync all domains of this user (simplified)
            $stmt = $pdo->prepare("SELECT id FROM domains d JOIN clients c ON d.client_id=c.id WHERE c.username=?");
            $stmt->execute([$sys_user]);
            $domain_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach($domain_ids as $did) {
                $pdo->prepare("UPDATE domains SET php_version = ? WHERE id = ?")->execute([$php_ver, $did]);
                cmd("vhost-tool sync " . (int)$did);
            }
            
            echo json_encode(['status' => 'success', 'msg' => "PHP Handler updated for user $sys_user"]);
            exit;
        }

        if ($action == 'set_network_card') {
            $interface = $_POST['interface'];
            if (!preg_match('/^[a-z0-9]+$/i', $interface)) throw new Exception("Invalid interface name");
            
            // Command to update system network config could go here
            cmd("system-info network-update " . escapeshellarg($interface));
            
            echo json_encode(['status' => 'success', 'msg' => "Network Interface set to $interface"]);
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

<div class="mb-8">
    <h2 class="text-2xl font-bold text-white mb-2">System Tools</h2>
    <p class="text-slate-400 text-sm">Configure system services and accounts.</p>
</div>

<!-- TABS -->
<div class="flex border-b border-slate-800 mb-8 overflow-x-auto">
    <a href="?tab=ftp"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'ftp' ? 'border-indigo-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
        FTP Manager
    </a>
    <a href="?tab=mail"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'mail' ? 'border-indigo-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
        Mail Manager
    </a>
    <a href="?tab=php"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'php' ? 'border-indigo-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
        PHP Config
    </a>
    <a href="?tab=network"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'network' ? 'border-indigo-500 text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
        Network Settings
    </a>
</div>

<!-- CONTENT: FTP -->
<div class="<?= $active_tab == 'ftp' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- CREATE FTP -->
        <div class="glass-panel p-8 rounded-3xl relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-40 h-40 bg-blue-600/10 rounded-full blur-3xl"></div>
            <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-white font-heading">
                <div class="p-2 bg-blue-500/10 rounded-lg border border-blue-500/20 text-blue-500">
                    <i data-lucide="folder-up" class="w-5 h-5"></i>
                </div>
                Create FTP Account
            </h3>
            <form onsubmit="handleFTPCreate(event)" class="space-y-4 relative z-10">
                <div class="grid grid-cols-2 gap-4">
                    <input name="ftp_user" required placeholder="Pre-fix (e.g. dev)"
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-indigo-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                    <select name="sys_user" required
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-indigo-500 focus:bg-slate-900 transition">
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['username'] ?>">@<?= $c['username'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <input name="pass" required type="password" placeholder="Password"
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-indigo-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                    <input name="pass2" required type="password" placeholder="Confirm"
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-indigo-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                </div>
                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-indigo-600/20 text-white transition border border-indigo-500/50">
                    Create FTP User
                </button>
            </form>
        </div>

        <!-- LIST FTP -->
        <div class="glass-panel p-8 rounded-3xl relative overflow-hidden flex flex-col h-full">
            <h3 class="text-xl font-bold mb-6 text-white font-heading">Existing Accounts</h3>
            <div class="overflow-y-auto flex-1 custom-scrollbar max-h-[400px]">
                <table class="w-full text-left">
                    <thead
                        class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400 sticky top-0 backdrop-blur-md">
                        <tr>
                            <th class="p-3">User</th>
                            <th class="p-3">Home</th>
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

<!-- CONTENT: MAIL -->
<div class="<?= $active_tab == 'mail' ? '' : 'hidden' ?>">
    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden max-w-2xl">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-emerald-600/10 rounded-full blur-3xl"></div>
        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-white font-heading">
            <div class="p-2 bg-emerald-500/10 rounded-lg border border-emerald-500/20 text-emerald-500">
                <i data-lucide="mail-plus" class="w-5 h-5"></i>
            </div>
            Create Email Account
        </h3>
        <form onsubmit="handleGeneric(event, 'add_mail')" class="space-y-4 relative z-10">
            <div class="flex gap-2">
                <input name="prefix" required placeholder="user"
                    class="flex-1 bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-indigo-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition text-right">
                <div class="flex items-center text-slate-500 font-bold">@</div>
                <select name="domain" required
                    class="flex-1 bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-indigo-500 focus:bg-slate-900 transition">
                    <?php foreach ($mail_domains as $d): ?>
                        <option value="<?= $d['domain'] ?>"><?= $d['domain'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input name="mail_pass" required type="password" placeholder="Password"
                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-indigo-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition mb-2">
            <button type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-indigo-600/20 text-white transition border border-indigo-500/50">Create
                Mailbox</button>
        </form>
    </div>
</div>

<!-- CONTENT: PHP -->
<div class="<?= $active_tab == 'php' ? '' : 'hidden' ?>">
    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden max-w-2xl">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-purple-600/10 rounded-full blur-3xl"></div>
        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-white font-heading">
            <div class="p-2 bg-purple-500/10 rounded-lg border border-purple-500/20 text-purple-500">
                <i data-lucide="code" class="w-5 h-5"></i>
            </div>
            PHP Handlers
        </h3>
        <form onsubmit="handleGeneric(event, 'set_php_handler')" class="space-y-4 relative z-10">
            <select name="php_version" required
                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-indigo-500 focus:bg-slate-900 transition">
                <?php foreach ($php_versions as $v): ?>
                    <option value="<?= $v ?>"><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sys_user" required
                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-indigo-500 focus:bg-slate-900 transition">
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['username'] ?>">Root: <?= $c['username'] ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"
                class="w-full bg-purple-600 hover:bg-purple-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-purple-600/20 text-white transition border border-purple-500/50">Set
                PHP Handler</button>
        </form>
    </div>
</div>

<!-- CONTENT: NETWORK -->
<div class="<?= $active_tab == 'network' ? '' : 'hidden' ?>">
    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden max-w-2xl">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-orange-600/10 rounded-full blur-3xl"></div>
        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-white font-heading">
            <div class="p-2 bg-orange-500/10 rounded-lg border border-orange-500/20 text-orange-500">
                <i data-lucide="network" class="w-5 h-5"></i>
            </div>
            Network Config
        </h3>
        <form onsubmit="handleGeneric(event, 'set_network_card')" class="space-y-4 relative z-10">
            <div class="p-4 rounded-xl bg-orange-500/5 border border-orange-500/10 mb-4">
                <p class="text-xs text-orange-300/80 leading-relaxed">
                    <strong class="text-orange-400">Warning:</strong> Incorrect network settings may lock you out of the
                    admin panel. Proceed with caution.
                </p>
            </div>
            <select name="interface"
                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-indigo-500 focus:bg-slate-900 transition">
                <option value="eth0">eth0 (Default)</option>
                <option value="eth1">eth1</option>
            </select>
            <button type="submit"
                class="w-full bg-orange-600 hover:bg-orange-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-orange-600/20 text-white transition border border-orange-500/50">Update
                Interface</button>
        </form>
    </div>
</div>

<?php include 'layout/footer.php'; ?>
<script>
    // Generic Handler for simple forms
    async function handleGeneric(e, action) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>';
        lucide.createIcons();

        const fd = new FormData(e.target);
        fd.append('ajax_action', action);

        try {
            const response = await fetch('', { method: 'POST', body: fd });
            const res = await response.json();
            if (res.status === 'success') {
                showToast('success', res.msg || 'Success');
                if(action !== 'set_php_handler' && action !== 'set_network_card') e.target.reset();
            } else {
                showToast('error', res.msg || 'Action failed');
            }
        } catch (error) {
            showToast('error', 'Server Error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
            lucide.createIcons();
        }
    }

    // FTP Specific Logic
    async function loadFTP() {
        const list = document.getElementById('ftp-list');
        if (!list) return;
        const fd = new FormData(); fd.append('ajax_action', 'list_ftp');
        try {
            const response = await fetch('', { method: 'POST', body: fd });
            const res = await response.json();
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

    async function handleFTPCreate(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>';
        lucide.createIcons();

        const fd = new FormData(e.target);
        fd.append('ajax_action', 'add_ftp');

        try {
            const response = await fetch('', { method: 'POST', body: fd });
            const res = await response.json();
            if (res.status === 'success') {
                showToast('success', res.msg || 'FTP Account Created');
                e.target.reset();
                loadFTP();
            } else {
                showToast('error', res.msg || 'Failed to create FTP Account');
            }
        } catch (e) {
            showToast('error', 'Server Error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
            lucide.createIcons();
        }
    }

    async function delFTP(user) {
        if (!confirm('Delete FTP user ' + user + '?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'del_ftp');
        fd.append('user', user);
        try {
            const response = await fetch('', { method: 'POST', body: fd });
            const res = await response.json();
            if (res.status === 'success') {
                showToast('success', res.msg || 'Deleted');
                loadFTP();
            } else {
                showToast('error', res.msg || 'Delete failed');
            }
        } catch (e) {
            showToast('error', 'Server Error');
        }
    }

    // Init
    <?php if ($active_tab == 'ftp'): ?>
        loadFTP();
    <?php endif; ?>

</script>