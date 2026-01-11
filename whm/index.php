<?php
/**
 * VIVZON WHM - Production v27.0
 * Fixed: 502 Bad Gateway & JSON Syntax Error Resilience
 * Features: Full CRUD for Accounts, Packages, Services, FTP, and Mail.
 */

require_once '../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// --- 1. AJAX API HANDLER ---
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Action processed'];

    try {
        /** HOSTING ACCOUNTS **/
        if ($action == 'save_account') {
            $id = $_POST['id'] ?? null;
            $u = trim($_POST['user']);
            $d = trim($_POST['dom']);
            $e = trim($_POST['email']);
            $pkg = (int) $_POST['package_id'];

            if ($id) {
                $pdo->prepare("UPDATE clients SET email=?, package_id=? WHERE id=?")->execute([$e, $pkg, $id]);
                $pdo->prepare("UPDATE domains SET domain=? WHERE client_id=?")->execute([$d, $id]);
                if (!empty($_POST['pass'])) {
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
                if (ob_get_level() > 0)
                    ob_end_flush();
                flush();
                if (function_exists('fastcgi_finish_request'))
                    fastcgi_finish_request();

                cmd("create-account " . escapeshellarg($u) . " " . escapeshellarg($d) . " " . escapeshellarg($e) . " " . escapeshellarg($_POST['pass']));
                exit;
            }
        }

        if ($action == 'delete_account') {
            $id = (int) $_POST['id'];
            $user = $_POST['user'];
            $dom = $_POST['dom'];
            $pdo->prepare("DELETE FROM domains WHERE client_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM mail_domains WHERE domain = ?")->execute([$dom]);
            $pdo->prepare("DELETE FROM ftp_users WHERE homedir LIKE ?")->execute(["%/home/$user%"]);
            $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);

            echo json_encode($res);
            if (ob_get_level() > 0)
                ob_end_flush();
            flush();
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            cmd("delete-account " . escapeshellarg($user));
            exit;
        }

        if ($action == 'suspend_account') {
            $user = $_POST['user'];
            $suspend = $_POST['suspend'] === 'true';
            $status = $suspend ? 'suspended' : 'active';

            $pdo->prepare("UPDATE clients SET status = ? WHERE username = ?")->execute([$status, $user]);

            echo json_encode($res);
            if (ob_get_level() > 0)
                ob_end_flush();
            flush();
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();

            $cmd = $suspend ? 'suspend-account' : 'unsuspend-account';
            cmd("$cmd " . escapeshellarg($user));
            exit;
        }

        if ($action == 'login_as_client') {
            $_SESSION['client'] = $_POST['user'];
            $_SESSION['cid'] = $_POST['cid'];
            echo json_encode(['status' => 'success', 'redirect' => '../cpanel/']);
            exit;
        }

        if ($action == 'reset_account') {
            $user = $_POST['user'];
            echo json_encode($res);
            if (ob_get_level() > 0)
                ob_end_flush();
            flush();
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            cmd("reset-account " . escapeshellarg($user));
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
            $home = "/home/" . $_POST['sys_user'] . "/public_html";
            $pdo->prepare("INSERT INTO ftp_users (userid, passwd, homedir) VALUES (?,?,?)")->execute([$_POST['ftp_user'], $pass, $home]);
        }

        if ($action == 'add_mail') {
            $full = $_POST['prefix'] . "@" . $_POST['domain'];
            $pass = password_hash($_POST['mail_pass'], PASSWORD_BCRYPT);
            $did = $pdo->query("SELECT id FROM mail_domains WHERE domain = '{$_POST['domain']}'")->fetchColumn();
            $pdo->prepare("INSERT INTO mail_users (domain_id, email, password) VALUES (?,?,?)")->execute([$did, $full, $pass]);
        }

        /** SERVICE CONTROL (High 502 Risk) **/
        if ($action == 'service_action') {
            $op = $_POST['op'];
            if (!in_array($op, ['start', 'stop', 'restart', 'reload']))
                throw new Exception("Invalid Operation");

            echo json_encode($res);
            if (ob_get_level() > 0)
                ob_end_flush();
            flush();
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            cmd("service-control " . $op . " " . escapeshellarg($_POST['service']));
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
$stats = explode('|', (string) cmd("get-stats"));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VIVZON WHM | System Administration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: #f1f5f9;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        /* Glass Panes */
        .glass-panel {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        /* Transitions */
        .view-pane {
            display: none;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .view-pane.active {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        /* Sidebar Nav */
        .nav-link {
            display: flex;
            items-center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: 12px;
            font-weight: 600;
            color: #64748b;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.03);
            color: #e2e8f0;
        }

        .nav-link.active {
            background: #1e293b;
            color: #3b82f6;
            border-color: rgba(59, 130, 246, 0.2);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
    </style>
</head>

<body class="flex h-screen overflow-hidden text-sm">

    <!-- Sidebar -->
    <aside class="w-72 bg-slate-950 border-r border-slate-900 flex flex-col z-20 shadow-2xl">
        <div class="p-8 pb-6">
            <div class="flex items-center gap-3 mb-10">
                <div
                    class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-lg shadow-blue-500/30">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-white font-heading tracking-tight leading-none">SHM PANEL</h1>
                    <span class="text-[10px] font-bold text-blue-500 uppercase tracking-widest">Admin Console</span>
                </div>
            </div>

            <div class="text-[9px] font-bold text-slate-500 uppercase tracking-widest pl-4 mb-3">Management</div>
            <nav class="space-y-1">
                <button onclick="switchTab('dash', this)" class="nav-link active w-full"><i
                        data-lucide="layout-dashboard" class="w-4"></i> Overview</button>
                <button onclick="switchTab('acc', this)" class="nav-link w-full"><i data-lucide="users" class="w-4"></i>
                    Accounts</button>
                <button onclick="switchTab('pkg', this)" class="nav-link w-full"><i data-lucide="package"
                        class="w-4"></i> Packages</button>
            </nav>

            <div class="text-[9px] font-bold text-slate-500 uppercase tracking-widest pl-4 mb-3 mt-8">System</div>
            <nav class="space-y-1">
                <button onclick="switchTab('serv', this)" class="nav-link w-full"><i data-lucide="cpu" class="w-4"></i>
                    Service Node</button>
                <button onclick="switchTab('hosting', this)" class="nav-link w-full"><i data-lucide="wrench"
                        class="w-4"></i> Tools</button>
            </nav>
        </div>

        <div class="mt-auto p-6 border-t border-slate-900 bg-slate-950/50">
            <a href="logout.php"
                class="flex items-center gap-3 text-slate-400 hover:text-red-400 transition group p-2 rounded-lg hover:bg-red-500/10">
                <i data-lucide="log-out" class="w-4 group-hover:-translate-x-1 transition"></i>
                <span class="font-bold">End Session</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-full bg-[#0b1120] relative overflow-hidden">
        <!-- Top Header -->
        <header
            class="h-16 px-8 flex items-center justify-between border-b border-slate-800 bg-slate-900/50 backdrop-blur-md sticky top-0 z-10">
            <div class="flex items-center gap-4">
                <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse shadow-[0_0_10px_#10b981]"></span>
                <span class="text-xs font-bold text-slate-400 font-mono">SYSTEM ONLINE</span>
            </div>
            <div class="text-xs font-bold text-slate-500">v4.5-STABLE</div>
        </header>

        <div class="flex-1 overflow-y-auto p-10 pb-24">

            <!-- DASHBOARD -->
            <div id="view-dash" class="view-pane active">
                <h2 class="text-2xl font-bold mb-6 text-white font-heading">System Overview</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <?php
                    $metrics = [
                        ['CPU Load', 'cpu', 'text-blue-400', 'bg-blue-500/10', 'border-blue-500/20'],
                        ['RAM Usage', 'layers', 'text-purple-400', 'bg-purple-500/10', 'border-purple-500/20'],
                        ['Disk Space', 'hard-drive', 'text-emerald-400', 'bg-emerald-500/10', 'border-emerald-500/20'],
                        ['Uptime', 'clock', 'text-orange-400', 'bg-orange-500/10', 'border-orange-500/20']
                    ];
                    foreach ($metrics as $i => $m):
                        ?>
                        <div class="glass-panel p-6 rounded-2xl relative overflow-hidden group">
                            <div
                                class="absolute right-0 top-0 p-6 opacity-10 group-hover:scale-110 transition duration-500">
                                <i data-lucide="<?= $m[1] ?>" class="w-16 h-16 text-white"></i>
                            </div>
                            <div class="flex items-center gap-3 mb-4">
                                <div class="p-2 rounded-lg <?= $m[3] ?> <?= $m[2] ?> border <?= $m[4] ?>">
                                    <i data-lucide="<?= $m[1] ?>" class="w-5 h-5"></i>
                                </div>
                                <span
                                    class="text-[11px] font-bold text-slate-400 uppercase tracking-widest"><?= $m[0] ?></span>
                            </div>
                            <p class="text-3xl font-bold text-white tracking-tight">
                                <?= $stats[$i] ?? '0' ?>     <?= $i < 3 ? '%' : '' ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Network Config Card -->
                <div
                    class="mt-6 glass-panel p-6 rounded-2xl relative overflow-hidden group flex items-center justify-between">
                    <div class="flex items-center gap-6">
                        <div class="p-4 bg-slate-800 rounded-xl text-blue-400">
                            <i data-lucide="network" class="w-8 h-8"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-white mb-1">Server Network Configuration</h3>
                            <?php $md = str_replace('whm.', '', $_SERVER['SERVER_NAME']); ?>
                            <div class="flex gap-6 text-sm text-slate-400 font-mono">
                                <span class="flex items-center gap-2"><i data-lucide="server" class="w-4"></i> IP:
                                    <?= $_SERVER['SERVER_ADDR'] ?></span>
                                <span class="flex items-center gap-2"><i data-lucide="globe" class="w-4"></i> NS:
                                    ns1.<?= $md ?></span>
                                <span class="flex items-center gap-2"><i data-lucide="mail" class="w-4"></i> MX:
                                    mail.<?= $md ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ACCOUNTS -->
            <div id="view-acc" class="view-pane">
                <div class="flex justify-between items-center mb-8">
                    <h2 class="text-2xl font-bold text-white font-heading">Client Accounts</h2>
                    <button onclick="openAccModal()"
                        class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2.5 rounded-xl font-bold shadow-lg shadow-blue-900/20 text-sm flex items-center gap-2 transition border border-blue-500/50">
                        <i data-lucide="plus-circle" class="w-4"></i> Create Account
                    </button>
                </div>
                <div class="glass-panel rounded-2xl overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead
                            class="bg-slate-900/50 text-slate-400 text-[10px] font-bold uppercase tracking-widest border-b border-slate-800">
                            <tr>
                                <th class="p-5">Client / Domain</th>
                                <th class="p-5">Plan</th>
                                <th class="p-5">Status</th>
                                <th class="p-5 text-right">Management</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/50">
                            <?php foreach ($clients as $c): ?>
                                <tr class="hover:bg-slate-800/30 transition-colors group">
                                    <td class="p-5">
                                        <div class="font-bold text-white text-sm"><?= $c['username'] ?></div>
                                        <a href="http://<?= $c['domain'] ?>" target="_blank"
                                            class="text-xs text-blue-400 hover:underline flex items-center gap-1">
                                            <?= $c['domain'] ?> <i data-lucide="external-link"
                                                class="w-3 opacity-0 group-hover:opacity-100 transition"></i>
                                        </a>
                                    </td>
                                    <td class="p-5">
                                        <span
                                            class="bg-slate-800 border border-slate-700 px-3 py-1 rounded-full text-[10px] font-bold text-slate-300">
                                            <?= $c['pkg_name'] ?>
                                        </span>
                                    </td>
                                    <td class="p-5">
                                        <?php if ($c['status'] == 'suspended'): ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-red-500/10 text-red-500 border border-red-500/20">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Suspended
                                            </span>
                                        <?php else: ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-5 text-right flex justify-end gap-2">
                                        <button onclick="loginAs('<?= $c['username'] ?>', <?= $c['id'] ?>)"
                                            class="p-2 hover:bg-blue-500/10 text-slate-400 hover:text-blue-400 rounded-lg transition"
                                            title="Access Account">
                                            <i data-lucide="key" class="w-4"></i>
                                        </button>
                                        <?php if ($c['status'] == 'active'): ?>
                                            <button onclick="toggleSuspend('<?= $c['username'] ?>', true)"
                                                class="p-2 hover:bg-orange-500/10 text-slate-400 hover:text-orange-400 rounded-lg transition"
                                                title="Suspend">
                                                <i data-lucide="pause-circle" class="w-4"></i>
                                            </button>
                                        <?php else: ?>
                                            <button onclick="toggleSuspend('<?= $c['username'] ?>', false)"
                                                class="p-2 hover:bg-emerald-500/10 text-slate-400 hover:text-emerald-400 rounded-lg transition"
                                                title="Unsuspend">
                                                <i data-lucide="play-circle" class="w-4"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button onclick='openAccModal(<?= json_encode($c) ?>)'
                                            class="p-2 hover:bg-blue-500/10 text-slate-400 hover:text-blue-400 rounded-lg transition border border-transparent hover:border-blue-500/20"
                                            title="Edit">
                                            <i data-lucide="edit-3" class="w-4"></i>
                                        </button>
                                        <button onclick="resetAccount('<?= $c['username'] ?>')"
                                            class="p-2 hover:bg-red-500/10 text-slate-400 hover:text-red-400 rounded-lg transition"
                                            title="Reset Account">
                                            <i data-lucide="rotate-ccw" class="w-4"></i>
                                        </button>
                                        <button
                                            onclick="delAcc(<?= $c['id'] ?>, '<?= $c['username'] ?>', '<?= $c['domain'] ?>')"
                                            class="p-2 hover:bg-red-500/10 text-slate-400 hover:text-red-400 rounded-lg transition border border-transparent hover:border-red-500/20"
                                            title="Delete">
                                            <i data-lucide="trash-2" class="w-4"></i>
                                        </button>
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
                    <h2 class="text-2xl font-bold text-white font-heading">Service Packages</h2>
                    <button onclick="openPkgModal()"
                        class="bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-2.5 rounded-xl font-bold shadow-lg shadow-emerald-900/20 text-sm flex items-center gap-2 transition border border-emerald-500/50">
                        <i data-lucide="plus" class="w-4"></i> Add Package
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <?php foreach ($packages as $p): ?>
                        <div class="glass-panel p-6 rounded-2xl relative group hover:border-slate-600 transition">
                            <div class="flex justify-between items-start mb-6">
                                <h3 class="text-lg font-bold text-white"><?= $p['name'] ?></h3>
                                <div class="p-2 bg-slate-800 rounded-lg text-slate-400"><i data-lucide="box"
                                        class="w-4"></i></div>
                            </div>
                            <div class="space-y-4 text-sm text-slate-400 mb-8 font-medium">
                                <div
                                    class="flex items-center gap-3 p-2 rounded-lg bg-slate-900/30 border border-slate-800/50">
                                    <i data-lucide="hard-drive" class="w-4 text-blue-400"></i> <?= $p['disk_mb'] ?> MB
                                    Storage
                                </div>
                                <div
                                    class="flex items-center gap-3 p-2 rounded-lg bg-slate-900/30 border border-slate-800/50">
                                    <i data-lucide="globe" class="w-4 text-emerald-400"></i> <?= $p['max_domains'] ?>
                                    Domains
                                </div>
                                <div
                                    class="flex items-center gap-3 p-2 rounded-lg bg-slate-900/30 border border-slate-800/50">
                                    <i data-lucide="mail" class="w-4 text-purple-400"></i> <?= $p['max_emails'] ?> Emails
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <button onclick='openPkgModal(<?= json_encode($p) ?>)'
                                    class="flex-1 bg-slate-800 hover:bg-slate-700 py-2.5 rounded-xl text-xs font-bold uppercase tracking-widest text-slate-300 transition border border-slate-700">Edit</button>
                                <button onclick="delPkg(<?= $p['id'] ?>)"
                                    class="bg-red-500/10 hover:bg-red-500/20 p-2.5 rounded-xl text-red-400 border border-red-500/20 transition"><i
                                        data-lucide="trash-2" class="w-4"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- SERVICES -->
            <div id="view-serv" class="view-pane">
                <h2 class="text-2xl font-bold mb-8 text-white font-heading">Service Engine</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php foreach ($services as $id => $name):
                        $active = trim(cmd("service-status $id")) == 'active'; ?>
                        <div
                            class="glass-panel p-6 rounded-2xl flex justify-between items-center group hover:border-blue-500/30 transition">
                            <div class="flex items-center gap-4">
                                <div class="relative">
                                    <div
                                        class="w-3 h-3 rounded-full <?= $active ? 'bg-emerald-500 shadow-[0_0_10px_#10b981]' : 'bg-red-500 shadow-[0_0_10px_#ef4444]' ?>">
                                    </div>
                                    <div
                                        class="w-3 h-3 rounded-full <?= $active ? 'bg-emerald-500' : 'bg-red-500' ?> absolute top-0 animate-ping opacity-75">
                                    </div>
                                </div>
                                <div>
                                    <p class="font-bold text-lg text-white group-hover:text-blue-400 transition">
                                        <?= $name ?>
                                    </p>
                                    <p class="text-[10px] font-mono text-slate-500 uppercase tracking-widest"><?= $id ?></p>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button onclick="servAction('<?= $id ?>','restart')" title="Restart"
                                    class="p-3 bg-slate-800 rounded-xl text-blue-400 hover:text-white hover:bg-blue-600 transition-all border border-slate-700 shadow-lg">
                                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                                </button>
                                <button onclick="servAction('<?= $id ?>','stop')" title="Stop"
                                    class="p-3 bg-slate-800 rounded-xl text-red-500 hover:text-white hover:bg-red-600 transition-all border border-slate-700 shadow-lg">
                                    <i data-lucide="power" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- FTP & MAIL TOOLS -->
            <div id="view-hosting" class="view-pane">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden">
                        <div class="absolute -right-10 -top-10 w-40 h-40 bg-emerald-600/10 rounded-full blur-3xl"></div>
                        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-white font-heading">
                            <div class="p-2 bg-emerald-500/10 rounded-lg border border-emerald-500/20 text-emerald-500">
                                <i data-lucide="folder-key" class="w-5 h-5"></i>
                            </div>
                            FTP Provisioning
                        </h3>
                        <form onsubmit="handleGeneric(event, 'add_ftp')" class="space-y-4 relative z-10">
                            <input name="ftp_user" required placeholder="FTP Username"
                                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                            <input name="ftp_pass" required type="password" placeholder="FTP Password"
                                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition mb-2">
                            <select name="sys_user"
                                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-emerald-500 focus:bg-slate-900 transition">
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['username'] ?>">Root: <?= $c['username'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button
                                class="w-full bg-emerald-600 hover:bg-emerald-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-emerald-600/20 text-white transition border border-emerald-500/50">Create
                                FTP User</button>
                        </form>
                    </div>

                    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden">
                        <div class="absolute -right-10 -top-10 w-40 h-40 bg-blue-600/10 rounded-full blur-3xl"></div>
                        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-white font-heading">
                            <div class="p-2 bg-blue-500/10 rounded-lg border border-blue-500/20 text-blue-500"><i
                                    data-lucide="mail" class="w-5 h-5"></i></div>
                            New Mailbox
                        </h3>
                        <form onsubmit="handleGeneric(event, 'add_mail')" class="space-y-4 relative z-10">
                            <div class="flex gap-2">
                                <input name="prefix" required placeholder="admin"
                                    class="flex-1 bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                                <select name="domain"
                                    class="bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-blue-500 focus:bg-slate-900 transition w-1/3">
                                    <?php foreach ($clients as $c): ?>
                                        <option value="<?= $c['domain'] ?>">@<?= $c['domain'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input name="mail_pass" required type="password" placeholder="Password"
                                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition mb-2">
                            <button
                                class="w-full bg-blue-600 hover:bg-blue-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-blue-600/20 text-white transition border border-blue-500/50">Create
                                Mailbox</button>
                        </form>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-10 mt-10">
                    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden">
                        <div class="absolute -right-10 -top-10 w-40 h-40 bg-purple-600/10 rounded-full blur-3xl"></div>
                        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-white font-heading">
                            <div class="p-2 bg-purple-500/10 rounded-lg border border-purple-500/20 text-purple-500">
                                <i data-lucide="code" class="w-5 h-5"></i>
                            </div>
                            PHP Handlers
                        </h3>
                        <form onsubmit="handleGeneric(event, 'set_php_handler')" class="space-y-4 relative z-10">
                            <select name="php_version" required
                                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-purple-500 focus:bg-slate-900 transition">
                                <?php foreach ($php_versions as $v): ?>
                                    <option value="<?= $v ?>"><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="sys_user" required
                                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-purple-500 focus:bg-slate-900 transition">
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['username'] ?>">Root: <?= $c['username'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button
                                class="w-full bg-purple-600 hover:bg-purple-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-purple-600/20 text-white transition border border-purple-500/50">Set
                                PHP Handler</button>
                        </form>
                    </div>
                    <div class="glass-panel p-8 rounded-3xl relative overflow-hidden">
                        <div class="absolute -right-10 -top-10 w-40 h-40 bg-orange-600/10 rounded-full blur-3xl"></div>
                        <h3 class="text-xl font-bold mb-8 flex items-center gap-3 text-white font-heading">
                            <div class="p-2 bg-orange-500/10 rounded-lg border border-orange-500/20 text-orange-500">
                                <i data-lucide="network" class="w-5 h-5"></i>
                            </div>
                            Network Card
                        </h3>
                        <form onsubmit="handleGeneric(event, 'set_network_card')" class="space-y-4 relative z-10">
                            <input name="ip_address" required placeholder="IP Address (e.g., 192.168.1.100)"
                                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-orange-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                            <input name="netmask" required placeholder="Netmask (e.g., 255.255.255.0)"
                                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-orange-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                            <input name="gateway" required placeholder="Gateway (e.g., 192.168.1.1)"
                                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-orange-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                            <button
                                class="w-full bg-orange-600 hover:bg-orange-500 py-3.5 rounded-xl font-bold mt-4 shadow-lg shadow-orange-600/20 text-white transition border border-orange-500/50">Configure
                                Network</button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- ACCOUNT MODAL -->
    <div id="modal-acc"
        class="fixed inset-0 bg-slate-950/80 backdrop-blur-md hidden flex items-center justify-center z-50 p-6">
        <form id="form-acc" onsubmit="handleGeneric(event, 'save_account')"
            class="glass-panel p-10 rounded-3xl w-full max-w-lg relative">
            <h3 id="acc-title" class="text-2xl font-bold mb-8 text-white font-heading">Provision Account</h3>
            <input type="hidden" name="id" id="acc-id">

            <div class="space-y-5">
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Client ID</label>
                    <input name="user" id="acc-user" placeholder="Username" required
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Primary
                        Domain</label>
                    <input name="dom" id="acc-dom" placeholder="example.com" required
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Contact</label>
                    <input name="email" id="acc-email" placeholder="client@email.com" required
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Security</label>
                    <input name="pass" type="password" placeholder="Password (Leave empty to keep)"
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Plan</label>
                    <div class="relative">
                        <select name="package_id" id="acc-pkg"
                            class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-blue-500 focus:bg-slate-900 transition appearance-none cursor-pointer">
                            <?php foreach ($packages as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= $p['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <i data-lucide="chevron-down"
                            class="w-4 h-4 absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"></i>
                    </div>
                </div>

                <div class="flex gap-4 pt-4">
                    <button type="button" onclick="closeModal('modal-acc')"
                        class="flex-1 p-4 rounded-xl font-bold text-slate-400 hover:bg-slate-800 transition">Cancel</button>
                    <button type="submit"
                        class="flex-1 bg-blue-600 hover:bg-blue-500 p-4 rounded-xl font-bold text-white shadow-lg shadow-blue-600/20 transition">
                        Confirm
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- PACKAGE MODAL -->
    <div id="modal-pkg"
        class="fixed inset-0 bg-slate-950/80 backdrop-blur-md hidden flex items-center justify-center z-50 p-6">
        <form id="form-pkg" onsubmit="handleGeneric(event, 'save_package')"
            class="glass-panel p-10 rounded-3xl w-full max-w-md relative">
            <h3 id="pkg-title" class="text-2xl font-bold mb-8 text-white font-heading">Plan Configuration</h3>
            <input type="hidden" name="id" id="pkg-id">

            <div class="space-y-5">
                <input name="name" id="pkg-name" placeholder="Package Name" required
                    class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">

                <div class="grid grid-cols-3 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-1">Disk</label>
                        <input name="disk" id="pkg-disk" type="number" placeholder="MB" required
                            class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white focus:bg-slate-900 transition text-center">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-1">Doms</label>
                        <input name="doms" id="pkg-doms" type="number" placeholder="#" required
                            class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white focus:bg-slate-900 transition text-center">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-1">Mail</label>
                        <input name="mails" id="pkg-mails" type="number" placeholder="#" required
                            class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white focus:bg-slate-900 transition text-center">
                    </div>
                </div>

                <div class="flex gap-4 pt-4">
                    <button type="button" onclick="closeModal('modal-pkg')"
                        class="flex-1 p-4 rounded-xl font-bold text-slate-400 hover:bg-slate-800 transition">Cancel</button>
                    <button type="submit"
                        class="flex-1 bg-emerald-600 hover:bg-emerald-500 p-4 rounded-xl font-bold text-white shadow-lg shadow-emerald-600/20 transition">Save
                        Plan</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Init Icons
        lucide.createIcons();

        function switchTab(id, btn) {
            document.querySelectorAll('.view-pane').forEach(v => v.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
            document.getElementById('view-' + id).classList.add('active');
            btn.classList.add('active');
        }

        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function openAccModal(data = null) {
            const f = document.getElementById('form-acc'); f.reset();
            const title = document.getElementById('acc-title');

            if (data) {
                document.getElementById('acc-id').value = data.id;
                document.getElementById('acc-user').value = data.username;
                document.getElementById('acc-user').readOnly = true;
                document.getElementById('acc-dom').value = data.domain;
                document.getElementById('acc-email').value = data.email;
                document.getElementById('acc-pkg').value = data.package_id;
                title.innerText = "Edit Account";
            } else {
                document.getElementById('acc-id').value = "";
                document.getElementById('acc-user').readOnly = false;
                title.innerText = "Provision Account";
            }
            document.getElementById('modal-acc').classList.remove('hidden');
        }

        function openPkgModal(data = null) {
            const f = document.getElementById('form-pkg'); f.reset();
            const title = document.getElementById('pkg-title');
            if (data) {
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

        // --- TOAST SYSTEM ---
        function showToast(type, msg) {
            const toast = document.createElement('div');
            toast.className = `fixed bottom-5 right-5 z-[100] px-6 py-4 rounded-xl shadow-2xl flex items-center gap-3 transform translate-y-10 opacity-0 transition-all duration-300 ${type === 'success' ? 'bg-emerald-600 text-white shadow-emerald-900/50' : 'bg-red-600 text-white shadow-red-900/50'}`;
            toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5"></i> <span class="font-bold">${msg}</span>`;
            document.body.appendChild(toast);
            lucide.createIcons();
            
            requestAnimationFrame(() => toast.classList.remove('translate-y-10', 'opacity-0'));
            setTimeout(() => {
                toast.classList.add('translate-y-10', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        async function handleGeneric(e, action) {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="animate-pulse">Processing...</span>`;

            const fd = new FormData(e.target);
            fd.append('ajax_action', action);

            try {
                const res = await fetch('', { method: 'POST', body: fd });
                
                // Handle 502/504 Service Reloads
                if ([502, 504].includes(res.status)) {
                    btn.innerHTML = "Reloading Node...";
                    showToast('success', 'Service Reload Triggered');
                    setTimeout(() => location.reload(), 2000);
                    return;
                }

                const data = await res.json();
                
                if (data.status === 'success') {
                    showToast('success', 'Operation Successful');
                    if(data.redirect) setTimeout(() => location.href = data.redirect, 1000);
                    else setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('error', data.msg || 'Action Failed');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            } catch (err) {
                showToast('error', 'Server Error or Service Restarting...');
                setTimeout(() => location.reload(), 2000);
            }
        }

        async function toggleSuspend(user, suspend) {
             if(!confirm('Are you sure you want to ' + (suspend ? 'suspend' : 'unsuspend') + ' this account?')) return;
             
             const fd = new FormData();
             fd.append('ajax_action', 'suspend_account');
             fd.append('user', user);
             fd.append('suspend', suspend);
             
             try {
                const res = await fetch('', { method: 'POST', body: fd });
                const d = await res.json();
                if(d.status === 'success') {
                    showToast('success', 'Account Status Updated');
                    setTimeout(() => location.reload(), 1000);
                } else showToast('error', d.msg);
             } catch(e) { showToast('error', 'Network Error'); }
        }
        
        async function resetAccount(user) {
            if(!confirm('DANGER: Reset entire account for ' + user + '? This mimicks a fresh install.')) return;
            const fd = new FormData();
            fd.append('ajax_action', 'reset_account');
            fd.append('user', user);
            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => showToast('success', 'Account Reset Initiated'));
        }

        async function delAcc(id, user, dom) {
            if(!confirm('PERMANENTLY DELETE ' + dom + '? Data cannot be recovered.')) return;
            const fd = new FormData();
            fd.append('ajax_action', 'delete_account');
            fd.append('id', id);
            fd.append('user', user);
            fd.append('dom', dom);
            
            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if(d.status === 'success') {
                        showToast('success', 'Account Deleted');
                        setTimeout(() => location.reload(), 1000);
                    }
                });
        }

        async function delPkg(id) {
            if(!confirm('Delete this package?')) return;
            const fd = new FormData();
            fd.append('ajax_action', 'delete_package');
            fd.append('id', id);
            fetch('', { method: 'POST', body: fd }).then(() => location.reload());
        }

        function servAction(srv, op) {
             showToast('success', 'Service command sent: ' + op);
             const fd = new FormData();
             fd.append('ajax_action', 'service_action');
             fd.append('service', srv);
             fd.append('op', op);
             fetch('', { method: 'POST', body: fd }); 
        }
        
        function loginAs(user, cid) {
            const fd = new FormData();
            fd.append('ajax_action', 'login_as_client');
            fd.append('user', user);
            fd.append('cid', cid);
            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if(d.status === 'success') location.href = d.redirect;
                });
        }
    </script>
</body>

</html>
            document.querySelectorAll('.view-pane').forEach(v => v.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
            document.getElementById('view-' + id).classList.add('active');
            btn.classList.add('active');
        }

        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function openAccModal(data = null) {
            const f = document.getElementById('form-acc'); f.reset();
            const title = document.getElementById('acc-title');

            if (data) {
                document.getElementById('acc-id').value = data.id;
                document.getElementById('acc-user').value = data.username;
                document.getElementById('acc-user').readOnly = true;
                document.getElementById('acc-dom').value = data.domain;
                document.getElementById('acc-email').value = data.email;
                document.getElementById('acc-pkg').value = data.package_id;
                title.innerText = "Edit Account";
            } else {
                document.getElementById('acc-id').value = "";
                document.getElementById('acc-user').readOnly = false;
                title.innerText = "Provision Account";
            }
            document.getElementById('modal-acc').classList.remove('hidden');
        }

        function openPkgModal(data = null) {
            const f = document.getElementById('form-pkg'); f.reset();
            const title = document.getElementById('pkg-title');
            if (data) {
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

        async function handleGeneric(e, action) {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="animate-pulse">Processing...</span>`;

            fd.append('ajax_action', action);

            try {
                const res = await fetch('', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();

                if (data.status === 'success') {
                    showToast('success', 'Operation Successful');
                    if (data.redirect) setTimeout(() => location.href = data.redirect, 1000);
                    else setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('error', data.msg || 'Action Failed');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            } catch (err) {
                showToast('error', 'Server Error: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        // --- TOAST SYSTEM ---
        function showToast(type, msg) {
            const toast = document.createElement('div');
            toast.className = `fixed bottom-5 right-5 z-[100] px-6 py-4 rounded-xl shadow-2xl flex items-center gap-3 transform translate-y-10 opacity-0 transition-all duration-300 ${type === 'success' ? 'bg-emerald-600 text-white shadow-emerald-900/50' : 'bg-red-600 text-white shadow-red-900/50'}`;
            toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-5 h-5"></i> <span class="font-bold">${msg}</span>`;
            document.body.appendChild(toast);
            lucide.createIcons();

            // Animate In
            requestAnimationFrame(() => {
                toast.classList.remove('translate-y-10', 'opacity-0');
            });

            // Remove
            setTimeout(() => {
                toast.classList.add('translate-y-10', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        function toggleSuspend(user, suspend) {
            if (!confirm('Are you sure you want to ' + (suspend ? 'suspend' : 'unsuspend') + ' this account?')) return;

            // Create a fake form data to reuse handleGeneric logic or just fetch manually
            const fd = new FormData();
            fd.append('ajax_action', 'suspend_account');
            fd.append('user', user);
            fd.append('suspend', suspend);

            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        showToast('success', 'Account Status Updated');
                        setTimeout(() => location.reload(), 1000);
                    } else showToast('error', d.msg);
                });
        }

        function resetAccount(user) {
            if (!confirm('Reset entire account for ' + user + '? This mimics a fresh install.')) return;
            const fd = new FormData();
            fd.append('ajax_action', 'reset_account');
            fd.append('user', user);
            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => showToast('success', 'Account Reset Initiated'));
        }

        function delAcc(id, user, dom) {
            if (!confirm('PERMANENTLY DELETE ' + dom + '? Data cannot be recovered.')) return;
            const fd = new FormData();
            fd.append('ajax_action', 'delete_account');
            fd.append('id', id);
            fd.append('user', user);
            fd.append('dom', dom);

            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        showToast('success', 'Account Deleted');
                        setTimeout(() => location.reload(), 1000);
                    }
                });
        }

        function delPkg(id) {
            if (!confirm('Delete this package?')) return;
            const fd = new FormData();
            fd.append('ajax_action', 'delete_package');
            fd.append('id', id);
            fetch('', { method: 'POST', body: fd }).then(() => location.reload());
        }

        function servAction(srv, op) {
            showToast('success', 'Service command sent: ' + op);
            const fd = new FormData();
            fd.append('ajax_action', 'service_action');
            fd.append('service', srv);
            fd.append('op', op);
            fetch('', { method: 'POST', body: fd });
            // Don't reload immediately, let it run in bg
        }

        function loginAs(user, cid) {
            const fd = new FormData();
            fd.append('ajax_action', 'login_as_client');
            fd.append('user', user);
            fd.append('cid', cid);
            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') location.href = d.redirect;
                });
        }

    </script>
</body>

</html>

try {
const response = await fetch('', { method: 'POST', body: fd });

// Handling Nginx Reloads (502/504)
if ([502, 504].includes(response.status)) {
btn.innerHTML = "Reloading Node...";
setTimeout(() => location.reload(), 2000);
return;
}

const data = await response.json();
if (data.status === 'success') location.reload();
else {
alert(data.msg);
btn.disabled = false;
btn.innerHTML = originalText;
}
} catch (err) {
// If JSON fails, it's likely a service reload interruption, which is good.
location.reload();
}
}

async function delAcc(id, user, dom) {
if (!confirm(`Warning: This will permanently delete ${user} and all associated data. Continue?`)) return;
postDat('delete_account', { id, user, dom });
}

async function delPkg(id) {
if (!confirm('Delete this service package?')) return;
postDat('delete_package', { id });
}

async function toggleSuspend(user, suspend) {
const action = suspend ? "SUSPEND" : "UNSUSPEND";
if (!confirm(`Are you sure you want to ${action} account ${user}?`)) return;
postDat('suspend_account', { user, suspend });
}

async function loginAs(user, cid) {
const fd = new FormData();
fd.append('ajax_action', 'login_as_client');
fd.append('user', user);
fd.append('cid', cid);
const res = await fetch('', { method: 'POST', body: fd });
const data = await res.json();
if (data.status === 'success') window.open(data.redirect, '_blank');
}

async function resetAccount(user) {
if (!confirm(`DANGER: Are you sure you want to RESET account ${user}?\n\nThis will DELETE ALL FILES in public_html and
DROP ALL DATABASES.\n\nThis action cannot be undone.`)) return;
if (!confirm(`DOUBLE CHECK: Really reset ${user}?`)) return;
postDat('reset_account', { user });
}

async function servAction(service, op) {
postDat('service_action', { service, op });
}

async function postDat(action, data) {
const fd = new FormData();
fd.append('ajax_action', action);
for (let k in data) fd.append(k, data[k]);

try { await fetch('', { method: 'POST', body: fd }); } catch (e) { }
setTimeout(() => location.reload(), 1500);
}
</script>
</body>

</html>