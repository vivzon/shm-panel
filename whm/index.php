<?php 
/**
 * VIVZON WHM - Production v27.0
 * Fixed: 502 Bad Gateway & JSON Syntax Error Resilience
 * Features: Full CRUD for Accounts, Packages, Services, FTP, and Mail.
 */

require_once '../shared/config.php'; 

// --- 1. AJAX API HANDLER ---
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Action processed'];

    try {
        /** HOSTING ACCOUNTS **/
        if ($action == 'save_account') {
            $id = $_POST['id'] ?? null;
            $u = trim($_POST['user']); $d = trim($_POST['dom']); 
            $e = trim($_POST['email']); $pkg = (int)$_POST['package_id'];

            if ($id) {
                $pdo->prepare("UPDATE clients SET email=?, package_id=? WHERE id=?")->execute([$e, $pkg, $id]);
                $pdo->prepare("UPDATE domains SET domain=? WHERE client_id=?")->execute([$d, $id]);
                if(!empty($_POST['pass'])) {
                    $hash = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE clients SET password=? WHERE id=?")->execute([$hash, $id]);
                }
            } else {
                $pdo->beginTransaction();
                $hash = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO clients (username, email, password, package_id) VALUES (?,?,?,?)")->execute([$u, $e, $hash, $pkg]);
                $cid = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO domains (client_id, domain) VALUES (?,?)")->execute([$cid, $d]);
                $pdo->prepare("INSERT INTO mail_domains (domain) VALUES (?)")->execute([$d]);
                $pdo->commit();
                
                // IMPORTANT: Send response BEFORE the long-running shell command to prevent 502
                echo json_encode($res);
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                
                cmd("create-account ".escapeshellarg($u)." ".escapeshellarg($d)." ".escapeshellarg($e)." ".escapeshellarg($_POST['pass']));
                exit;
            }
        }

        if ($action == 'delete_account') {
            $id = (int)$_POST['id']; $user = $_POST['user']; $dom = $_POST['dom'];
            $pdo->prepare("DELETE FROM domains WHERE client_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM mail_domains WHERE domain = ?")->execute([$dom]);
            $pdo->prepare("DELETE FROM ftp_users WHERE homedir LIKE ?")->execute(["%/home/$user%"]);
            $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);
            
            echo json_encode($res);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            cmd("delete-account ".escapeshellarg($user));
            exit;
        }

        /** SERVICE PACKAGES **/
        if ($action == 'save_package') {
            $id = $_POST['id'] ?? null;
            $vals = [$_POST['name'], $_POST['disk'], $_POST['doms'], $_POST['mails']];
            if ($id) {
                $pdo->prepare("UPDATE packages SET name=?, disk_mb=?, max_domains=?, max_emails=? WHERE id=?")->execute([...$vals, $id]);
            } else {
                $pdo->prepare("INSERT INTO packages (name, disk_mb, max_domains, max_emails) VALUES (?,?,?,?)")->execute($vals);
            }
        }

        if ($action == 'delete_package') {
            $pdo->prepare("DELETE FROM packages WHERE id = ?")->execute([$_POST['id']]);
        }

        /** FTP & MAIL **/
        if ($action == 'add_ftp') {
            $pass = password_hash($_POST['ftp_pass'], PASSWORD_BCRYPT);
            $home = "/home/".$_POST['sys_user']."/public_html";
            $pdo->prepare("INSERT INTO ftp_users (userid, passwd, homedir) VALUES (?,?,?)")->execute([$_POST['ftp_user'], $pass, $home]);
        }

        if ($action == 'add_mail') {
            $full = $_POST['prefix']."@".$_POST['domain'];
            $pass = password_hash($_POST['mail_pass'], PASSWORD_BCRYPT);
            $did = $pdo->query("SELECT id FROM mail_domains WHERE domain = '{$_POST['domain']}'")->fetchColumn();
            $pdo->prepare("INSERT INTO mail_users (domain_id, email, password) VALUES (?,?,?)")->execute([$did, $full, $pass]);
        }

        /** SERVICE CONTROL (High 502 Risk) **/
        if ($action == 'service_action') {
            echo json_encode($res);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            cmd("service-control ".$_POST['op']." ".escapeshellarg($_POST['service']));
            exit;
        }

        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// --- 2. DATA COLLECTION ---
