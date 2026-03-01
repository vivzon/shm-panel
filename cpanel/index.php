<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

// AJax Actions
if (isset($_POST['ajax_action'])) {
    verify_csrf();
    if ($_POST['ajax_action'] == 'clear_logs') {
        cmd("clear-client-logs " . escapeshellarg($username));
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['ajax_action'] == 'get_logs') {
        $logs = cmd("get-client-logs " . escapeshellarg($username));
        echo htmlspecialchars($logs);
        exit;
    }
}

// 1. Fetch Client Data
$stmt = $pdo->prepare("SELECT c.*, p.name as pkg_name, p.max_emails, p.max_databases, p.max_domains, p.disk_mb FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = ?");
$stmt->execute([$cid]);
$clientData = $stmt->fetch();
$stmt2 = $pdo->prepare("SELECT * FROM domains WHERE client_id = ?");
$stmt2->execute([$cid]);
$domains = $stmt2->fetchAll();

// 2. Fetch Usage Stats
try {
    $stmt_db = $pdo->prepare("SELECT COUNT(*) FROM client_databases WHERE client_id = ?");
    $stmt_db->execute([$cid]);
    $usage_db = $stmt_db->fetchColumn();
} catch (Exception $e) {
    $usage_db = 0;
}

$usage_dom = count($domains);
$stmt_mail = $pdo->prepare("SELECT COUNT(*) FROM mail_users WHERE domain_id IN (SELECT id FROM mail_domains WHERE domain IN (SELECT domain FROM domains WHERE client_id = ?))");
$stmt_mail->execute([$cid]);
$usage_mail = $stmt_mail->fetchColumn();

// Calculate Disk Usage
$used_bytes = 0;
if (function_exists('cmd')) {
    $used_bytes = (int) cmd("get-client-usage " . escapeshellarg($username));
}
$used_mb = round($used_bytes / 1024 / 1024, 2);
$disk_percent = ($clientData['disk_mb'] > 0) ? ($used_mb / $clientData['disk_mb']) * 100 : 0;
if ($disk_percent > 100)
    $disk_percent = 100;


// 3. Fetch Traffic Data (Last 7 Days)
// Aggregate traffic across ALL user domains
$stmt_traffic = $pdo->prepare("
    SELECT date, SUM(bytes_sent) as total_bytes, SUM(hits) as total_hits 
    FROM domain_traffic 
    WHERE domain_id IN (SELECT id FROM domains WHERE client_id = ?) 
    AND date >= DATE(NOW() - INTERVAL 7 DAY)
    GROUP BY date 
    ORDER BY date ASC
");
$stmt_traffic->execute([$cid]);
$traffic_data = $stmt_traffic->fetchAll(PDO::FETCH_ASSOC);

// Format for JS
$dates = [];
$hits = [];
$bytes = [];

// Fill missing dates with 0
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $found = false;
    foreach ($traffic_data as $row) {
        if ($row['date'] == $d) {
            $dates[] = date('M d', strtotime($d));
            $hits[] = (int) $row['total_hits'];
            $bytes[] = round($row['total_bytes'] / 1024 / 1024, 2); // MB
            $found = true;
            break;
        }
    }
    if (!$found) {
        $dates[] = date('M d', strtotime($d));
        $hits[] = 0;
        $bytes[] = 0;
    }
}

include 'layout/header.php';
?>

