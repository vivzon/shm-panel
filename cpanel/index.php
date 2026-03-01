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

<div style="display: flex; flex-direction: column; gap: 2rem;">

    <!-- Welcome Section -->
    <div
        style="display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; border-bottom: 1px solid var(--slate-300); padding-bottom: 1.5rem; flex-wrap: wrap;">
        <div>
            <h2
                style="font-size: 1.875rem; font-weight: 700; color: var(--slate-900); font-family: 'Outfit', sans-serif; letter-spacing: -0.02em; margin-bottom: 0.5rem;">
                Dashboard</h2>
            <p style="color: var(--slate-700);">Welcome back, <span
                    style="color: var(--primary); font-weight: 700;"><?= htmlspecialchars($username) ?></span>. System
                is running
                smoothly.</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="files.php" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="folder-up" style="width: 16px; height: 16px;"></i> Upload Files
            </a>
        </div>
    </div>

    <!-- Stats Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem;">
        <!-- Domains -->
        <div class="glass-card"
            style="padding: 1.5rem; position: relative; overflow: hidden; transition: transform 0.3s;"
            onmouseover="this.style.transform='translateY(-4px)';" onmouseout="this.style.transform='translateY(0)';">
            <div
                style="position: absolute; right: -1rem; top: -1rem; width: 6rem; height: 6rem; background: rgba(37, 99, 235, 0.1); border-radius: 50%; filter: blur(20px);">
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; position: relative; z-index: 10;">
                <div
                    style="padding: 0.75rem; background: rgba(37, 99, 235, 0.1); color: var(--primary); border-radius: 0.75rem;">
                    <i data-lucide="globe" style="width: 24px; height: 24px;"></i>
                </div>
                <span class="badge" style="background: rgba(0,0,0,0.05); color: var(--slate-700);"><?= $usage_dom ?> /
                    <?= $clientData['max_domains'] ?></span>
            </div>
            <h3
                style="font-size: 1.875rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem; position: relative; z-index: 10;">
                <?= $usage_dom ?>
            </h3>
            <p style="font-size: 0.875rem; color: var(--slate-700); font-weight: 500; position: relative; z-index: 10;">
                Active Domains</p>
            <div
                style="width: 100%; background: var(--slate-100); height: 4px; margin-top: 1rem; border-radius: 9999px; overflow: hidden;">
                <div
                    style="background: var(--primary); height: 100%; border-radius: 9999px; width: <?= ($usage_dom / max(1, $clientData['max_domains'])) * 100 ?>%">
                </div>
            </div>
        </div>

        <!-- Databases -->
        <div class="glass-card"
            style="padding: 1.5rem; position: relative; overflow: hidden; transition: transform 0.3s;"
            onmouseover="this.style.transform='translateY(-4px)';" onmouseout="this.style.transform='translateY(0)';">
            <div
                style="position: absolute; right: -1rem; top: -1rem; width: 6rem; height: 6rem; background: rgba(168, 85, 247, 0.1); border-radius: 50%; filter: blur(20px);">
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; position: relative; z-index: 10;">
                <div
                    style="padding: 0.75rem; background: rgba(168, 85, 247, 0.1); color: #a855f7; border-radius: 0.75rem;">
                    <i data-lucide="database" style="width: 24px; height: 24px;"></i>
                </div>
                <span class="badge" style="background: rgba(0,0,0,0.05); color: var(--slate-700);"><?= $usage_db ?> /
                    <?= $clientData['max_databases'] ?></span>
            </div>
            <h3
                style="font-size: 1.875rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem; position: relative; z-index: 10;">
                <?= $usage_db ?>
            </h3>
            <p style="font-size: 0.875rem; color: var(--slate-700); font-weight: 500; position: relative; z-index: 10;">
                MySQL Databases</p>
            <div
                style="width: 100%; background: var(--slate-100); height: 4px; margin-top: 1rem; border-radius: 9999px; overflow: hidden;">
                <div
                    style="background: #a855f7; height: 100%; border-radius: 9999px; width: <?= ($usage_db / max(1, $clientData['max_databases'])) * 100 ?>%">
                </div>
            </div>
        </div>

        <!-- Emails -->
        <div class="glass-card"
            style="padding: 1.5rem; position: relative; overflow: hidden; transition: transform 0.3s;"
            onmouseover="this.style.transform='translateY(-4px)';" onmouseout="this.style.transform='translateY(0)';">
            <div
                style="position: absolute; right: -1rem; top: -1rem; width: 6rem; height: 6rem; background: rgba(16, 185, 129, 0.1); border-radius: 50%; filter: blur(20px);">
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; position: relative; z-index: 10;">
                <div
                    style="padding: 0.75rem; background: rgba(16, 185, 129, 0.1); color: var(--accent-emerald); border-radius: 0.75rem;">
                    <i data-lucide="mail" style="width: 24px; height: 24px;"></i>
                </div>
                <span class="badge" style="background: rgba(0,0,0,0.05); color: var(--slate-700);"><?= $usage_mail ?> /
                    <?= $clientData['max_emails'] ?></span>
            </div>
            <h3
                style="font-size: 1.875rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem; position: relative; z-index: 10;">
                <?= $usage_mail ?>
            </h3>
            <p style="font-size: 0.875rem; color: var(--slate-700); font-weight: 500; position: relative; z-index: 10;">
                Email Accounts</p>
            <div
                style="width: 100%; background: var(--slate-100); height: 4px; margin-top: 1rem; border-radius: 9999px; overflow: hidden;">
                <div
                    style="background: var(--accent-emerald); height: 100%; border-radius: 9999px; width: <?= ($usage_mail / max(1, $clientData['max_emails'])) * 100 ?>%">
                </div>
            </div>
        </div>

        <!-- Storage -->
        <div class="glass-card"
            style="padding: 1.5rem; position: relative; overflow: hidden; transition: transform 0.3s;"
            onmouseover="this.style.transform='translateY(-4px)';" onmouseout="this.style.transform='translateY(0)';">
            <div
                style="position: absolute; right: -1rem; top: -1rem; width: 6rem; height: 6rem; background: rgba(249, 115, 22, 0.1); border-radius: 50%; filter: blur(20px);">
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; position: relative; z-index: 10;">
                <div
                    style="padding: 0.75rem; background: rgba(249, 115, 22, 0.1); color: #f97316; border-radius: 0.75rem;">
                    <i data-lucide="hard-drive" style="width: 24px; height: 24px;"></i>
                </div>
                <span class="badge"
                    style="background: rgba(249, 115, 22, 0.1); color: #f97316; border: 1px solid rgba(249, 115, 22, 0.2);"><?= htmlspecialchars($clientData['pkg_name']) ?></span>
            </div>
            <h3
                style="font-size: 1.875rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem; position: relative; z-index: 10;">
                <?= $used_mb ?> MB
            </h3>
            <p style="font-size: 0.875rem; color: var(--slate-700); font-weight: 500; position: relative; z-index: 10;">
                of <?= $clientData['disk_mb'] ?> MB Used</p>
            <div
                style="width: 100%; background: var(--slate-100); height: 4px; margin-top: 1rem; border-radius: 9999px; overflow: hidden;">
                <div style="background: #f97316; height: 100%; border-radius: 9999px; width: <?= $disk_percent ?>%">
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
        <!-- Left Column: Traffic Graph -->
        <div style="grid-column: span 2 / span 2; display: flex; flex-direction: column; gap: 2rem;">
            <div class="glass-card" style="padding: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <div>
                        <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--slate-900);">Network Traffic</h3>
                        <p style="font-size: 0.75rem; color: var(--slate-700);">Hits & Bandwidth (Last 7 Days)</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span
                            style="display: flex; width: 8px; height: 8px; border-radius: 50%; background: var(--primary);"></span>
                        <span style="font-size: 0.75rem; color: var(--slate-700);">Flow</span>
                    </div>
                </div>
                <!-- Chart Container -->
                <div id="trafficChart" style="width: 100%; height: 300px;"></div>
            </div>

            <!-- Quick Actions -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem;">
                <!-- Replaced Tailwind Action Cards with styled anchors -->
                <a href="emails.php" class="glass-card"
                    style="padding: 1rem; text-align: center; text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; transition: transform 0.2s;"
                    onmouseover="this.style.transform='translateY(-4px)'; this.style.borderColor='var(--primary)';"
                    onmouseout="this.style.transform='translateY(0)'; this.style.borderColor='var(--slate-200)';">
                    <div
                        style="padding: 0.75rem; background: var(--slate-100); border-radius: 50%; color: var(--primary);">
                        <i data-lucide="mail-plus" style="width: 20px; height: 20px;"></i>
                    </div>
                    <span style="font-weight: 700; font-size: 0.875rem; color: var(--slate-700);">New Email</span>
                </a>
                <a href="databases.php" class="glass-card"
                    style="padding: 1rem; text-align: center; text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; transition: transform 0.2s;"
                    onmouseover="this.style.transform='translateY(-4px)'; this.style.borderColor='#a855f7';"
                    onmouseout="this.style.transform='translateY(0)'; this.style.borderColor='var(--slate-200)';">
                    <div style="padding: 0.75rem; background: var(--slate-100); border-radius: 50%; color: #a855f7;"><i
                            data-lucide="database" style="width: 20px; height: 20px;"></i></div>
                    <span style="font-weight: 700; font-size: 0.875rem; color: var(--slate-700);">Add DB</span>
                </a>
                <a href="domains.php" class="glass-card"
                    style="padding: 1rem; text-align: center; text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; transition: transform 0.2s;"
                    onmouseover="this.style.transform='translateY(-4px)'; this.style.borderColor='var(--accent-emerald)';"
                    onmouseout="this.style.transform='translateY(0)'; this.style.borderColor='var(--slate-200)';">
                    <div
                        style="padding: 0.75rem; background: var(--slate-100); border-radius: 50%; color: var(--accent-emerald);">
                        <i data-lucide="globe" style="width: 20px; height: 20px;"></i>
                    </div>
                    <span style="font-weight: 700; font-size: 0.875rem; color: var(--slate-700);">Add Domain</span>
                </a>
                <a href="tools.php" class="glass-card"
                    style="padding: 1rem; text-align: center; text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; transition: transform 0.2s;"
                    onmouseover="this.style.transform='translateY(-4px)'; this.style.borderColor='#f97316';"
                    onmouseout="this.style.transform='translateY(0)'; this.style.borderColor='var(--slate-200)';">
                    <div style="padding: 0.75rem; background: var(--slate-100); border-radius: 50%; color: #f97316;"><i
                            data-lucide="wrench" style="width: 20px; height: 20px;"></i></div>
                    <span style="font-weight: 700; font-size: 0.875rem; color: var(--slate-700);">Tools</span>
                </a>
            </div>
        </div>

        <!-- Right Column: Logs & Info -->
        <div style="display: flex; flex-direction: column; gap: 2rem;">
            <!-- Server Info -->
            <div class="glass-card" style="padding: 1.5rem;">
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--slate-900); margin-bottom: 1rem;">Server
                    Info</h3>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div
                        style="display: flex; justify-content: space-between; font-size: 0.875rem; padding: 0.5rem 0; border-bottom: 1px solid var(--slate-200);">
                        <span style="color: var(--slate-700);">IP Address</span>
                        <span
                            style="font-family: monospace; color: var(--slate-900);"><?= $_SERVER['SERVER_ADDR'] ?></span>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; font-size: 0.875rem; padding: 0.5rem 0; border-bottom: 1px solid var(--slate-200);">
                        <span style="color: var(--slate-700);">PHP Version</span>
                        <span style="font-family: monospace; color: var(--primary);">8.2 (Default)</span>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; font-size: 0.875rem; padding: 0.5rem 0; border-bottom: 1px solid var(--slate-200);">
                        <span style="color: var(--slate-700);">Web Server</span>
                        <span style="font-family: monospace; color: var(--accent-emerald);">Nginx</span>
                    </div>
                    <div style="margin-top: 1rem; padding-top: 0.5rem;">
                        <div
                            style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 0.25rem;">
                            <span style="color: var(--slate-700);">System Load</span>
                            <span style="color: var(--accent-emerald);">Healthy</span>
                        </div>
                        <div
                            style="height: 6px; background: var(--slate-100); border-radius: 9999px; overflow: hidden;">
                            <div style="height: 100%; background: var(--accent-emerald); width: 25%;"
                                class="animate-pulse"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error Logs -->
            <div class="glass-card"
                style="overflow: hidden; display: flex; flex-direction: column; height: 300px; padding: 0;">
                <div
                    style="padding: 1rem; border-bottom: 1px solid var(--slate-200); display: flex; justify-content: space-between; align-items: center; background: var(--slate-50);">
                    <h3
                        style="font-weight: 700; color: var(--slate-900); font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i data-lucide="terminal" style="width: 16px; height: 16px; color: var(--slate-700);"></i> Error
                        Stream
                    </h3>
                    <button onclick="fetchLogs()"
                        style="color: var(--slate-700); background: transparent; border: none; cursor: pointer; transition: color 0.2s;"
                        onmouseover="this.style.color='var(--slate-900)';"
                        onmouseout="this.style.color='var(--slate-700)';"><i data-lucide="refresh-cw"
                            style="width: 14px; height: 14px;"></i></button>
                </div>
                <div style="flex: 1; overflow-y: auto; padding: 1rem; background: var(--slate-50); font-family: monospace; font-size: 11px; color: var(--slate-400); line-height: 1.6;"
                    id="log-container" class="custom-scrollbar">
                    <div style="display: flex; items-center; justify-content: center; height: 100%; color: var(--slate-500);"
                        class="animate-pulse">Connecting to
                        stream...</div>
                </div>
                <div
                    style="padding: 0.5rem 1rem; background: var(--slate-50); border-top: 1px solid var(--slate-200); display: flex; justify-content: space-between; align-items: center;">
                    <span
                        style="display: flex; align-items: center; gap: 0.5rem; font-size: 10px; color: var(--accent-emerald); font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em;">
                        <span style="position: relative; display: flex; height: 8px; width: 8px;">
                            <span class="animate-ping"
                                style="position: absolute; display: inline-flex; height: 100%; width: 100%; border-radius: 50%; background: var(--accent-emerald); opacity: 0.75;"></span>
                            <span
                                style="position: relative; display: inline-flex; border-radius: 50%; height: 8px; width: 8px; background: var(--accent-emerald);"></span>
                        </span>
                        Live
                    </span>
                    <button onclick="clearLogs()" class="btn btn-secondary"
                        style="padding: 0.25rem 0.75rem; font-size: 0.75rem;">
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
                    cont.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--slate-700);">No recent errors.</div>';
                } else {
                    cont.innerHTML = `<pre style="white-space: pre-wrap;">${text}</pre>`;
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