$packages = $pdo->query("SELECT * FROM packages")->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT c.*, d.domain, p.name as pkg_name FROM clients c LEFT JOIN domains d ON c.id = d.client_id LEFT JOIN packages p ON c.package_id = p.id ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$services = ['nginx' => 'Web Server', 'mariadb' => 'MariaDB SQL', 'php8.2-fpm' => 'PHP 8.2 Engine', 'proftpd' => 'FTP Server', 'postfix' => 'Mail Delivery'];
$stats = explode('|', (string)cmd("get-stats"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VIVZON WHM | Production</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #020617; color: #f1f5f9; }
        .view-pane { display: none; animation: fadeIn 0.2s ease-out; }
        .view-pane.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .nav-link.active { background: #1e293b; color: #60a5fa; border-left: 4px solid #3b82f6; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col">
        <div class="p-6 text-xl font-black tracking-tighter border-b border-slate-800 flex items-center gap-2">
            <div class="bg-blue-600 p-1 rounded-md text-white"><i data-lucide="shield"></i></div> VIVZON <span class="text-blue-500">WHM</span>
        </div>
        <nav class="flex-1 p-4 space-y-1">
            <button onclick="switchTab('dash', this)" class="nav-link active w-full flex items-center gap-3 p-3 rounded-xl transition-all"><i data-lucide="layout-dashboard" class="w-5"></i> Dashboard</button>
            <button onclick="switchTab('acc', this)" class="nav-link w-full flex items-center gap-3 p-3 rounded-xl transition-all"><i data-lucide="users" class="w-5"></i> Accounts</button>
            <button onclick="switchTab('pkg', this)" class="nav-link w-full flex items-center gap-3 p-3 rounded-xl transition-all"><i data-lucide="package" class="w-5"></i> Packages</button>
            <button onclick="switchTab('serv', this)" class="nav-link w-full flex items-center gap-3 p-3 rounded-xl transition-all"><i data-lucide="activity" class="w-5"></i> Services</button>
            <button onclick="switchTab('hosting', this)" class="nav-link w-full flex items-center gap-3 p-3 rounded-xl transition-all"><i data-lucide="database" class="w-5"></i> FTP & Mail</button>
        </nav>
    </aside>

    <main class="flex-1 p-10 overflow-y-auto">
        
        <!-- DASHBOARD -->
        <div id="view-dash" class="view-pane active">
            <h2 class="text-3xl font-bold mb-8">System Health</h2>
            <div class="grid grid-cols-4 gap-6">
                <?php $icons=['cpu','layers','database','clock']; $labels=['CPU Usage','RAM Load','Disk Space','Uptime']; 
                foreach($labels as $i => $l): ?>
                <div class="bg-slate-900 p-6 rounded-3xl border border-slate-800 shadow-xl">
                    <div class="flex justify-between items-center mb-4 text-slate-500">
                        <i data-lucide="<?=$icons[$i]?>" class="w-5"></i>
                        <span class="text-[10px] font-bold uppercase"><?=$l?></span>
                    </div>
                    <p class="text-3xl font-bold"><?= $stats[$i] ?? '0' ?><?= $i<3?'%':'' ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ACCOUNTS -->
        <div id="view-acc" class="view-pane">
            <div class="flex justify-between items-center mb-8">
                <h2 class="text-3xl font-bold">Client Accounts</h2>
                <button onclick="openAccModal()" class="bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded-2xl font-bold shadow-lg shadow-blue-600/20">+ Create Account</button>
            </div>
            <div class="bg-slate-900 rounded-3xl border border-slate-800 overflow-hidden shadow-2xl">
                <table class="w-full text-left">
                    <thead class="bg-slate-800 text-slate-400 text-[10px] font-bold uppercase tracking-widest">
                        <tr><th class="p-5">User / Primary Domain</th><th class="p-5">Package</th><th class="p-5 text-right">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach($clients as $c): ?>
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="p-5">
                                <div class="font-bold text-white"><?=$c['username']?></div>
                                <div class="text-xs text-blue-400"><?=$c['domain']?></div>
                            </td>
                            <td class="p-5"><span class="bg-slate-800 px-3 py-1 rounded-full text-[11px] font-bold"><?=$c['pkg_name']?></span></td>
                            <td class="p-5 text-right flex justify-end gap-2">
                                <button onclick='openAccModal(<?=json_encode($c)?>)' class="p-2 hover:bg-slate-700 rounded-lg"><i data-lucide="edit-3" class="w-4"></i></button>
                                <button onclick="delAcc(<?=$c['id']?>, '<?=$c['username']?>', '<?=$c['domain']?>')" class="p-2 hover:bg-red-500/10 text-red-500 rounded-lg"><i data-lucide="trash-2" class="w-4"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PACKAGES -->
        <div id="view-pkg" class="view-pane">
            <div class="flex justify-between items-center mb-8">
                <h2 class="text-3xl font-bold">Service Packages</h2>
                <button onclick="openPkgModal()" class="bg-emerald-600 hover:bg-emerald-700 px-6 py-3 rounded-2xl font-bold shadow-lg shadow-emerald-600/20">Add Package</button>
            </div>
            <div class="grid grid-cols-3 gap-6">
                <?php foreach($packages as $p): ?>
                <div class="bg-slate-900 p-8 rounded-3xl border border-slate-800 relative group">
                    <h3 class="text-xl font-bold mb-4"><?=$p['name']?></h3>
                    <div class="space-y-3 text-sm text-slate-400 mb-8">
                        <div class="flex items-center gap-2"><i data-lucide="hard-drive" class="w-4"></i> <?=$p['disk_mb']?> MB Storage</div>
                        <div class="flex items-center gap-2"><i data-lucide="globe" class="w-4"></i> <?=$p['max_domains']?> Domains</div>
                        <div class="flex items-center gap-2"><i data-lucide="mail" class="w-4"></i> <?=$p['max_emails']?> Emails</div>
                    </div>
                    <div class="flex gap-2">
                        <button onclick='openPkgModal(<?=json_encode($p)?>)' class="flex-1 bg-slate-800 py-3 rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-slate-700">Edit</button>
                        <button onclick="delPkg(<?=$p['id']?>)" class="bg-red-500/10 p-3 rounded-xl text-red-500"><i data-lucide="trash-2"></i></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- SERVICES -->
        <div id="view-serv" class="view-pane">
            <h2 class="text-3xl font-bold mb-8">Service Engine</h2>
            <div class="grid grid-cols-2 gap-6">
                <?php foreach($services as $id => $name): 
                    $active = trim(cmd("service-status $id")) == 'active'; ?>
                <div class="bg-slate-900 p-6 rounded-3xl border border-slate-800 flex justify-between items-center">
                    <div class="flex items-center gap-4">
                        <div class="w-3 h-3 rounded-full <?=$active?'bg-emerald-500 shadow-[0_0_10px_#10b981]':'bg-red-500 shadow-[0_0_10px_#ef4444]'?>"></div>
                        <div><p class="font-bold text-lg"><?=$name?></p><p class="text-[10px] font-mono text-slate-500 uppercase"><?=$id?></p></div>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="servAction('<?=$id?>','restart')" title="Restart" class="p-4 bg-slate-800 rounded-2xl text-blue-400 hover:bg-slate-700 transition-all"><i data-lucide="refresh-cw"></i></button>
                        <button onclick="servAction('<?=$id?>','stop')" title="Stop" class="p-4 bg-slate-800 rounded-2xl text-red-500 hover:bg-slate-700 transition-all"><i data-lucide="power"></i></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- FTP & MAIL -->
        <div id="view-hosting" class="view-pane">
            <div class="grid grid-cols-2 gap-10">
                <div class="bg-slate-900 p-10 rounded-[2.5rem] border border-slate-800 shadow-2xl">
                    <h3 class="text-2xl font-bold mb-8 flex items-center gap-3"><i data-lucide="folder-key" class="text-emerald-500"></i> FTP Provisioning</h3>
                    <form onsubmit="handleGeneric(event, 'add_ftp')" class="space-y-4">
                        <input name="ftp_user" required placeholder="FTP Username" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800 outline-none focus:border-blue-500">
                        <input name="ftp_pass" required type="password" placeholder="FTP Password" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800 outline-none focus:border-blue-500">
                        <select name="sys_user" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800">
                            <?php foreach($clients as $c): ?><option value="<?=$c['username']?>">Root: <?=$c['username']?></option><?php endforeach; ?>
                        </select>
                        <button class="w-full bg-emerald-600 py-4 rounded-2xl font-bold mt-4 shadow-lg shadow-emerald-600/20">Add FTP User</button>
                    </form>
                </div>
                <div class="bg-slate-900 p-10 rounded-[2.5rem] border border-slate-800 shadow-2xl">
                    <h3 class="text-2xl font-bold mb-8 flex items-center gap-3"><i data-lucide="mail" class="text-blue-500"></i> New Mailbox</h3>
                    <form onsubmit="handleGeneric(event, 'add_mail')" class="space-y-4">
                        <div class="flex gap-2">
                            <input name="prefix" required placeholder="admin" class="flex-1 bg-slate-950 p-4 rounded-2xl border border-slate-800">
                            <select name="domain" class="flex-1 bg-slate-950 p-4 rounded-2xl border border-slate-800">
                                <?php foreach($clients as $c): ?><option value="<?=$c['domain']?>">@<?=$c['domain']?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <input name="mail_pass" required type="password" placeholder="Password" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800 outline-none">
                        <button class="w-full bg-blue-600 py-4 rounded-2xl font-bold mt-4 shadow-lg shadow-blue-600/20">Create Mailbox</button>
                    </form>
                </div>
            </div>
        </div>

    </main>

    <!-- ACCOUNT MODAL -->
    <div id="modal-acc" class="fixed inset-0 bg-black/80 backdrop-blur-md hidden flex items-center justify-center z-50 p-4">
        <form id="form-acc" onsubmit="handleGeneric(event, 'save_account')" class="bg-slate-900 p-10 rounded-[2.5rem] w-full max-w-lg border border-slate-800 shadow-2xl">
            <h3 id="acc-title" class="text-2xl font-bold mb-8">New Account</h3>
            <input type="hidden" name="id" id="acc-id">
            <div class="space-y-4">
                <input name="user" id="acc-user" placeholder="Username (alpha-numeric)" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800 outline-none focus:border-blue-500" required>
                <input name="dom" id="acc-dom" placeholder="Primary Domain (domain.com)" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800 outline-none focus:border-blue-500" required>
                <input name="email" id="acc-email" placeholder="Contact Email" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800 outline-none focus:border-blue-500" required>
                <input name="pass" type="password" placeholder="Password (leave blank to keep current)" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800 outline-none focus:border-blue-500">
                <select name="package_id" id="acc-pkg" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800">
                    <?php foreach($packages as $p): ?><option value="<?=$p['id']?>"><?=$p['name']?></option><?php endforeach; ?>
                </select>
                <div class="flex gap-4 pt-6">
                    <button type="button" onclick="closeModal('modal-acc')" class="flex-1 bg-slate-800 p-4 rounded-2xl font-bold hover:bg-slate-700">Cancel</button>
                    <button type="submit" class="flex-1 bg-blue-600 p-4 rounded-2xl font-bold hover:bg-blue-700 shadow-lg shadow-blue-600/20">Save Account</button>
                </div>
            </div>
        </form>
    </div>

    <!-- PACKAGE MODAL -->
    <div id="modal-pkg" class="fixed inset-0 bg-black/80 backdrop-blur-md hidden flex items-center justify-center z-50 p-4">
        <form id="form-pkg" onsubmit="handleGeneric(event, 'save_package')" class="bg-slate-900 p-10 rounded-[2.5rem] w-full max-w-md border border-slate-800 shadow-2xl">
            <h3 id="pkg-title" class="text-2xl font-bold mb-8">Plan Configuration</h3>
            <input type="hidden" name="id" id="pkg-id">
            <div class="space-y-4">
                <input name="name" id="pkg-name" placeholder="Package Name" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800 outline-none" required>
                <input name="disk" id="pkg-disk" type="number" placeholder="Disk Limit (MB)" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800" required>
                <input name="doms" id="pkg-doms" type="number" placeholder="Max Domains" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800" required>
                <input name="mails" id="pkg-mails" type="number" placeholder="Max Emails" class="w-full bg-slate-950 p-4 rounded-2xl border border-slate-800" required>
                <div class="flex gap-4 pt-6">
                    <button type="button" onclick="closeModal('modal-pkg')" class="flex-1 bg-slate-800 p-4 rounded-2xl font-bold">Cancel</button>
                    <button type="submit" class="flex-1 bg-emerald-600 p-4 rounded-2xl font-bold shadow-lg shadow-emerald-600/20">Save Plan</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function switchTab(id, btn) {
            document.querySelectorAll('.view-pane').forEach(v => v.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
            document.getElementById('view-' + id).classList.add('active');
            btn.classList.add('active');
        }

        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function openAccModal(data = null) {
            const f = document.getElementById('form-acc'); f.reset();
            if(data) {
                document.getElementById('acc-id').value = data.id;
                document.getElementById('acc-user').value = data.username;
                document.getElementById('acc-user').readOnly = true;
                document.getElementById('acc-dom').value = data.domain;
                document.getElementById('acc-email').value = data.email;
                document.getElementById('acc-pkg').value = data.package_id;
                document.getElementById('acc-title').innerText = "Edit Account";
            } else {
                document.getElementById('acc-id').value = "";
                document.getElementById('acc-user').readOnly = false;
                document.getElementById('acc-title').innerText = "Provision Account";
            }
            document.getElementById('modal-acc').classList.remove('hidden');
        }

        function openPkgModal(data = null) {
            const f = document.getElementById('form-pkg'); f.reset();
            if(data) {
                document.getElementById('pkg-id').value = data.id;
                document.getElementById('pkg-name').value = data.name;
                document.getElementById('pkg-disk').value = data.disk_mb;
                document.getElementById('pkg-doms').value = data.max_domains;
                document.getElementById('pkg-mails').value = data.max_emails;
            } else {
                document.getElementById('pkg-id').value = "";
            }
            document.getElementById('modal-pkg').classList.remove('hidden');
        }

        /**
         * GLOBAL AJAX HANDLER (502 Protected)
         */
        async function handleGeneric(e, action) {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = "Processing Node...";

            const fd = new FormData(e.target);
            fd.append('ajax_action', action);

            try {
                const response = await fetch('', { method: 'POST', body: fd });
                
                // If 502 occurs, server is restarting. Just refresh after delay.
                if (response.status === 502 || response.status === 504) {
                    btn.innerHTML = "Server Reloading...";
                    setTimeout(() => location.reload(), 2500);
                    return;
                }

                const data = await response.json();
                if(data.status === 'success') location.reload();
                else { alert(data.msg); btn.disabled = false; btn.innerHTML = originalText; }

            } catch (err) {
                // Handle JSON parse errors during service restarts
                btn.innerHTML = "Node Syncing...";
                setTimeout(() => location.reload(), 2500);
            }
        }

        async function delAcc(id, user, dom) {
            if(!confirm(`Permanent Purge: Delete ${user}?`)) return;
            const fd = new FormData();
            fd.append('ajax_action', 'delete_account');
            fd.append('id', id); fd.append('user', user); fd.append('dom', dom);
            await fetch('', { method: 'POST', body: fd });
            location.reload();
        }

        async function delPkg(id) {
            if(!confirm('Delete this plan?')) return;
            const fd = new FormData();
            fd.append('ajax_action', 'delete_package');
            fd.append('id', id);
            await fetch('', { method: 'POST', body: fd });
            location.reload();
        }

        async function servAction(service, op) {
            const fd = new FormData();
            fd.append('ajax_action', 'service_action');
            fd.append('service', service); fd.append('op', op);
            
            try {
                await fetch('', { method: 'POST', body: fd });
                setTimeout(() => location.reload(), 2000);
            } catch (e) { location.reload(); }
        }

        lucide.createIcons();
    </script>
</body>
</html>
