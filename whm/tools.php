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

<div class="mb-8">
    <h2 class="text-2xl font-bold text-slate-900 mb-2">System Tools</h2>
    <p class="text-slate-700 text-sm">Configure system services and accounts.</p>
</div>

<!-- TABS -->
<div class="flex border-b border-slate-300 mb-8 overflow-x-auto">
    <a href="?tab=ftp"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'ftp' ? 'border-indigo-500 text-slate-900' : 'border-transparent text-slate-700 hover:text-slate-700' ?>">
        FTP Manager
    </a>
    <a href="?tab=mail"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'mail' ? 'border-indigo-500 text-slate-900' : 'border-transparent text-slate-700 hover:text-slate-700' ?>">
        Mail Manager
    </a>
    <a href="?tab=php"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'php' ? 'border-indigo-500 text-slate-900' : 'border-transparent text-slate-700 hover:text-slate-700' ?>">
        PHP Config
    </a>
    <a href="?tab=network"
        class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab == 'network' ? 'border-indigo-500 text-slate-900' : 'border-transparent text-slate-700 hover:text-slate-700' ?>">
        Network Settings
    </a>
</div>

<!-- CONTENT: FTP -->
<div class="<?= $active_tab == 'ftp' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- CREATE FTP -->
        <div class="glass-panel p-8 rounded-3xl relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-40 h-40 bg-blue-600/10 rounded-full blur-3xl"></div>
            <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-slate-900 font-heading">
                <div class="p-2 bg-blue-500/10 rounded-lg border border-blue-500/20 text-blue-500">
                    <i data-lucide="folder-up" class="w-5 h-5"></i>
                </div>
                Create FTP Account
            </h3>
            <form onsubmit="handleToolAction(event, 'add_ftp', loadFTP)" class="space-y-4 relative z-10">
                <div class="grid grid-cols-2 gap-4">
                    <input name="ftp_user" required placeholder="Pre-fix (e.g. dev)"
                        class="w-full bg-slate-50 p-4 rounded-xl border border-slate-300 outline-none focus:border-indigo-500 text-slate-900 placeholder:text-slate-700 focus:bg-white transition">
                    <select name="sys_user" required
                        class="w-full bg-slate-50 p-4 rounded-xl border border-slate-300 text-slate-700 outline-none focus:border-indigo-500 focus:bg-white transition">
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['username'] ?>">@<?= $c['username'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <input name="pass" required type="password" placeholder="Password"
                        class="w-full bg-slate-50 p-4 rounded-xl border border-slate-300 outline-none focus:border-indigo-500 text-slate-900 placeholder:text-slate-700 focus:bg-white transition">
                    <input name="pass2" required type="password" placeholder="Confirm"
                        class="w-full bg-slate-50 p-4 rounded-xl border border-slate-300 outline-none focus:border-indigo-500 text-slate-900 placeholder:text-slate-700 focus:bg-white transition">
                </div>
                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-indigo-600/20 text-slate-900 transition border border-indigo-500/50">
                    Create FTP User
                </button>
            </form>
        </div>

        <!-- LIST FTP -->
        <div class="glass-panel p-8 rounded-3xl relative overflow-hidden flex flex-col h-full">
            <h3 class="text-xl font-bold mb-6 text-slate-900 font-heading">Existing Accounts</h3>
            <div class="overflow-y-auto flex-1 custom-scrollbar max-h-[400px]">
                <table class="w-full text-left">
                    <thead
                        class="bg-slate-50 text-[10px] font-bold uppercase text-slate-700 sticky top-0 backdrop-blur-md">
                        <tr>
                            <th class="p-3">User</th>
                            <th class="p-3">Home</th>
                            <th class="p-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody id="ftp-list" class="divide-y divide-slate-700/50">
                        <tr>
                            <td colspan="3" class="p-4 text-center text-slate-700">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- CONTENT: MAIL -->
