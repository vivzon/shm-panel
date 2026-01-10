<?php
/** 
 * VIVZON CPANEL - MASTER PRODUCTION v4.5
 * Comprehensive: DB Users, Email Isolation, DNS, PHP Config, SSL, Usage Metrics
 */
require_once '../shared/config.php';

if(!isset($_SESSION['client'])) { header("Location: login.php"); exit; }
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

// --- AJAX API HANDLER ---
if(isset($_POST['ajax_action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        $limits = $pdo->query("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();

        /** 1. DATABASE & USER LOGIC **/
        if($action == 'add_db') {
            $curr = $pdo->query("SELECT COUNT(*) FROM client_databases WHERE client_id = $cid")->fetchColumn();
            if($curr >= $limits['max_databases']) throw new Exception("Plan database limit reached.");
            $db_name = $username . "_" . preg_replace('/[^a-z0-9_]/', '', $_POST['db_name']);
            $pdo->prepare("INSERT INTO client_databases (client_id, db_name) VALUES (?, ?)")->execute([$cid, $db_name]);
            sendResponse($res); cmd("mysql-tool create-db " . escapeshellarg($db_name)); exit;
        }

        if($action == 'add_db_user') {
            $db_user = $username . "_" . preg_replace('/[^a-z0-9_]/', '', $_POST['db_user']);
            $pdo->prepare("INSERT INTO client_db_users (client_id, db_user) VALUES (?, ?)")->execute([$cid, $db_user]);
            sendResponse($res); cmd("mysql-tool create-user " . escapeshellarg($db_user) . " " . escapeshellarg($_POST['db_pass']) . " " . escapeshellarg($_POST['target_db'])); exit;
        }

        /** 2. EMAIL LOGIC **/
        if($action == 'add_email') {
            $curr = $pdo->query("SELECT COUNT(*) FROM mail_users WHERE domain_id IN (SELECT id FROM mail_domains WHERE domain IN (SELECT domain FROM domains WHERE client_id = $cid))")->fetchColumn();
            if($curr >= $limits['max_emails']) throw new Exception("Email limit reached.");
            $did = $pdo->query("SELECT id FROM mail_domains WHERE domain = '{$_POST['domain']}'")->fetchColumn();
            if(!$did) { $pdo->prepare("INSERT INTO mail_domains (domain) VALUES (?)")->execute([$_POST['domain']]); $did = $pdo->lastInsertId(); }
            $pdo->prepare("INSERT INTO mail_users (domain_id, email, password) VALUES (?, ?, ?)")->execute([$did, $_POST['user']."@".$_POST['domain'], password_hash($_POST['pass'], PASSWORD_BCRYPT)]);
            sendResponse($res); exit;
        }

        /** 3. DNS, PHP, & SSL LOGIC **/
        if($action == 'add_dns') {
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, ?, ?, ?)")->execute([$_POST['domain_id'], $_POST['type'], $_POST['host'], $_POST['value']]);
            sendResponse($res); cmd("dns-tool sync " . (int)$_POST['domain_id']); exit;
        }

        if($action == 'update_domain_config') {
            $pdo->prepare("UPDATE domains SET php_version = ?, ssl_active = ? WHERE id = ? AND client_id = ?")->execute([$_POST['php_version'], isset($_POST['ssl'])?1:0, $_POST['domain_id'], $cid]);
            $pdo->prepare("INSERT INTO php_config (domain_id, memory_limit) VALUES (?, ?) ON DUPLICATE KEY UPDATE memory_limit=VALUES(memory_limit)")->execute([$_POST['domain_id'], $_POST['mem']]);
            sendResponse($res); cmd("vhost-tool sync " . (int)$_POST['domain_id']); exit;
        }

    } catch (Exception $e) { sendResponse(['status' => 'error', 'msg' => $e->getMessage()]); }
    exit;
}

