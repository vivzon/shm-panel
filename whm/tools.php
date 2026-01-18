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
        if ($action == 'add_ftp') {
            if ($_POST['pass'] !== $_POST['pass2'])
                throw new Exception("Passwords do not match");
            $pass = password_hash($_POST['pass'], PASSWORD_BCRYPT);
            // Derive home from system user
            $home = "/home/" . $_POST['sys_user'] . "/public_html";
            $pdo->prepare("INSERT INTO ftp_users (userid, passwd, homedir) VALUES (?,?,?)")->execute([$_POST['ftp_user'], $pass, $home]);
        }

        if ($action == 'add_mail') {
            $full = $_POST['prefix'] . "@" . $_POST['domain'];
            $pass = password_hash($_POST['mail_pass'], PASSWORD_BCRYPT);
            $did = $pdo->query("SELECT id FROM mail_domains WHERE domain = '{$_POST['domain']}'")->fetchColumn();
            if (!$did)
                throw new Exception("Domain not found for mail");
            $pdo->prepare("INSERT INTO mail_users (domain_id, email, password) VALUES (?,?,?)")->execute([$did, $full, $pass]);
        }

        if ($action == 'set_php_handler') {
            // Stub implementation
            echo json_encode(['status' => 'success', 'msg' => 'PHP Handler Updated (Stub)']);
            exit;
        }

        if ($action == 'set_network_card') {
            // Stub implementation
            echo json_encode(['status' => 'success', 'msg' => 'Network Config Updated (Stub)']);
            exit;
        }

        echo json_encode($res);
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
    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden max-w-2xl">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-blue-600/10 rounded-full blur-3xl"></div>
        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-white font-heading">
            <div class="p-2 bg-blue-500/10 rounded-lg border border-blue-500/20 text-blue-500">
                <i data-lucide="folder-up" class="w-5 h-5"></i>
            </div>
            Create FTP Account
        </h3>
        <form onsubmit="handleGeneric(event, 'add_ftp')" class="space-y-4 relative z-10">
            <input name="ftp_user" required placeholder="Username"
                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-indigo-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
            <select name="sys_user" required
                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-indigo-500 focus:bg-slate-900 transition">
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['username'] ?>">System User: <?= $c['username'] ?></option>
                <?php endforeach; ?>
            </select>
            <div class="grid grid-cols-2 gap-4">
                <input name="pass" required type="password" placeholder="Password"
                    class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-indigo-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                <input name="pass2" required type="password" placeholder="Confirm"
                    class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-indigo-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
            </div>
            <button
                class="w-full bg-indigo-600 hover:bg-indigo-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-indigo-600/20 text-white transition border border-indigo-500/50">Create
                FTP User</button>
        </form>
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
            <button
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
            <button
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
            <button
                class="w-full bg-orange-600 hover:bg-orange-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-orange-600/20 text-white transition border border-orange-500/50">Update
                Interface</button>
        </form>
    </div>
</div>

<?php include 'layout/footer.php'; ?>