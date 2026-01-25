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
$clientData = $pdo->query("SELECT c.*, p.name as pkg_name, p.max_emails, p.max_databases, p.max_domains, p.disk_mb FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();

// 2. Fetch Usage Stats
try {
    $usage_db = $pdo->query("SELECT COUNT(*) FROM client_databases WHERE client_id = $cid")->fetchColumn();
} catch (Exception $e) {
    $usage_db = 0;
}

$usage_dom = count($domains);
$usage_mail = $pdo->query("SELECT COUNT(*) FROM mail_users WHERE domain_id IN (SELECT id FROM mail_domains WHERE domain IN (SELECT domain FROM domains WHERE client_id = $cid))")->fetchColumn();

// 3. Fetch Traffic Data (Last 7 Days)
// Aggregate traffic across ALL user domains
$traffic_data = $pdo->query("
    SELECT date, SUM(bytes_sent) as total_bytes, SUM(hits) as total_hits 
    FROM domain_traffic 
    WHERE domain_id IN (SELECT id FROM domains WHERE client_id = $cid) 
    AND date >= DATE(NOW() - INTERVAL 7 DAY)
    GROUP BY date 
    ORDER BY date ASC
")->fetchAll(PDO::FETCH_ASSOC);

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
    <div class="flex flex-col md:flex-row justify-between items-end gap-4 border-b border-white/5 pb-6">
        <div>
            <h2 class="text-3xl font-bold text-white font-heading tracking-tight mb-2">Dashboard</h2>
            <p class="text-slate-400">Welcome back, <span
                    class="text-blue-400 font-bold"><?= htmlspecialchars($username) ?></span>. System is running
                smoothly.</p>
        </div>
        <div class="flex gap-3">
            <a href="files.php"
                class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-bold shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
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
                <span class="text-xs font-bold bg-white/5 px-2 py-1 rounded text-slate-400"><?= $usage_dom ?> /
                    <?= $clientData['max_domains'] ?></span>
            </div>
            <h3 class="text-3xl font-bold text-white mb-1 relative z-10"><?= $usage_dom ?></h3>
            <p class="text-sm text-slate-400 font-medium relative z-10">Active Domains</p>
            <div class="w-full bg-slate-800 h-1 mt-4 rounded-full overflow-hidden">
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
                <span class="text-xs font-bold bg-white/5 px-2 py-1 rounded text-slate-400"><?= $usage_db ?> /
                    <?= $clientData['max_databases'] ?></span>
            </div>
            <h3 class="text-3xl font-bold text-white mb-1 relative z-10"><?= $usage_db ?></h3>
            <p class="text-sm text-slate-400 font-medium relative z-10">MySQL Databases</p>
            <div class="w-full bg-slate-800 h-1 mt-4 rounded-full overflow-hidden">
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
                <span class="text-xs font-bold bg-white/5 px-2 py-1 rounded text-slate-400"><?= $usage_mail ?> /
                    <?= $clientData['max_emails'] ?></span>
            </div>
            <h3 class="text-3xl font-bold text-white mb-1 relative z-10"><?= $usage_mail ?></h3>
            <p class="text-sm text-slate-400 font-medium relative z-10">Email Accounts</p>
            <div class="w-full bg-slate-800 h-1 mt-4 rounded-full overflow-hidden">
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
            <h3 class="text-3xl font-bold text-white mb-1 relative z-10"><?= $clientData['disk_mb'] ?> MB</h3>
            <p class="text-sm text-slate-400 font-medium relative z-10">Total Storage</p>
            <div class="w-full bg-slate-800 h-1 mt-4 rounded-full overflow-hidden">
                <!-- Placeholder usage calc -->
                <div class="bg-orange-500 h-full rounded-full" style="width: 45%"></div>
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
                        <h3 class="text-lg font-bold text-white">Network Traffic</h3>
                        <p class="text-xs text-slate-400">Hits & Bandwidth (Last 7 Days)</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="flex h-2 w-2 rounded-full bg-blue-500"></span>
                        <span class="text-xs text-slate-400">Flow</span>
                    </div>
                </div>
                <!-- Chart Container -->
                <div id="trafficChart" class="w-full h-[300px]"></div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="emails.php"
                    class="p-4 bg-slate-800/40 hover:bg-slate-700/50 border border-white/5 hover:border-blue-500/30 rounded-2xl flex flex-col items-center gap-3 transition group text-center">
                    <div class="p-3 bg-slate-900 rounded-full group-hover:bg-blue-600 transition duration-300">
                        <i data-lucide="mail-plus" class="w-5 h-5 text-blue-400 group-hover:text-white transition"></i>
                    </div>
                    <span class="font-bold text-sm text-slate-300 group-hover:text-white">New Email</span>
                </a>
                <a href="databases.php"
                    class="p-4 bg-slate-800/40 hover:bg-slate-700/50 border border-white/5 hover:border-purple-500/30 rounded-2xl flex flex-col items-center gap-3 transition group text-center">
                    <div class="p-3 bg-slate-900 rounded-full group-hover:bg-purple-600 transition duration-300">
                        <i data-lucide="database" class="w-5 h-5 text-purple-400 group-hover:text-white transition"></i>
                    </div>
                    <span class="font-bold text-sm text-slate-300 group-hover:text-white">Add DB</span>
                </a>
                <a href="domains.php"
                    class="p-4 bg-slate-800/40 hover:bg-slate-700/50 border border-white/5 hover:border-emerald-500/30 rounded-2xl flex flex-col items-center gap-3 transition group text-center">
                    <div class="p-3 bg-slate-900 rounded-full group-hover:bg-emerald-600 transition duration-300">
                        <i data-lucide="globe" class="w-5 h-5 text-emerald-400 group-hover:text-white transition"></i>
                    </div>
                    <span class="font-bold text-sm text-slate-300 group-hover:text-white">Add Domain</span>
                </a>
                <a href="tools.php"
                    class="p-4 bg-slate-800/40 hover:bg-slate-700/50 border border-white/5 hover:border-orange-500/30 rounded-2xl flex flex-col items-center gap-3 transition group text-center">
                    <div class="p-3 bg-slate-900 rounded-full group-hover:bg-orange-600 transition duration-300">
                        <i data-lucide="wrench" class="w-5 h-5 text-orange-400 group-hover:text-white transition"></i>
                    </div>
                    <span class="font-bold text-sm text-slate-300 group-hover:text-white">Tools</span>
                </a>
            </div>
        </div>

        <!-- Right Column: Logs & Info -->
        <div class="space-y-8">
            <!-- Server Info -->
            <div class="glass-card p-6">
                <h3 class="text-lg font-bold text-white mb-4">Server Info</h3>
                <div class="space-y-3">
                    <div class="flex justify-between text-sm py-2 border-b border-white/5">
                        <span class="text-slate-400">IP Address</span>
                        <span class="font-mono text-white"><?= $_SERVER['SERVER_ADDR'] ?></span>
                    </div>
                    <div class="flex justify-between text-sm py-2 border-b border-white/5">
                        <span class="text-slate-400">PHP Version</span>
                        <span class="font-mono text-blue-400">8.2 (Default)</span>
                    </div>
                    <div class="flex justify-between text-sm py-2 border-b border-white/5">
                        <span class="text-slate-400">Web Server</span>
                        <span class="font-mono text-emerald-400">Nginx</span>
                    </div>
                    <div class="mt-4 pt-2">
                        <div class="flex justify-between text-xs mb-1">
                            <span class="text-slate-500">System Load</span>
                            <span class="text-green-400">Healthy</span>
                        </div>
                        <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                            <div class="h-full bg-green-500 w-1/4 animate-pulse"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error Logs -->
            <div class="glass-card overflow-hidden flex flex-col h-[300px]">
                <div class="p-4 border-b border-white/5 flex justify-between items-center bg-slate-900/50">
                    <h3 class="font-bold text-white text-sm flex items-center gap-2">
                        <i data-lucide="terminal" class="w-4 h-4 text-slate-400"></i> Error Stream
                    </h3>
                    <button onclick="fetchLogs()" class="text-slate-500 hover:text-white transition"><i
                            data-lucide="refresh-cw" class="w-3 h-3"></i></button>
                </div>
                <div class="flex-1 overflow-y-auto p-4 bg-[#050912] font-mono text-[11px] text-slate-400 leading-relaxed scrollbar-hide"
                    id="log-container">
                    <div class="flex items-center justify-center h-full text-slate-600 animate-pulse">Connecting to
                        stream...</div>
                </div>
                <div class="p-2 bg-slate-900/50 border-t border-white/5 flex justify-between items-center px-4">
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
                        class="text-[10px] text-slate-500 hover:text-red-400 transition font-bold uppercase">Clear</button>
                </div>
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
            const res = await fetch('', { method: 'POST', body: fd });
            const text = await res.text();
            const cont = document.getElementById('log-container');

            if (text.trim() === "") {
                cont.innerHTML = '<div class="flex items-center justify-center h-full text-slate-600">No recent errors.</div>';
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
            await fetch('', { method: 'POST', body: fd });
            fetchLogs();
        } catch (e) { console.error(e); }
    }

    // Init
    fetchLogs();
    setInterval(fetchLogs, 5000);
</script>

<?php include 'layout/footer.php'; ?>