// DATA FOR DASHBOARD
$client = $pdo->query("SELECT c.*, p.name as pkg_name, p.max_emails, p.max_databases, p.max_domains, p.disk_mb FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();
$my_dbs = $pdo->query("SELECT * FROM client_databases WHERE client_id = $cid")->fetchAll();
$my_emails = $pdo->query("SELECT mu.* FROM mail_users mu JOIN mail_domains md ON mu.domain_id = md.id WHERE md.domain IN (SELECT domain FROM domains WHERE client_id = $cid)")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><title>Vivzon Cpanel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style> .pane { display: none; } .pane.active { display: block; animation: fadeIn 0.3s; } @keyframes fadeIn { from{opacity:0} to{opacity:1} } </style>
</head>
<body class="bg-slate-50 flex h-screen overflow-hidden text-slate-800">

    <!-- Sidebar Navigation -->
    <aside class="w-72 bg-slate-950 text-white flex flex-col p-6 shadow-2xl">
        <div class="text-2xl font-black text-blue-500 mb-10 flex items-center gap-2 italic"><i data-lucide="shield-check"></i> VIVZON</div>
        <nav class="flex-1 space-y-1">
            <button onclick="tab('dash')" class="nav-btn active w-full text-left p-4 rounded-2xl hover:bg-slate-800 flex gap-4 transition"><i data-lucide="layout"></i> Dashboard</button>
            <button onclick="tab('files')" class="nav-btn w-full text-left p-4 rounded-2xl hover:bg-slate-800 flex gap-4 transition"><i data-lucide="folder"></i> File Manager</button>
            <button onclick="tab('db')" class="nav-btn w-full text-left p-4 rounded-2xl hover:bg-slate-800 flex gap-4 transition"><i data-lucide="database"></i> Databases</button>
            <button onclick="tab('mail')" class="nav-btn w-full text-left p-4 rounded-2xl hover:bg-slate-800 flex gap-4 transition"><i data-lucide="mail"></i> Email Accounts</button>
            <button onclick="tab('dom')" class="nav-btn w-full text-left p-4 rounded-2xl hover:bg-slate-800 flex gap-4 transition"><i data-lucide="globe"></i> DNS & PHP</button>
        </nav>
        <a href="logout.php" class="p-4 text-red-400 flex gap-3 font-bold hover:text-red-300 transition"><i data-lucide="log-out"></i> Logout</a>
    </aside>

    <main class="flex-1 p-10 overflow-y-auto">
        
        <!-- DASHBOARD & RESOURCE METRICS -->
        <div id="pane-dash" class="pane active">
            <h2 class="text-3xl font-bold mb-8 tracking-tight">Cpanel Dashboard</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-10">
                <div class="bg-white p-6 rounded-[2rem] border shadow-sm">
                    <div class="flex justify-between mb-4"><span class="text-xs font-bold text-slate-400 uppercase">Databases</span><span class="font-bold"><?=count($my_dbs)?> / <?=$client['max_databases']?></span></div>
                    <div class="h-2 bg-slate-100 rounded-full overflow-hidden"><div class="bg-blue-600 h-full" style="width:<?=(count($my_dbs)/$client['max_databases'])*100?>%"></div></div>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border shadow-sm">
                    <div class="flex justify-between mb-4"><span class="text-xs font-bold text-slate-400 uppercase">Emails</span><span class="font-bold"><?=count($my_emails)?> / <?=$client['max_emails']?></span></div>
                    <div class="h-2 bg-slate-100 rounded-full overflow-hidden"><div class="bg-purple-600 h-full" style="width:<?=(count($my_emails)/$client['max_emails'])*100?>%"></div></div>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border shadow-sm">
                    <div class="flex justify-between mb-4"><span class="text-xs font-bold text-slate-400 uppercase">Domain Slots</span><span class="font-bold"><?=count($domains)?> / <?=$client['max_domains']?></span></div>
                    <div class="h-2 bg-slate-100 rounded-full overflow-hidden"><div class="bg-emerald-600 h-full" style="width:<?=(count($domains)/$client['max_domains'])*100?>%"></div></div>
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <a href="http://filemanager.vivzon.cloud" target="_blank" class="bg-white p-8 rounded-3xl border text-center hover:shadow-xl transition group border-orange-100"><i data-lucide="folder-open" class="mx-auto w-10 h-10 text-orange-500 mb-3 group-hover:scale-110 transition"></i><p class="font-bold">File Manager</p></a>
                <a href="http://phpmyadmin.vivzon.cloud" target="_blank" class="bg-white p-8 rounded-3xl border text-center hover:shadow-xl transition group border-blue-100"><i data-lucide="database" class="mx-auto w-10 h-10 text-blue-500 mb-3 group-hover:scale-110 transition"></i><p class="font-bold">phpMyAdmin</p></a>
                <a href="http://webmail.vivzon.cloud" target="_blank" class="bg-white p-8 rounded-3xl border text-center hover:shadow-xl transition group border-purple-100"><i data-lucide="mail" class="mx-auto w-10 h-10 text-purple-500 mb-3 group-hover:scale-110 transition"></i><p class="font-bold">Webmail</p></a>
                <div onclick="tab('dom')" class="bg-white p-8 rounded-3xl border text-center hover:shadow-xl transition group border-emerald-100 cursor-pointer"><i data-lucide="shield-check" class="mx-auto w-10 h-10 text-emerald-500 mb-3 group-hover:scale-110 transition"></i><p class="font-bold">SSL & DNS</p></div>
            </div>
        </div>

        <!-- DATABASE USER & TABLE MANAGEMENT -->
        <div id="pane-db" class="pane">
            <h2 class="text-2xl font-bold mb-8">MySQL® Databases</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
                <div class="bg-white p-8 rounded-[2rem] border shadow-sm">
                    <h3 class="font-bold mb-4">Create New Database</h3>
                    <form onsubmit="handle(event, 'add_db')" class="flex gap-2">
                        <span class="bg-slate-100 p-4 rounded-xl font-bold text-slate-400"><?=$username?>_</span>
                        <input name="db_name" required class="flex-1 bg-slate-50 border p-4 rounded-xl outline-none focus:border-blue-500">
                        <button class="bg-blue-600 text-white px-6 rounded-xl font-bold">Create</button>
                    </form>
                </div>
                <div class="bg-white p-8 rounded-[2rem] border shadow-sm">
                    <h3 class="font-bold mb-4">Add User to Database</h3>
                    <form onsubmit="handle(event, 'add_db_user')" class="space-y-4">
                        <div class="flex items-center bg-slate-50 border rounded-xl"><span class="pl-4 font-bold text-slate-400"><?=$username?>_</span><input name="db_user" required class="flex-1 bg-transparent p-4 outline-none"></div>
                        <input name="db_pass" type="password" required placeholder="User Password" class="w-full bg-slate-50 border p-4 rounded-xl outline-none">
                        <select name="target_db" class="w-full bg-slate-50 border p-4 rounded-xl outline-none">
                            <?php foreach($my_dbs as $db): ?><option value="<?=$db['db_name']?>">Access to: <?=$db['db_name']?></option><?php endforeach; ?>
                        </select>
                        <button class="w-full bg-slate-900 text-white p-4 rounded-xl font-bold">Create User</button>
                    </form>
                </div>
            </div>
            <div class="bg-white rounded-3xl border overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-400 tracking-widest">
                        <tr><th class="p-6">Current Database Name</th><th class="p-6 text-right">Login / Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($my_dbs as $db): ?>
                        <tr class="border-t border-slate-100">
                            <td class="p-6 font-mono text-blue-600"><?=$db['db_name']?></td>
                            <td class="p-6 text-right">
                                <a href="http://phpmyadmin.vivzon.cloud" target="_blank" class="text-blue-500 font-bold text-xs mr-4 uppercase">phpMyAdmin</a>
                                <button onclick="deleteAction('delete_db', 'db_name', '<?=$db['db_name']?>')" class="text-red-500 hover:bg-red-50 p-2 rounded-lg transition"><i data-lucide="trash-2"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- EMAIL MANAGEMENT -->
        <div id="pane-mail" class="pane">
            <h2 class="text-2xl font-bold mb-8">Email Mailboxes</h2>
            <form onsubmit="handle(event, 'add_email')" class="bg-white p-10 rounded-[2.5rem] border shadow-sm grid grid-cols-4 gap-4 mb-10">
                <input name="user" required placeholder="mailbox name" class="bg-slate-50 border p-4 rounded-2xl outline-none focus:border-blue-500">
                <select name="domain" class="bg-slate-50 border p-4 rounded-2xl outline-none">
                    <?php foreach($domains as $d): ?><option value="<?=$d['domain']?>">@<?=$d['domain']?></option><?php endforeach; ?>
                </select>
                <input name="pass" type="password" required placeholder="Password" class="bg-slate-50 border p-4 rounded-2xl outline-none focus:border-blue-500">
                <button class="bg-blue-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-600/20">Create Mailbox</button>
            </form>
            <div class="bg-white rounded-3xl border overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-400">
                        <tr><th class="p-6">Active Email Account</th><th class="p-6 text-right">Webmail / Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($my_emails as $mail): ?>
                        <tr class="border-t">
                            <td class="p-6 font-bold text-slate-700"><?=$mail['email']?></td>
                            <td class="p-6 text-right">
                                <a href="http://webmail.vivzon.cloud" target="_blank" class="text-blue-500 font-bold text-xs mr-4 uppercase tracking-tighter">Login</a>
                                <button class="text-red-500 hover:bg-red-50 p-2 rounded-lg transition"><i data-lucide="trash-2" class="w-4"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- DNS & PHP CONFIG -->
        <div id="pane-dom" class="pane">
            <h2 class="text-2xl font-bold mb-8">Domain Hosting Configuration</h2>
            <?php foreach($domains as $d): ?>
            <div class="bg-white p-10 rounded-[2.5rem] border mb-8 shadow-sm">
                <div class="flex justify-between items-center mb-10">
                    <div>
                        <h3 class="text-2xl font-black text-slate-900"><?=$d['domain']?></h3>
                        <p class="text-xs text-slate-400 font-mono mt-1">Root: /home/<?=$username?>/public_html</p>
                    </div>
                    <form onsubmit="handle(event, 'update_domain_config')" class="flex items-center gap-4 bg-slate-50 p-4 rounded-3xl border">
                        <input type="hidden" name="domain_id" value="<?=$d['id']?>">
                        <select name="php_version" class="bg-white border p-2 rounded-xl text-xs font-bold">
                            <option value="8.1" <?=$d['php_version']=='8.1'?'selected':''?>>PHP 8.1</option>
                            <option value="8.2" <?=$d['php_version']=='8.2'?'selected':''?>>PHP 8.2</option>
                            <option value="8.3" <?=$d['php_version']=='8.3'?'selected':''?>>PHP 8.3</option>
                        </select>
                        <select name="mem" class="bg-white border p-2 rounded-xl text-xs font-bold">
                            <option>128M</option><option>256M</option><option>512M</option>
                        </select>
                        <div class="flex items-center gap-2 px-2 border-l border-slate-200">
                            <input type="checkbox" name="ssl" <?=$d['ssl_active']?'checked':''?> class="w-4 h-4 text-emerald-500">
                            <span class="text-[10px] font-bold uppercase text-slate-500">SSL</span>
                        </div>
                        <button class="bg-blue-600 text-white p-2 rounded-lg"><i data-lucide="save" class="w-4"></i></button>
                    </form>
                </div>
                <div class="border-t pt-8">
                    <h4 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-6">DNS Zone Management</h4>
                    <form onsubmit="handle(event, 'add_dns')" class="grid grid-cols-4 gap-3 mb-4">
                        <input type="hidden" name="domain_id" value="<?=$d['id']?>">
                        <input name="host" placeholder="Host (e.g. @)" class="bg-slate-50 p-4 rounded-xl border text-sm" required>
                        <select name="type" class="bg-slate-50 p-4 rounded-xl border text-sm font-bold"><option>A</option><option>CNAME</option><option>MX</option><option>TXT</option></select>
                        <input name="value" placeholder="Value (IP or Domain)" class="bg-slate-50 p-4 rounded-xl border text-sm" required>
                        <button class="bg-slate-900 text-white rounded-xl font-bold text-xs uppercase shadow-xl">Add Record</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </main>

    <script>
        lucide.createIcons();
        function tab(id) {
            document.querySelectorAll('.pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('bg-slate-800', 'text-blue-400'));
            document.getElementById('pane-' + id).classList.add('active');
            event.currentTarget.classList.add('bg-slate-800', 'text-blue-400');
        }

        async function handle(e, action) {
            e.preventDefault();
            const btn = e.target.querySelector('button');
            const oldHtml = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = "Syncing Node...";
            const fd = new FormData(e.target); fd.append('ajax_action', action);
            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                if(res.status === 'success') { 
                    btn.innerHTML = "Success!";
                    setTimeout(() => location.reload(), 800);
                } else { alert(res.msg); btn.disabled = false; btn.innerHTML = oldHtml; }
            } catch (err) { location.reload(); }
        }

        async function deleteAction(action, key, val) {
            if(!confirm("Permanent Action: Are you sure?")) return;
            const fd = new FormData(); fd.append('ajax_action', action); fd.append(key, val);
            await fetch('', { method: 'POST', body: fd });
            location.reload();
        }
    </script>
</body>
</html>
