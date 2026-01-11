<?php
/** 
 * VIVZON CPANEL - MASTER PRODUCTION v4.5
 * Comprehensive: DB Users, Email Isolation, DNS, PHP Config, SSL, Usage Metrics
 */
require_once '../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

// --- AJAX API HANDLER ---
if (isset($_POST['ajax_action'])) {
    if (ob_get_length())
        ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        $limits = $pdo->query("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();

        /** 1. DATABASE & USER LOGIC **/
        if ($action == 'add_db') {
            $curr = $pdo->query("SELECT COUNT(*) FROM client_databases WHERE client_id = $cid")->fetchColumn();
            if ($curr >= $limits['max_databases'])
                throw new Exception("Plan database limit reached.");

            $db_name = $username . "_" . preg_replace('/[^a-z0-9_]/', '', $_POST['db_name']);
            $domain_id = !empty($_POST['domain_id']) ? (int) $_POST['domain_id'] : "NULL";

            $pdo->prepare("INSERT INTO client_databases (client_id, domain_id, db_name) VALUES (?, ?, ?)")->execute([$cid, $domain_id == "NULL" ? null : $domain_id, $db_name]);
            sendResponse($res);
            cmd("mysql-tool create-db " . escapeshellarg($db_name));
            exit;
        }

        if ($action == 'add_db_user') {
            $db_user = $username . "_" . preg_replace('/[^a-z0-9_]/', '', $_POST['db_user']);
            $pdo->prepare("INSERT INTO client_db_users (client_id, db_user) VALUES (?, ?)")->execute([$cid, $db_user]);
            sendResponse($res);
            cmd("mysql-tool create-user " . escapeshellarg($db_user) . " " . escapeshellarg($_POST['db_pass']) . " " . escapeshellarg($_POST['target_db']));
            exit;
        }

        /** 2. EMAIL LOGIC **/
        if ($action == 'add_email') {
            $curr = $pdo->query("SELECT COUNT(*) FROM mail_users WHERE domain_id IN (SELECT id FROM mail_domains WHERE domain IN (SELECT domain FROM domains WHERE client_id = $cid))")->fetchColumn();
            if ($curr >= $limits['max_emails'])
                throw new Exception("Email limit reached.");
            $did = $pdo->query("SELECT id FROM mail_domains WHERE domain = '{$_POST['domain']}'")->fetchColumn();
            if (!$did) {
                $pdo->prepare("INSERT INTO mail_domains (domain) VALUES (?)")->execute([$_POST['domain']]);
                $did = $pdo->lastInsertId();
            }
            $pdo->prepare("INSERT INTO mail_users (domain_id, email, password) VALUES (?, ?, ?)")->execute([$did, $_POST['user'] . "@" . $_POST['domain'], password_hash($_POST['pass'], PASSWORD_BCRYPT)]);
            sendResponse($res);
            exit;
        }

        /** 3. DNS, PHP, & SSL LOGIC **/
        if ($action == 'add_dns') {
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, ?, ?, ?)")->execute([$_POST['domain_id'], $_POST['type'], $_POST['host'], $_POST['value']]);
            sendResponse($res);
            cmd("dns-tool sync " . (int) $_POST['domain_id']);
            exit;
        }

        if ($action == 'update_domain_config') {
            $pdo->prepare("UPDATE domains SET php_version = ?, ssl_active = ? WHERE id = ? AND client_id = ?")->execute([$_POST['php_version'], isset($_POST['ssl']) ? 1 : 0, $_POST['domain_id'], $cid]);
            $pdo->prepare("INSERT INTO php_config (domain_id, memory_limit) VALUES (?, ?) ON DUPLICATE KEY UPDATE memory_limit=VALUES(memory_limit)")->execute([$_POST['domain_id'], $_POST['mem']]);
            sendResponse($res);
            cmd("vhost-tool sync " . (int) $_POST['domain_id']);
            cmd("vhost-tool sync " . (int) $_POST['domain_id']);
            exit;
        }

        if ($action == 'add_domain') {
            $dom = strtolower(trim($_POST['domain']));

            // Strict Domain Validation (Regex)
            if (!preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/', $dom))
                throw new Exception("Invalid Domain Name Format (e.g. example.com)");

            // Check Limits
            $curr = $pdo->query("SELECT COUNT(*) FROM domains WHERE client_id = $cid")->fetchColumn();
            if ($curr >= $limits['max_domains'])
                throw new Exception("Domain limit reached ({$limits['max_domains']})");

            // Check Uniqueness Globally (prevent takeover)
            $exists = $pdo->prepare("SELECT id FROM domains WHERE domain = ?");
            $exists->execute([$dom]);
            if ($exists->fetch())
                throw new Exception("Domain already exists on server");

            // Insert & Execute
            $pdo->prepare("INSERT INTO domains (client_id, domain, document_root) VALUES (?, ?, ?)")
                ->execute([$cid, $dom, "/var/www/clients/$username/domains/$dom/public_html"]);
            $dom_id = $pdo->lastInsertId();

            // --- Auto DNS Configuration ---
            $server_ip = $_SERVER['SERVER_ADDR'];
            $host_parts = explode('.', $_SERVER['HTTP_HOST']);
            $base_domain = implode('.', array_slice($host_parts, -2));
            $mail_host = "mail." . $base_domain;

            // 1. A Record (@ -> IP)
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', '@', ?)")->execute([$dom_id, $server_ip]);

            // 2. CNAME Record (www -> @)
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'CNAME', 'www', '@')")->execute([$dom_id]);

            // 3. MX Record (@ -> mail.server)
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'MX', '@', ?)")->execute([$dom_id, $mail_host]);

            // 4. SPF Record
            $spf = "v=spf1 mx a ip4:$server_ip -all";
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'TXT', '@', ?)")->execute([$dom_id, $spf]);

            sendResponse($res);
            cmd("shm-manage add-domain " . escapeshellarg($username) . " " . escapeshellarg($dom));
            cmd("dns-tool sync $dom_id"); // Sync immediately
            exit;
        }

        if ($action == 'delete_domain') {
            $dom_id = (int) $_POST['domain_id'];
            $d = $pdo->prepare("SELECT domain FROM domains WHERE id=? AND client_id=?");
            $d->execute([$dom_id, $cid]);
            $domain_name = $d->fetchColumn();
            if (!$domain_name)
                throw new Exception("Invalid Domain");

            // Delete Related
            $pdo->prepare("DELETE FROM dns_records WHERE domain_id=?")->execute([$dom_id]);
            $pdo->prepare("DELETE FROM php_config WHERE domain_id=?")->execute([$dom_id]);
            $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$dom_id]);

            sendResponse($res);
            cmd("shm-manage delete-domain " . escapeshellarg($username) . " " . escapeshellarg($domain_name));
            exit;
        }

        if ($action == 'delete_db') {
            $db_name = $_POST['db_name'];
            $check = $pdo->prepare("SELECT id FROM client_databases WHERE db_name = ? AND client_id = ?");
            $check->execute([$db_name, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM client_databases WHERE db_name = ?")->execute([$db_name]);
            sendResponse($res);
            cmd("mysql-tool delete-db " . escapeshellarg($db_name));
            exit;
        }

        if ($action == 'reset_db_pass') {
            $db_user = $_POST['db_user'];
            $pass = $_POST['new_pass'];

            // Check ownership
            $check = $pdo->prepare("SELECT id FROM client_db_users WHERE db_user = ? AND client_id = ?");
            $check->execute([$db_user, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            sendResponse($res);
            cmd("mysql-tool reset-pass " . escapeshellarg($db_user) . " " . escapeshellarg($pass));
            exit;
        }

        if ($action == 'delete_dns') {
            $did = (int) $_POST['id'];
            $dom_id = (int) $_POST['domain_id'];
            $check = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $check->execute([$dom_id, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM dns_records WHERE id = ? AND domain_id = ?")->execute([$did, $dom_id]);
            sendResponse($res);
            cmd("dns-tool sync " . $dom_id);
            exit;
        }

        if ($action == 'delete_email') {
            $email = $_POST['email'];
            $check = $pdo->prepare("SELECT m.id FROM mail_users m JOIN mail_domains md ON m.domain_id = md.id JOIN domains d ON md.domain = d.domain WHERE m.email = ? AND d.client_id = ?");
            $check->execute([$email, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM mail_users WHERE email = ?")->execute([$email]);
            sendResponse($res);
            exit;
        }

        if ($action == 'reset_mail_pass') {
            $email = $_POST['email'];
            $pass = $_POST['new_pass'];

            // Check ownership
            $check = $pdo->prepare("SELECT m.id FROM mail_users m JOIN mail_domains md ON m.domain_id = md.id JOIN domains d ON md.domain = d.domain WHERE m.email = ? AND d.client_id = ?");
            $check->execute([$email, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("UPDATE mail_users SET password = ? WHERE email = ?")->execute([password_hash($pass, PASSWORD_BCRYPT), $email]);
            sendResponse($res);
            exit;
        }

        /** 4. APP INSTALLER LOGIC **/
        if ($action == 'install_app') {
            $app = $_POST['app'];
            $dom_id = $_POST['domain_id'];

            // Get Domain Name
            $d = $pdo->query("SELECT domain FROM domains WHERE id=$dom_id AND client_id=$cid")->fetchColumn();
            if (!$d)
                throw new Exception("Invalid Domain");

            sendResponse($res);
            // Run in background as it takes time
            cmd("app-tool $app " . escapeshellarg($d) . " > /dev/null 2>&1 &");
            exit;
        }
    } catch (Exception $e) {
        sendResponse(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// DATA FOR DASHBOARD
$client = $pdo->query("SELECT c.*, p.name as pkg_name, p.max_emails, p.max_databases, p.max_domains, p.disk_mb FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();
try {
    $my_dbs = $pdo->query("SELECT cd.*, d.domain FROM client_databases cd LEFT JOIN domains d ON cd.domain_id = d.id WHERE cd.client_id = $cid ORDER BY d.domain DESC")->fetchAll();
} catch (PDOException $e) {
    // Fallback for pre-migration schema
    $my_dbs = $pdo->query("SELECT *, NULL as domain FROM client_databases WHERE client_id = $cid")->fetchAll();
}
$my_emails = $pdo->query("SELECT mu.* FROM mail_users mu JOIN mail_domains md ON mu.domain_id = md.id WHERE md.domain IN (SELECT domain FROM domains WHERE client_id = $cid)")->fetchAll();

// Usage Calculation
$usage_dom = count($domains);
$usage_db = count($my_dbs);
$usage_mail = count($my_emails);
$usage_disk = 0; // Disk usage calculation would go here
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vivzon Cpanel | Client Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: #f1f5f9;
        }

        /* Smooth Transitions */
        .pane {
            display: none;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .pane.active {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        /* Modern Sidebar */
        .nav-btn {
            display: flex;
            items-center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            color: #94a3b8;
            margin-bottom: 4px;
            border: 1px solid transparent;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.03);
            color: #e2e8f0;
        }

        .nav-btn.active {
            background: rgba(37, 99, 235, 0.1);
            color: #60a5fa;
            border: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: 0 0 15px rgba(37, 99, 235, 0.1);
        }

        .nav-btn.active i {
            color: #60a5fa;
            stroke-width: 2.5px;
        }

        /* Cards */
        .glass-card {
            background: rgba(30, 41, 59, 0.6);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
            border-radius: 1.5rem;
        }

        .glass-panel {
            background: rgba(30, 41, 59, 0.4);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.03);
        }
    </style>
</head>

<body class="flex h-screen overflow-hidden">

    <!-- Modern Sidebar -->
    <aside class="w-72 glass-panel h-full border-r border-slate-700/30 flex flex-col z-20 shadow-2xl shadow-black/20">
        <div class="p-8 pb-6">
            <div class="flex items-center gap-3 text-xl font-extrabold tracking-tighter text-white mb-10">
                <div
                    class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-lg shadow-blue-500/30">
                    <i data-lucide="cloud" class="w-5"></i>
                </div>
                SHM <span class="text-blue-500">PANEL</span>
            </div>

            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-widest pl-4 mb-3">Main Menu</div>
            <nav class="space-y-1">
                <button onclick="tab('dash')" id="btn-dash" class="nav-btn active w-full"><i data-lucide="layout-grid"
                        class="w-5"></i> Dashboard</button>
                <button onclick="tab('files')" id="btn-files" class="nav-btn w-full"><i data-lucide="folder-open"
                        class="w-5"></i> File Manager</button>
                <button onclick="tab('db')" id="btn-db" class="nav-btn w-full"><i data-lucide="database"
                        class="w-5"></i> Database</button>
                <button onclick="tab('mail')" id="btn-mail" class="nav-btn w-full"><i data-lucide="mail"
                        class="w-5"></i> Email Boxes</button>
                <button onclick="tab('dom')" id="btn-dom" class="nav-btn w-full"><i data-lucide="globe" class="w-5"></i>
                    SSL & DNS</button>
                <button onclick="tab('apps')" id="btn-apps" class="nav-btn w-full"><i data-lucide="box" class="w-5"></i>
                    App Installer</button>
            </nav>
        </div>

        <div class="mt-auto p-6 border-t border-slate-700/30 bg-slate-900/20">
            <div class="flex items-center gap-3">
                <div
                    class="w-10 h-10 bg-slate-800 border border-slate-700 rounded-full flex items-center justify-center text-slate-400 font-bold shadow-sm">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
                <div class="flex-1 overflow-hidden">
                    <p class="text-sm font-bold text-slate-200 truncate"><?= htmlspecialchars($username) ?></p>
                    <p class="text-[10px] uppercase font-bold text-slate-500">Client ID: #<?= $cid ?></p>
                </div>
                <a href="logout.php" class="text-slate-500 hover:text-red-500 transition"><i data-lucide="log-out"
                        class="w-5"></i></a>
            </div>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-full relative overflow-hidden bg-[#0f172a]">
        <!-- Top Bar -->
        <header
            class="h-20 px-8 flex items-center justify-between sticky top-0 z-10 glass-panel border-b border-slate-700/30 !bg-[#0f172a]/80">
            <h2 id="page-title" class="text-2xl font-bold text-white">Overview</h2>
            <div class="flex gap-4">
                <div
                    class="px-4 py-2 bg-emerald-500/10 text-emerald-400 rounded-full text-xs font-bold flex items-center gap-2 border border-emerald-500/20">
                    <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span> Systems Active
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-10 pb-24">

            <!-- DASHBOARD & RESOURCE METRICS -->
            <div id="pane-dash" class="pane active">

                <!-- Usage Stats -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
                    <!-- Disk -->
                    <div class="glass-card p-6 relative overflow-hidden group hover:bg-slate-800/50 transition">
                        <div
                            class="absolute right-0 top-0 p-6 opacity-10 group-hover:opacity-20 transition transform group-hover:scale-110 text-white">
                            <i data-lucide="hard-drive" class="w-24 h-24"></i>
                        </div>
                        <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">Disk Usage</p>
                        <h3 class="text-3xl font-extrabold text-white mb-2"><?= $usage_disk ?> <span
                                class="text-sm text-slate-500 font-medium">/ <?= $client['disk_mb'] ?> MB</span></h3>
                        <div class="w-full bg-slate-700/50 h-2 rounded-full overflow-hidden">
                            <div class="bg-blue-500 h-full rounded-full shadow-lg shadow-blue-500/50"
                                style="width: <?= ($usage_disk / $client['disk_mb']) * 100 ?>%"></div>
                        </div>
                    </div>

                    <!-- Domains -->
                    <div class="glass-card p-6 relative overflow-hidden group hover:bg-slate-800/50 transition">
                        <div
                            class="absolute right-0 top-0 p-6 opacity-10 group-hover:opacity-20 transition transform group-hover:scale-110 text-white">
                            <i data-lucide="globe" class="w-24 h-24"></i>
                        </div>
                        <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">Domains</p>
                        <h3 class="text-3xl font-extrabold text-white mb-2"><?= $usage_dom ?> <span
                                class="text-sm text-slate-500 font-medium">/ <?= $client['max_domains'] ?></span></h3>
                        <div class="w-full bg-slate-700/50 h-2 rounded-full overflow-hidden">
                            <div class="bg-emerald-500 h-full rounded-full shadow-lg shadow-emerald-500/50"
                                style="width: <?= ($usage_dom / $client['max_domains']) * 100 ?>%"></div>
                        </div>
                    </div>

                    <!-- Emails -->
                    <div class="glass-card p-6 relative overflow-hidden group hover:bg-slate-800/50 transition">
                        <div
                            class="absolute right-0 top-0 p-6 opacity-10 group-hover:opacity-20 transition transform group-hover:scale-110 text-white">
                            <i data-lucide="mail" class="w-24 h-24"></i>
                        </div>
                        <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">Email Boxes</p>
                        <h3 class="text-3xl font-extrabold text-white mb-2"><?= $usage_mail ?> <span
                                class="text-sm text-slate-500 font-medium">/ <?= $client['max_emails'] ?></span></h3>
                        <div class="w-full bg-slate-700/50 h-2 rounded-full overflow-hidden">
                            <div class="bg-purple-500 h-full rounded-full shadow-lg shadow-purple-500/50"
                                style="width: <?= ($usage_mail / $client['max_emails']) * 100 ?>%"></div>
                        </div>
                    </div>

                    <!-- DBs -->
                    <div class="glass-card p-6 relative overflow-hidden group hover:bg-slate-800/50 transition">
                        <div
                            class="absolute right-0 top-0 p-6 opacity-10 group-hover:opacity-20 transition transform group-hover:scale-110 text-white">
                            <i data-lucide="database" class="w-24 h-24"></i>
                        </div>
                        <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">Databases</p>
                        <h3 class="text-3xl font-extrabold text-white mb-2"><?= $usage_db ?> <span
                                class="text-sm text-slate-500 font-medium">/ <?= $client['max_databases'] ?></span></h3>
                        <div class="w-full bg-slate-700/50 h-2 rounded-full overflow-hidden">
                            <div class="bg-orange-500 h-full rounded-full shadow-lg shadow-orange-500/50"
                                style="width: <?= ($usage_db / $client['max_databases']) * 100 ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Server Details Card (New) -->
                <div
                    class="glass-card p-6 mb-10 flex items-center justify-between group hover:bg-slate-800/50 transition">
                    <div class="flex items-center gap-6">
                        <div class="p-4 bg-slate-800 rounded-2xl text-blue-500 ring-1 ring-white/10">
                            <i data-lucide="server" class="w-8 h-8"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-white mb-1">Hosting Environment Details</h3>
                            <?php
                            $md = str_replace('cpanel.', '', $_SERVER['SERVER_NAME']);
                            if ($md == $_SERVER['SERVER_NAME'])
                                $md = 'vivzon.cloud';
                            ?>
                            <div class="flex flex-wrap gap-6 text-sm text-slate-400 font-mono mt-1">
                                <span
                                    class="flex items-center gap-2 bg-slate-900/50 px-3 py-1 rounded-lg border border-slate-700/50"><i
                                        data-lucide="network" class="w-4"></i> IP: <?= $_SERVER['SERVER_ADDR'] ?></span>
                                <span
                                    class="flex items-center gap-2 bg-slate-900/50 px-3 py-1 rounded-lg border border-slate-700/50"><i
                                        data-lucide="globe" class="w-4"></i> NS1: ns1.<?= $md ?></span>
                                <span
                                    class="flex items-center gap-2 bg-slate-900/50 px-3 py-1 rounded-lg border border-slate-700/50"><i
                                        data-lucide="globe" class="w-4"></i> NS2: ns2.<?= $md ?></span>
                                <span
                                    class="flex items-center gap-2 bg-slate-900/50 px-3 py-1 rounded-lg border border-slate-700/50"><i
                                        data-lucide="mail" class="w-4"></i> MX: mail.<?= $md ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <h3 class="text-lg font-bold text-white mb-6">Quick Shortcuts</h3>
                <?php
                $host_parts = explode('.', $_SERVER['HTTP_HOST']);
                $base_domain = implode('.', array_slice($host_parts, -2));
                ?>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <a href="http://filemanager.<?= $base_domain ?>" target="_blank"
                        class="glass-card p-8 text-center hover:bg-slate-800/50 transition group">
                        <div
                            class="w-14 h-14 mx-auto bg-blue-500/10 text-blue-500 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition group-hover:bg-blue-600 group-hover:text-white group-hover:shadow-lg shadow-blue-600/20">
                            <i data-lucide="folder-open" class="w-7 h-7"></i>
                        </div>
                        <p class="font-bold text-slate-300 group-hover:text-white transition">File Manager</p>
                    </a>
                    <a href="http://phpmyadmin.<?= $base_domain ?>" target="_blank"
                        class="glass-card p-8 text-center hover:bg-slate-800/50 transition group">
                        <div
                            class="w-14 h-14 mx-auto bg-orange-500/10 text-orange-500 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition group-hover:bg-orange-600 group-hover:text-white group-hover:shadow-lg shadow-orange-600/20">
                            <i data-lucide="database" class="w-7 h-7"></i>
                        </div>
                        <p class="font-bold text-slate-300 group-hover:text-white transition">phpMyAdmin</p>
                    </a>
                    <a href="http://webmail.<?= $base_domain ?>" target="_blank"
                        class="glass-card p-8 text-center hover:bg-slate-800/50 transition group">
                        <div
                            class="w-14 h-14 mx-auto bg-purple-500/10 text-purple-500 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition group-hover:bg-purple-600 group-hover:text-white group-hover:shadow-lg shadow-purple-600/20">
                            <i data-lucide="mail" class="w-7 h-7"></i>
                        </div>
                        <p class="font-bold text-slate-300 group-hover:text-white transition">Webmail Interface</p>
                    </a>
                    <div onclick="tab('dom')"
                        class="cursor-pointer glass-card p-8 text-center hover:bg-slate-800/50 transition group">
                        <div
                            class="w-14 h-14 mx-auto bg-emerald-500/10 text-emerald-500 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition group-hover:bg-emerald-600 group-hover:text-white group-hover:shadow-lg shadow-emerald-600/20">
                            <i data-lucide="globe" class="w-7 h-7"></i>
                        </div>
                        <p class="font-bold text-slate-300 group-hover:text-white transition">Manage Domains</p>
                    </div>
                </div>
            </div>

            <!-- APP INSTALLER -->
            <div id="pane-apps" class="pane">
                <h2 class="text-2xl font-bold mb-8 text-white">One-Click App Installer</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- WordPress -->
                    <div
                        class="glass-card p-6 flex flex-col items-center text-center group hover:bg-slate-800/50 transition relative overflow-hidden">
                        <div
                            class="w-16 h-16 bg-blue-500/10 text-blue-500 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition group-hover:bg-blue-600 group-hover:text-white shadow-lg shadow-blue-600/20">
                            <i data-lucide="layout-template" class="w-8 h-8"></i>
                        </div>
                        <h3 class="font-bold text-lg mb-2 text-white">WordPress</h3>
                        <p class="text-sm text-slate-400 mb-6">The world's most popular CMS.</p>
                        <button onclick="openAppModal('wordpress', 'WordPress')"
                            class="mt-auto w-full py-3 rounded-xl font-bold bg-slate-800 text-slate-300 hover:bg-blue-600 hover:text-white transition border border-slate-700">Install</button>
                    </div>

                    <!-- Laravel -->
                    <div
                        class="glass-card p-6 flex flex-col items-center text-center group hover:bg-slate-800/50 transition relative overflow-hidden">
                        <div
                            class="w-16 h-16 bg-red-500/10 text-red-500 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition group-hover:bg-red-600 group-hover:text-white shadow-lg shadow-red-600/20">
                            <i data-lucide="code-2" class="w-8 h-8"></i>
                        </div>
                        <h3 class="font-bold text-lg mb-2 text-white">Laravel</h3>
                        <p class="text-sm text-slate-400 mb-6">The PHP Framework for Web Artisans.</p>
                        <button onclick="openAppModal('laravel', 'Laravel')"
                            class="mt-auto w-full py-3 rounded-xl font-bold bg-slate-800 text-slate-300 hover:bg-red-600 hover:text-white transition border border-slate-700">Install</button>
                    </div>

                    <!-- CodeIgniter -->
                    <div
                        class="glass-card p-6 flex flex-col items-center text-center group hover:bg-slate-800/50 transition relative overflow-hidden">
                        <div
                            class="w-16 h-16 bg-orange-500/10 text-orange-500 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition group-hover:bg-orange-600 group-hover:text-white shadow-lg shadow-orange-600/20">
                            <i data-lucide="flame" class="w-8 h-8"></i>
                        </div>
                        <h3 class="font-bold text-lg mb-2 text-white">CodeIgniter</h3>
                        <p class="text-sm text-slate-400 mb-6">Powerful PHP framework with a small footprint.</p>
                        <button onclick="openAppModal('codeigniter', 'CodeIgniter')"
                            class="mt-auto w-full py-3 rounded-xl font-bold bg-slate-800 text-slate-300 hover:bg-orange-600 hover:text-white transition border border-slate-700">Install</button>
                    </div>

                    <!-- React -->
                    <div
                        class="glass-card p-6 flex flex-col items-center text-center group hover:bg-slate-800/50 transition relative overflow-hidden">
                        <div
                            class="w-16 h-16 bg-cyan-500/10 text-cyan-500 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition group-hover:bg-cyan-600 group-hover:text-white shadow-lg shadow-cyan-600/20">
                            <i data-lucide="atom" class="w-8 h-8"></i>
                        </div>
                        <h3 class="font-bold text-lg mb-2 text-white">React (Vite)</h3>
                        <p class="text-sm text-slate-400 mb-6">A JavaScript library for building user interfaces.</p>
                        <button onclick="openAppModal('react', 'React')"
                            class="mt-auto w-full py-3 rounded-xl font-bold bg-slate-800 text-slate-300 hover:bg-cyan-600 hover:text-white transition border border-slate-700">Install</button>
                    </div>
                </div>
            </div>

            <!-- FILE MANAGER -->
            <div id="pane-files" class="pane">
                <div class="flex flex-col items-center justify-center h-[60vh] text-center">
                    <div class="glass-card p-12 max-w-2xl w-full relative overflow-hidden">
                        <div class="absolute top-0 right-0 p-12 opacity-5 pointer-events-none text-white">
                            <i data-lucide="folder-open" class="w-64 h-64"></i>
                        </div>
                        <div
                            class="w-20 h-20 bg-blue-500/10 text-blue-500 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-inner ring-1 ring-blue-500/20">
                            <i data-lucide="hard-drive" class="w-10 h-10"></i>
                        </div>
                        <h2 class="text-3xl font-extrabold text-white mb-4">File Manager</h2>
                        <p class="text-slate-400 mb-8 font-medium">Access your files, upload content, and manage
                            permissions using our advanced File Manager.</p>

                        <?php
                        $host_parts = explode('.', $_SERVER['HTTP_HOST']);
                        $base_domain = implode('.', array_slice($host_parts, -2));
                        ?>

                        <a href="http://filemanager.<?= $base_domain ?>" target="_blank"
                            class="inline-flex items-center gap-3 bg-blue-600 text-white px-8 py-4 rounded-xl font-bold hover:bg-blue-500 transition shadow-lg shadow-blue-600/30 group">
                            Launch File Manager
                            <i data-lucide="arrow-right" class="w-5 group-hover:translate-x-1 transition"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- DATABASE USER & TABLE MANAGEMENT -->
            <div id="pane-db" class="pane">
                <h2 class="text-2xl font-bold mb-8 text-white">MySQL® Databases</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
                    <div class="glass-card p-8 shadow-sm">
                        <h3 class="font-bold mb-4 text-white">Create New Database</h3>
                        <form onsubmit="handle(event, 'add_db')" class="flex flex-col gap-4">
                            <div class="flex gap-2">
                                <span
                                    class="bg-slate-800 p-4 rounded-xl font-bold text-slate-500 border border-slate-700"><?= $username ?>_</span>
                                <input name="db_name" required placeholder="Database Name"
                                    class="flex-1 bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-white placeholder-slate-600 transition">
                            </div>
                            <select name="domain_id"
                                class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-slate-300">
                                <option value="">-- Associate with Domain (Optional) --</option>
                                <?php foreach ($domains as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= $d['domain'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button
                                class="bg-blue-600 text-white px-6 py-4 rounded-xl font-bold hover:bg-blue-500 transition shadow-lg shadow-blue-600/20">Create
                                Database</button>
                        </form>
                    </div>
                    <div class="glass-card p-8 shadow-sm">
                        <h3 class="font-bold mb-4 text-white">Add User to Database</h3>
                        <form onsubmit="handle(event, 'add_db_user')" class="space-y-4">
                            <div
                                class="flex items-center bg-slate-900/50 border border-slate-700 rounded-xl overflow-hidden">
                                <span class="pl-4 font-bold text-slate-500"><?= $username ?>_</span>
                                <input name="db_user" required
                                    class="flex-1 bg-transparent p-4 outline-none text-white placeholder-slate-600"
                                    placeholder="username">
                            </div>
                            <input name="db_pass" type="password" required placeholder="User Password"
                                class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-white placeholder-slate-600 transition">
                            <select name="target_db"
                                class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-slate-300">
                                <?php foreach ($my_dbs as $db): ?>
                                    <option value="<?= $db['db_name'] ?>">Access to: <?= $db['db_name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button
                                class="w-full bg-slate-800 text-white p-4 rounded-xl font-bold hover:bg-slate-700 transition border border-slate-700">Create
                                User</button>
                        </form>
                    </div>

                    <!-- DB USERS LIST -->
                    <div class="md:col-span-2">
                        <h3 class="font-bold mb-4 mt-8 text-white">Database Users</h3>
                        <div class="glass-card overflow-hidden mb-8">
                            <table class="w-full text-left">
                                <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                                    <tr>
                                        <th class="p-6">User</th>
                                        <th class="p-6 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $db_users = $pdo->query("SELECT * FROM client_db_users WHERE client_id = $cid")->fetchAll();
                                    foreach ($db_users as $u): ?>
                                        <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                                            <td class="p-6 font-bold text-slate-300"><?= $u['db_user'] ?></td>
                                            <td class="p-6 text-right">
                                                <button
                                                    onclick="resetPassword('reset_db_pass', 'db_user', '<?= $u['db_user'] ?>')"
                                                    class="text-orange-400 hover:bg-orange-500/10 p-2 rounded-lg transition mr-2"><i
                                                        data-lucide="key" class="w-4 h-4"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="glass-card overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400 tracking-widest">
                            <tr>
                                <th class="p-6">Current Database Name</th>
                                <th class="p-6 text-right">Login / Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_dbs as $db): ?>
                                <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                                    <td class="p-6">
                                        <div class="font-bold text-slate-200"><?= $db['db_name'] ?></div>
                                        <?php if ($db['domain']): ?>
                                            <div class="text-xs text-blue-400 flex items-center gap-1 mt-1"><i
                                                    data-lucide="link" class="w-3"></i> <?= $db['domain'] ?></div>
                                        <?php else: ?>
                                            <div class="text-xs text-slate-500 italic mt-1">Global Database</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-6 text-right">
                                        <a href="http://phpmyadmin.<?= $base_domain ?>" target="_blank"
                                            class="text-blue-400 font-bold text-xs mr-4 uppercase hover:text-blue-300">phpMyAdmin</a>
                                        <button onclick="deleteAction('delete_db', 'db_name', '<?= $db['db_name'] ?>')"
                                            class="text-red-400 hover:bg-red-500/10 p-2 rounded-lg transition"><i
                                                data-lucide="trash-2" class="w-4"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- EMAIL MANAGEMENT -->
            <div id="pane-mail" class="pane">
                <h2 class="text-2xl font-bold mb-8 text-white">Email Mailboxes</h2>
                <form onsubmit="handle(event, 'add_email')"
                    class="glass-card p-10 grid grid-cols-1 md:grid-cols-4 gap-4 mb-10">
                    <input name="user" required placeholder="mailbox name"
                        class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-white placeholder-slate-600 transition">
                    <select name="domain"
                        class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-slate-300">
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= $d['domain'] ?>">@<?= $d['domain'] ?></option><?php endforeach; ?>
                    </select>
                    <input name="pass" type="password" required placeholder="Password"
                        class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-white placeholder-slate-600 transition">
                    <button
                        class="bg-blue-600 text-white rounded-xl font-bold shadow-lg shadow-blue-600/20 hover:bg-blue-500 transition">Create
                        Mailbox</button>
                </form>
                <div class="glass-card overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                            <tr>
                                <th class="p-6">Active Email Account</th>
                                <th class="p-6 text-right">Webmail / Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_emails as $mail): ?>
                                <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                                    <td class="p-6 font-bold text-slate-300"><?= $mail['email'] ?></td>
                                    <td class="p-6 text-right">
                                        <a href="http://webmail.<?= $base_domain ?>" target="_blank"
                                            class="text-blue-400 font-bold text-xs mr-4 uppercase tracking-tighter hover:text-blue-300">Login</a>
                                        <button onclick="resetPassword('reset_mail_pass', 'email', '<?= $mail['email'] ?>')"
                                            class="text-orange-400 hover:bg-orange-500/10 p-2 rounded-lg transition mr-2"><i
                                                data-lucide="key" class="w-4 h-4"></i></button>
                                        <button onclick="deleteAction('delete_email', 'email', '<?= $mail['email'] ?>')"
                                            class="text-red-400 hover:bg-red-500/10 p-2 rounded-lg transition"><i
                                                data-lucide="trash-2" class="w-4"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- DNS & PHP CONFIG -->
            <div id="pane-dom" class="pane">
                <div class="flex justify-between items-center mb-8">
                    <h2 class="text-2xl font-bold text-white">Domain Hosting Configuration</h2>
                    <form onsubmit="handle(event, 'add_domain')" class="flex gap-2">
                        <input name="domain" required placeholder="example.com"
                            class="bg-slate-900/50 border border-slate-700 p-3 rounded-xl text-sm outline-none shadow-sm focus:border-blue-500 text-white placeholder-slate-500">
                        <button
                            class="bg-slate-800 text-white px-6 py-3 rounded-xl font-bold text-sm shadow-xl hover:bg-slate-700 border border-slate-700 transition">+
                            Add Website</button>
                    </form>
                </div>
                <?php foreach ($domains as $d): ?>
                    <div class="glass-card p-10 mb-8 shadow-sm group">
                        <div class="flex justify-between items-center mb-10">
                            <div>
                                <h3 class="text-2xl font-black text-white"><?= $d['domain'] ?></h3>
                                <p class="text-xs text-slate-500 font-mono mt-1">Root: /home/<?= $username ?>/public_html
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <a href="http://filemanager.<?= $base_domain ?>/?domain_id=<?= $d['id'] ?>" target="_blank"
                                    class="bg-blue-500/10 text-blue-400 -4 py-2 rounded-xl text-xs font-bold hover:bg-blue-600 hover:text-white transition flex items-center gap-2 border border-blue-500/20 px-4"><i
                                        data-lucide="folder-open" class="w-4 h-4"></i> Manage Files</a>
                                <button onclick="deleteAction('delete_domain', 'domain_id', <?= $d['id'] ?>)"
                                    class="bg-red-500/10 text-red-400 px-4 py-2 rounded-xl text-xs font-bold hover:bg-red-600 hover:text-white transition border border-red-500/20">Delete</button>
                            </div>
                            <form onsubmit="handle(event, 'update_domain_config')"
                                class="flex items-center gap-4 bg-slate-900/50 p-4 rounded-3xl border border-slate-700/50">
                                <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                                <select name="php_version"
                                    class="bg-slate-800 border border-slate-700 p-2 rounded-xl text-xs font-bold text-white">
                                    <option value="8.1" <?= $d['php_version'] == '8.1' ? 'selected' : '' ?>>PHP 8.1</option>
                                    <option value="8.2" <?= $d['php_version'] == '8.2' ? 'selected' : '' ?>>PHP 8.2</option>
                                    <option value="8.3" <?= $d['php_version'] == '8.3' ? 'selected' : '' ?>>PHP 8.3</option>
                                </select>
                                <select name="mem"
                                    class="bg-slate-800 border border-slate-700 p-2 rounded-xl text-xs font-bold text-white">
                                    <option>128M</option>
                                    <option>256M</option>
                                    <option>512M</option>
                                </select>
                                <div class="flex items-center gap-2 px-2 border-l border-slate-700">
                                    <input type="checkbox" name="ssl" <?= $d['ssl_active'] ? 'checked' : '' ?>
                                        class="w-4 h-4 text-emerald-500 accent-emerald-500">
                                    <span class="text-[10px] font-bold uppercase text-emerald-400">SSL</span>
                                </div>
                                <button class="bg-blue-600 text-white p-2 rounded-lg hover:bg-blue-500 transition"><i
                                        data-lucide="save" class="w-4"></i></button>
                            </form>
                        </div>
                        <div class="border-t border-slate-700/50 pt-8">
                            <h4 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-6">DNS Zone Management
                            </h4>
                            <form onsubmit="handle(event, 'add_dns')" class="grid grid-cols-4 gap-3 mb-4">
                                <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                                <input name="host" placeholder="Host (e.g. @)"
                                    class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-sm text-white placeholder-slate-600 outline-none focus:border-blue-500 transition"
                                    required>
                                <select name="type"
                                    class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-sm font-bold text-slate-300 outline-none">
                                    <option>A</option>
                                    <option>CNAME</option>
                                    <option>MX</option>
                                    <option>TXT</option>
                                </select>
                                <input name="value" placeholder="Value (IP or Domain)"
                                    class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-sm text-white placeholder-slate-600 outline-none focus:border-blue-500 transition"
                                    required>
                                <button
                                    class="bg-slate-800 text-white rounded-xl font-bold text-xs uppercase shadow-xl hover:bg-slate-700 border border-slate-700 transition">Add
                                    Record</button>
                            </form>

                            <table class="w-full mt-6 text-left">
                                <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                                    <tr>
                                        <th class="p-3">Host</th>
                                        <th class="p-3">Type</th>
                                        <th class="p-3">Value</th>
                                        <th class="p-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700/50">
                                    <?php
                                    $recs = $pdo->prepare("SELECT * FROM dns_records WHERE domain_id = ?");
                                    $recs->execute([$d['id']]);
                                    while ($r = $recs->fetch()): ?>
                                        <tr class="text-sm hover:bg-slate-800/30 transition">
                                            <td class="p-3 font-bold text-slate-300"><?= $r['host'] ?></td>
                                            <td class="p-3"><span
                                                    class="bg-slate-800 border border-slate-700 px-2 py-1 rounded text-xs font-bold text-slate-400"><?= $r['type'] ?></span>
                                            </td>
                                            <td class="p-3 font-mono text-slate-500 text-xs"><?= $r['value'] ?></td>
                                            <td class="p-3 text-right">
                                                <button
                                                    onclick="deleteAction('delete_dns', 'id', <?= $r['id'] ?>, 'domain_id', <?= $d['id'] ?>)"
                                                    class="text-red-400 hover:text-red-500"><i data-lucide="trash-2"
                                                        class="w-4"></i></button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

    </main>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed bottom-6 right-6 z-50 flex flex-col gap-4 pointer-events-none"></div>

    <script>
        lucide.createIcons();

        // Toast Notification System
        function showToast(type, title, message) {
            const container = document.getElementById('toast-container');
            
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `pointer-events-auto w-96 glass-card p-4 rounded-xl shadow-2xl flex items-start gap-4 transform transition-all duration-500 translate-x-full opacity-0 border-l-4 ${type === 'success' ? 'border-l-emerald-500' : (type === 'error' ? 'border-l-red-500' : 'border-l-blue-500')}`;
            
            // Icon
            let iconHtml = '';
            if (type === 'success') iconHtml = `<div class="bg-emerald-500/20 text-emerald-400 p-2 rounded-lg"><i data-lucide="check-circle" class="w-5 h-5"></i></div>`;
            else if (type === 'error') iconHtml = `<div class="bg-red-500/20 text-red-400 p-2 rounded-lg"><i data-lucide="x-circle" class="w-5 h-5"></i></div>`;
            else iconHtml = `<div class="bg-blue-500/20 text-blue-400 p-2 rounded-lg"><i data-lucide="info" class="w-5 h-5"></i></div>`;

            toast.innerHTML = `
                ${iconHtml}
                <div class="flex-1">
                    <h4 class="font-bold text-white text-sm">${title}</h4>
                    <p class="text-xs text-slate-400 mt-1 leading-relaxed">${message}</p>
                </div>
                <button onclick="this.parentElement.remove()" class="text-slate-500 hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
            `;

            container.appendChild(toast);
            lucide.createIcons({ root: toast });

            // Animate in
            requestAnimationFrame(() => {
                toast.classList.remove('translate-x-full', 'opacity-0');
            });

            // Auto dismiss
            setTimeout(() => {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 500);
            }, 5000);
        }

        // Map tab IDs to Header Titles
        const TITLES = {
            'dash': 'Overview',
            'files': 'File Manager',
            'db': 'Database Management',
            'mail': 'Email Accounts',
            'dom': 'DNS & Security',
            'apps': 'App Installer'
        };

        function tab(id) {
            document.querySelectorAll('.pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));

            const pane = document.getElementById('pane-' + id);
            const btn = document.getElementById('btn-' + id);

            if (pane) pane.classList.add('active');
            if (btn) btn.classList.add('active');

            const title = document.getElementById('page-title');
            if (title) title.innerText = TITLES[id] || 'Overview';

            if (location.hash !== '#' + id) {
                location.hash = id;
            }
        }

        window.addEventListener('load', () => {
            const hash = location.hash.substring(1);
            if (hash && document.getElementById('pane-' + hash)) {
                tab(hash);
            } else {
                tab('dash');
            }
        });

        window.addEventListener('hashchange', () => {
            const hash = location.hash.substring(1);
            if (hash) tab(hash);
        });

        async function handle(e, action) {
            e.preventDefault();
            const btn = e.target.querySelector('button');
            const oldHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="animate-pulse">Processing...</span>`;

            const fd = new FormData(e.target);
            fd.append('ajax_action', action);

            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                if (res.status === 'success') {
                    showToast('success', 'Operation Successful', 'The requested action was completed successfully.');
                    btn.innerHTML = "Success!";
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('error', 'Operation Failed', res.msg || 'An unknown error occurred.');
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
            } catch (err) {
                showToast('error', 'Network Error', 'Failed to communicate with the server.');
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        }

        async function deleteAction(action, ...args) {
            if (!confirm("Permanent Action: Are you sure?")) return;
            const fd = new FormData(); 
            fd.append('ajax_action', action);
            for (let i = 0; i < args.length; i += 2) fd.append(args[i], args[i + 1]);
            
            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                if (res.status === 'success') {
                    showToast('success', 'Deleted', 'Item deleted successfully.');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('error', 'Delete Failed', res.msg || 'Could not delete item.');
                }
            } catch (e) {
                showToast('error', 'Error', 'System error during deletion.');
            }
        }

        async function resetPassword(action, keyName, keyValue) {
            const newPass = prompt("Enter new password for " + keyValue + ":");
            if (!newPass) return;

            const fd = new FormData();
            fd.append('ajax_action', action);
            fd.append(keyName, keyValue);
            fd.append('new_pass', newPass);

            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                if (res.status === 'success') {
                    showToast('success', 'Password Updated', 'The password has been changed successfully.');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('error', 'Update Failed', res.msg);
                }
            } catch (e) {
                showToast('error', 'Error', 'System error during password reset.');
            }
        }

        async function openAppModal(app, appName) {
            const domainId = prompt(`Install ${appName} to which domain? (Enter Domain ID)\n\nAvailable IDs:\n<?php foreach ($domains as $d) echo $d['id'] . ": " . $d['domain'] . "\n"; ?>`);
            if (!domainId) return;

            if (!confirm(`WARNING: This will OVERWRITE existing content in the public_html folder for this domain.\n\nAre you sure you want to install ${appName}?`)) return;

            handleAppInstall(app, domainId);
        }

        async function handleAppInstall(app, domainId) {
            const fd = new FormData();
            fd.append('ajax_action', 'install_app');
            fd.append('app', app);
            fd.append('domain_id', domainId);

            showToast('info', 'Installation Started', 'The installation process is running in the background. Please wait 30-60 seconds.');

            try {
                await fetch('', { method: 'POST', body: fd });
                showToast('success', 'Installation Complete', 'The application has been installed.');
                setTimeout(() => location.reload(), 1500);
            } catch (e) {
                showToast('warning', 'Check Status', 'Installation request sent, but check logs if it doesn\'t appear.');
            }
        }
    </script>
</body>

</html>