<!-- ApexCharts for Water Flow Graph -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<div class="space-y-8">

    <!-- Welcome Section -->
    <div class="flex flex-col md:flex-row justify-between items-end gap-4 border-b border-slate-300 pb-6">
        <div>
            <h2 class="text-3xl font-bold text-slate-900 font-heading tracking-tight mb-2">Dashboard</h2>
            <p class="text-slate-700">Welcome back, <span
                    class="text-blue-400 font-bold"><?= htmlspecialchars($username) ?></span>. System is running
                smoothly.</p>
        </div>
        <div class="flex gap-3">
            <a href="files.php"
                class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-slate-900 rounded-xl font-bold shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                <i data-lucide="folder-up" class="w-4 h-4"></i> Upload Files
            </a>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Domains -->
        <div class="glass-card p-6 relative overflow-hidden group hover:-translate-y-1 transition duration-300">
            <div
                class="absolute -right-4 -top-4 w-24 h-24 bg-blue-500/10 rounded-full blur-2xl group-hover:bg-blue-500/20 transition">
            </div>
            <div class="flex justify-between items-start mb-4 relative z-10">
                <div class="p-3 bg-blue-500/10 text-blue-400 rounded-xl"><i data-lucide="globe" class="w-6 h-6"></i>
                </div>
                <span class="text-xs font-bold bg-white/5 px-2 py-1 rounded text-slate-700"><?= $usage_dom ?> /
                    <?= $clientData['max_domains'] ?></span>
            </div>
            <h3 class="text-3xl font-bold text-slate-900 mb-1 relative z-10"><?= $usage_dom ?></h3>
            <p class="text-sm text-slate-700 font-medium relative z-10">Active Domains</p>
            <div class="w-full bg-slate-50 h-1 mt-4 rounded-full overflow-hidden">
                <div class="bg-blue-500 h-full rounded-full"
                    style="width: <?= ($usage_dom / max(1, $clientData['max_domains'])) * 100 ?>%"></div>
            </div>
        </div>

        <!-- Databases -->
        <div class="glass-card p-6 relative overflow-hidden group hover:-translate-y-1 transition duration-300">
            <div
                class="absolute -right-4 -top-4 w-24 h-24 bg-purple-500/10 rounded-full blur-2xl group-hover:bg-purple-500/20 transition">
            </div>
            <div class="flex justify-between items-start mb-4 relative z-10">
                <div class="p-3 bg-purple-500/10 text-purple-400 rounded-xl"><i data-lucide="database"
                        class="w-6 h-6"></i></div>
                <span class="text-xs font-bold bg-white/5 px-2 py-1 rounded text-slate-700"><?= $usage_db ?> /
                    <?= $clientData['max_databases'] ?></span>
            </div>
            <h3 class="text-3xl font-bold text-slate-900 mb-1 relative z-10"><?= $usage_db ?></h3>
            <p class="text-sm text-slate-700 font-medium relative z-10">MySQL Databases</p>
            <div class="w-full bg-slate-50 h-1 mt-4 rounded-full overflow-hidden">
                <div class="bg-purple-500 h-full rounded-full"
                    style="width: <?= ($usage_db / max(1, $clientData['max_databases'])) * 100 ?>%"></div>
            </div>
        </div>

        <!-- Emails -->
        <div class="glass-card p-6 relative overflow-hidden group hover:-translate-y-1 transition duration-300">
            <div
                class="absolute -right-4 -top-4 w-24 h-24 bg-emerald-500/10 rounded-full blur-2xl group-hover:bg-emerald-500/20 transition">
            </div>
            <div class="flex justify-between items-start mb-4 relative z-10">
                <div class="p-3 bg-emerald-500/10 text-emerald-400 rounded-xl"><i data-lucide="mail"
                        class="w-6 h-6"></i></div>
                <span class="text-xs font-bold bg-white/5 px-2 py-1 rounded text-slate-700"><?= $usage_mail ?> /
                    <?= $clientData['max_emails'] ?></span>
            </div>
            <h3 class="text-3xl font-bold text-slate-900 mb-1 relative z-10"><?= $usage_mail ?></h3>
            <p class="text-sm text-slate-700 font-medium relative z-10">Email Accounts</p>
            <div class="w-full bg-slate-50 h-1 mt-4 rounded-full overflow-hidden">
                <div class="bg-emerald-500 h-full rounded-full"
                    style="width: <?= ($usage_mail / max(1, $clientData['max_emails'])) * 100 ?>%"></div>
            </div>
        </div>

        <!-- Storage -->
        <div class="glass-card p-6 relative overflow-hidden group hover:-translate-y-1 transition duration-300">
            <div
                class="absolute -right-4 -top-4 w-24 h-24 bg-orange-500/10 rounded-full blur-2xl group-hover:bg-orange-500/20 transition">
            </div>
            <div class="flex justify-between items-start mb-4 relative z-10">
                <div class="p-3 bg-orange-500/10 text-orange-400 rounded-xl"><i data-lucide="hard-drive"
                        class="w-6 h-6"></i></div>
                <span
                    class="text-xs font-bold bg-orange-500/10 text-orange-400 border border-orange-500/20 px-2 py-1 rounded"><?= htmlspecialchars($clientData['pkg_name']) ?></span>
            </div>
            <h3 class="text-3xl font-bold text-slate-900 mb-1 relative z-10"><?= $used_mb ?> MB</h3>
            <p class="text-sm text-slate-700 font-medium relative z-10">of <?= $clientData['disk_mb'] ?> MB Used</p>
            <div class="w-full bg-slate-50 h-1 mt-4 rounded-full overflow-hidden">
                <div class="bg-orange-500 h-full rounded-full" style="width: <?= $disk_percent ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Left Column: Traffic Graph -->
        <div class="lg:col-span-2 space-y-8">
            <div class="glass-card p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Network Traffic</h3>
                        <p class="text-xs text-slate-700">Hits & Bandwidth (Last 7 Days)</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="flex h-2 w-2 rounded-full bg-blue-500"></span>
                        <span class="text-xs text-slate-700">Flow</span>
                    </div>
                </div>
                <!-- Chart Container -->
                <div id="trafficChart" class="w-full h-[300px]"></div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="emails.php"
                    class="p-4 bg-slate-50/40 hover:bg-slate-200/50 border border-slate-300 hover:border-blue-500/30 rounded-2xl flex flex-col items-center gap-3 transition group text-center">
                    <div class="p-3 bg-white rounded-full group-hover:bg-blue-600 transition duration-300">
                        <i data-lucide="mail-plus"
                            class="w-5 h-5 text-blue-400 group-hover:text-slate-900 transition"></i>
                    </div>
                    <span class="font-bold text-sm text-slate-700 group-hover:text-slate-900">New Email</span>
                </a>
                <a href="databases.php"
                    class="p-4 bg-slate-50/40 hover:bg-slate-200/50 border border-slate-300 hover:border-purple-500/30 rounded-2xl flex flex-col items-center gap-3 transition group text-center">
                    <div class="p-3 bg-white rounded-full group-hover:bg-purple-600 transition duration-300">
                        <i data-lucide="database"
                            class="w-5 h-5 text-purple-400 group-hover:text-slate-900 transition"></i>
                    </div>
                    <span class="font-bold text-sm text-slate-700 group-hover:text-slate-900">Add DB</span>
                </a>
                <a href="domains.php"
                    class="p-4 bg-slate-50/40 hover:bg-slate-200/50 border border-slate-300 hover:border-emerald-500/30 rounded-2xl flex flex-col items-center gap-3 transition group text-center">
                    <div class="p-3 bg-white rounded-full group-hover:bg-emerald-600 transition duration-300">
                        <i data-lucide="globe"
                            class="w-5 h-5 text-emerald-400 group-hover:text-slate-900 transition"></i>
                    </div>
                    <span class="font-bold text-sm text-slate-700 group-hover:text-slate-900">Add Domain</span>
                </a>
                <a href="tools.php"
                    class="p-4 bg-slate-50/40 hover:bg-slate-200/50 border border-slate-300 hover:border-orange-500/30 rounded-2xl flex flex-col items-center gap-3 transition group text-center">
                    <div class="p-3 bg-white rounded-full group-hover:bg-orange-600 transition duration-300">
                        <i data-lucide="wrench"
                            class="w-5 h-5 text-orange-400 group-hover:text-slate-900 transition"></i>
                    </div>
                    <span class="font-bold text-sm text-slate-700 group-hover:text-slate-900">Tools</span>
                </a>
            </div>
        </div>

        <!-- Right Column: Logs & Info -->
        <div class="space-y-8">
            <!-- Server Info -->
            <div class="glass-card p-6">
                <h3 class="text-lg font-bold text-slate-900 mb-4">Server Info</h3>
                <div class="space-y-3">
                    <div class="flex justify-between text-sm py-2 border-b border-slate-300">
                        <span class="text-slate-700">IP Address</span>
                        <span class="font-mono text-slate-900"><?= $_SERVER['SERVER_ADDR'] ?></span>
                    </div>
                    <div class="flex justify-between text-sm py-2 border-b border-slate-300">
                        <span class="text-slate-700">PHP Version</span>
                        <span class="font-mono text-blue-400">8.2 (Default)</span>
                    </div>
                    <div class="flex justify-between text-sm py-2 border-b border-slate-300">
                        <span class="text-slate-700">Web Server</span>
                        <span class="font-mono text-emerald-400">Nginx</span>
                    </div>
                    <div class="mt-4 pt-2">
                        <div class="flex justify-between text-xs mb-1">
                            <span class="text-slate-700">System Load</span>
                            <span class="text-green-400">Healthy</span>
                        </div>
                        <div class="h-1.5 bg-slate-50 rounded-full overflow-hidden">
                            <div class="h-full bg-green-500 w-1/4 animate-pulse"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error Logs -->
            <div class="glass-card overflow-hidden flex flex-col h-[300px]">
                <div class="p-4 border-b border-slate-300 flex justify-between items-center bg-slate-50">
                    <h3 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                        <i data-lucide="terminal" class="w-4 h-4 text-slate-700"></i> Error Stream
                    </h3>
                    <button onclick="fetchLogs()" class="text-slate-700 hover:text-slate-900 transition"><i
                            data-lucide="refresh-cw" class="w-3 h-3"></i></button>
                </div>
                <div class="flex-1 overflow-y-auto p-4 bg-slate-50 font-mono text-[11px] text-slate-300 leading-relaxed scrollbar-hide"
                    id="log-container">
                    <div class="flex items-center justify-center h-full text-slate-600 animate-pulse">Connecting to
                        stream...</div>
                </div>
                <div class="p-2 bg-slate-50 border-t border-slate-300 flex justify-between items-center px-4">
                    <span
                        class="flex items-center gap-2 text-[10px] text-emerald-400 font-bold uppercase tracking-wider">
                        <span class="relative flex h-2 w-2">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        Live
                    </span>
                    <button onclick="clearLogs()"
                        class="px-3 py-1 bg-red-500/10 text-red-600 border border-red-500/20 rounded hover:bg-red-500 hover:text-white transition font-bold leading-none">
                        Clear
                    </button>

                </div>

            </div>

        </div>

    </div>

    <script>
        // 1. Water Flow Graph (ApexCharts)
        const options = {
            series: [{
                name: 'Web Hits',
                data: <?= json_encode($hits) ?>
            }],
            chart: {
                type: 'area',
                height: 300,
                toolbar: { show: false },
                fontFamily: 'Plus Jakarta Sans, sans-serif',
                background: 'transparent'
            },
            colors: ['#3b82f6'],
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.7,
                    opacityTo: 0.1, // Water fade effect
                    stops: [0, 90, 100]
                }
            },
            dataLabels: { enabled: false },
            stroke: {
                curve: 'smooth',
                width: 3
            },
            xaxis: {
                categories: <?= json_encode($dates) ?>,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { style: { colors: '#64748b' } }
            },
            yaxis: {
                labels: { style: { colors: '#64748b' } }
            },
            grid: {
                borderColor: 'rgba(255, 255, 255, 0.05)',
                strokeDashArray: 4,
            },
            theme: { mode: 'dark' },
            tooltip: {
                theme: 'dark',
                x: { show: true },
            }
        };

        const chart = new ApexCharts(document.querySelector("#trafficChart"), options);
        chart.render();

        // 2. Log Viewer Logic
        async function fetchLogs() {
            try {
                const fd = new FormData();
                fd.append('ajax_action', 'get_logs');
                fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                const res = await fetch('', { method: 'POST', body: fd });
                const text = await res.text();
                const cont = document.getElementById('log-container');

                if (text.trim() === "") {
                    cont.innerHTML = '<div class="flex items-center justify-center h-full text-slate-700">No recent errors.</div>';
                } else {
                    cont.innerHTML = `<pre class="whitespace-pre-wrap">${text}</pre>`;
                    cont.scrollTop = cont.scrollHeight;
                }
            } catch (e) { console.error(e); }
        }

        async function clearLogs() {
            if (!confirm("Clear logs?")) return;
            try {
                const fd = new FormData();
                fd.append('ajax_action', 'clear_logs');
                fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                await fetch('', { method: 'POST', body: fd });
                fetchLogs();
            } catch (e) { console.error(e); }
        }

        // Init
        fetchLogs();
        setInterval(fetchLogs, 5000);
    </script>

    <?php include 'layout/footer.php'; ?>