<div class="<?= $active_tab == 'mail' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- CREATE MAIL -->
        <div class="glass-panel p-8 rounded-3xl relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-40 h-40 bg-emerald-600/10 rounded-full blur-3xl"></div>
            <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-slate-900 font-heading">
                <div class="p-2 bg-emerald-500/10 rounded-lg border border-emerald-500/20 text-emerald-500">
                    <i data-lucide="mail-plus" class="w-5 h-5"></i>
                </div>
                Create Email Account
            </h3>
            <form onsubmit="handleToolAction(event, 'add_mail', loadMail)" class="space-y-4 relative z-10">
                <div class="flex gap-2">
                    <input name="prefix" required placeholder="user"
                        class="flex-1 bg-slate-50 p-4 rounded-xl border border-slate-300 outline-none focus:border-indigo-500 text-slate-900 placeholder:text-slate-700 focus:bg-white transition text-right">
                    <div class="flex items-center text-slate-700 font-bold">@</div>
                    <select name="domain" required
                        class="flex-1 bg-slate-50 p-4 rounded-xl border border-slate-300 text-slate-700 outline-none focus:border-indigo-500 focus:bg-white transition">
                        <?php foreach ($mail_domains as $d): ?>
                            <option value="<?= $d['domain'] ?>"><?= $d['domain'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Hidden fake fields to prevent chrome autocomplete mess -->
                <input style="display:none" type="text" name="fakeusernameremembered" />
                <input style="display:none" type="password" name="fakepasswordremembered" />

                <input name="mail_pass" required type="password" placeholder="Password" autocomplete="new-password"
                    class="w-full bg-slate-50 p-4 rounded-xl border border-slate-300 outline-none focus:border-indigo-500 text-slate-900 placeholder:text-slate-700 focus:bg-white transition mb-2">
                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-indigo-600/20 text-slate-900 transition border border-indigo-500/50">Create
                    Mailbox</button>
            </form>
        </div>

        <!-- LIST MAIL -->
        <div class="glass-panel p-8 rounded-3xl relative overflow-hidden flex flex-col h-full">
            <h3 class="text-xl font-bold mb-6 text-slate-900 font-heading">Existing Mailboxes</h3>
            <div class="overflow-y-auto flex-1 custom-scrollbar max-h-[400px]">
                <table class="w-full text-left">
                    <thead
                        class="bg-slate-50 text-[10px] font-bold uppercase text-slate-700 sticky top-0 backdrop-blur-md">
                        <tr>
                            <th class="p-3">Email Address</th>
                            <th class="p-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="mail-list" class="divide-y divide-slate-700/50">
                        <tr>
                            <td colspan="2" class="p-4 text-center text-slate-700">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- CONTENT: PHP -->
<div class="<?= $active_tab == 'php' ? '' : 'hidden' ?>">
    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden max-w-2xl">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-purple-600/10 rounded-full blur-3xl"></div>
        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-slate-900 font-heading">
            <div class="p-2 bg-purple-500/10 rounded-lg border border-purple-500/20 text-purple-500">
                <i data-lucide="code" class="w-5 h-5"></i>
            </div>
            PHP Handlers
        </h3>
        <form onsubmit="handleToolAction(event, 'set_php_handler')" class="space-y-4 relative z-10">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-slate-700 font-bold uppercase mb-1 block">User / Site</label>
                    <select name="sys_user" required
                        class="w-full bg-slate-50 p-4 rounded-xl border border-slate-300 text-slate-700 outline-none focus:border-indigo-500 focus:bg-white transition font-mono text-sm">
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['username'] ?>"><?= $c['username'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-slate-700 font-bold uppercase mb-1 block">PHP Version</label>
                    <select name="php_version" required
                        class="w-full bg-slate-50 p-4 rounded-xl border border-slate-300 text-slate-700 outline-none focus:border-indigo-500 focus:bg-white transition font-mono text-sm">
                        <?php foreach ($php_versions as $v): ?>
                            <option value="<?= $v ?>">PHP <?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit"
                class="w-full bg-purple-600 hover:bg-purple-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-purple-600/20 text-slate-900 transition border border-purple-500/50">
                Update Handlers
            </button>
        </form>
    </div>
</div>

<!-- CONTENT: NETWORK -->
<div class="<?= $active_tab == 'network' ? '' : 'hidden' ?>">
    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden max-w-2xl">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-orange-600/10 rounded-full blur-3xl"></div>
        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-slate-900 font-heading">
            <div class="p-2 bg-orange-500/10 rounded-lg border border-orange-500/20 text-orange-500">
                <i data-lucide="network" class="w-5 h-5"></i>
            </div>
            Network Config
        </h3>
        <form onsubmit="handleToolAction(event, 'set_network_card')" class="space-y-4 relative z-10">
            <div class="p-4 rounded-xl bg-orange-500/5 border border-orange-500/10 mb-4">
                <p class="text-xs text-orange-300/80 leading-relaxed">
                    <strong class="text-orange-400">Warning:</strong> Incorrect network settings may lock you out of the
                    admin panel. Proceed with caution.
                </p>
            </div>
            <select name="interface"
                class="w-full bg-slate-50 p-4 rounded-xl border border-slate-300 text-slate-700 outline-none focus:border-indigo-500 focus:bg-white transition font-mono">
                <option value="eth0">eth0 (Standard Cloud)</option>
                <option value="eth1">eth1 (Secondary)</option>
                <option value="ens3">ens3 (KVM)</option>
                <option value="ens18">ens18 (Proxmox)</option>
            </select>
            <button type="submit"
                class="w-full bg-orange-600 hover:bg-orange-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-orange-600/20 text-slate-900 transition border border-orange-500/50">Update
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
                        <tr class="hover:bg-slate-50/30 transition group border-b border-slate-300 last:border-0">
                            <td class="p-4 font-mono text-xs text-blue-300 font-bold">${u.userid}</td>
                            <td class="p-4 text-slate-700 text-xs truncate max-w-[150px] font-mono">${u.homedir}</td>
                            <td class="p-4 text-right">
                                <button onclick="delFTP('${u.userid}')" class="p-2 rounded-lg hover:bg-red-500/10 text-red-400 opacity-50 group-hover:opacity-100 hover:text-red-500 transition"><i data-lucide="trash-2" class="w-4"></i></button>
                            </td>
                        </tr>
                     `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<tr><td colspan="3" class="p-8 text-center text-slate-700 italic text-sm">No FTP accounts found.</td></tr>';
            }
        } catch (e) { list.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-red-400">Error loading data.</td></tr>'; }
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
                        <tr class="hover:bg-slate-50/30 transition group border-b border-slate-300 last:border-0">
                            <td class="p-4 text-sm text-slate-900 font-medium">
                                ${u.email}
                                <div class="text-[10px] text-slate-700 font-mono mt-0.5">${u.domain}</div>
                            </td>
                            <td class="p-4 text-right">
                                <button onclick="delMail(${u.id}, '${u.email}')" class="p-2 rounded-lg hover:bg-red-500/10 text-red-400 opacity-50 group-hover:opacity-100 hover:text-red-500 transition"><i data-lucide="trash-2" class="w-4"></i></button>
                            </td>
                        </tr>
                     `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<tr><td colspan="2" class="p-8 text-center text-slate-700 italic text-sm">No mailboxes found.</td></tr>';
            }
        } catch (e) { list.innerHTML = '<tr><td colspan="2" class="p-4 text-center text-red-400">Error loading data.</td></tr>'; }
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



