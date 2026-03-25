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
try {
    if (function_exists('cmd')) {
        $used_bytes = (int) cmd("get-client-usage " . escapeshellarg($username));
    }
} catch (Exception $e) {
    // shm-manage not installed or command failed — treat as 0 bytes used
    error_log("SHM Panel: disk usage command failed: " . $e->getMessage());
    $used_bytes = 0;
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

    <?php
    $hour = date('H');
    $greeting = 'Good evening';
    if ($hour < 12) {
        $greeting = 'Good morning';
    } elseif ($hour < 17) {
        $greeting = 'Good afternoon';
    }
    ?>
    <!-- Welcome Section -->
    <div
        style="display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem; flex-wrap: wrap;">
        <div>
            <h2
                style="font-size: 2rem; font-weight: 800; color: var(--text-primary); font-family: var(--font-heading); letter-spacing: -0.02em; margin-bottom: 0.5rem;">
                <?= $greeting ?>, <?= htmlspecialchars($username) ?> 👋
            </h2>
            <p style="color: var(--text-secondary); font-size: 1rem;">Here is what's happening with your server today.</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="files.php" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="folder-up" style="width: 16px; height: 16px;"></i> Upload Files
            </a>
        </div>
    </div>

    <!-- Package Summary Strip -->
    <div class="pkg-strip">
        <div class="pkg-strip-item">
            <i data-lucide="package" style="width: 1rem; height: 1rem; color: var(--primary);"></i>
            <span class="pkg-label">Plan</span>
            <span class="pkg-value"><?= htmlspecialchars($clientData['pkg_name']) ?></span>
        </div>
        <div class="pkg-strip-item">
            <i data-lucide="hard-drive" style="width: 1rem; height: 1rem; color: var(--primary);"></i>
            <span class="pkg-label">Disk</span>
            <span class="pkg-value"><?= $used_mb ?> / <?= $clientData['disk_mb'] ?> MB</span>
        </div>
        <div class="pkg-strip-item">
            <i data-lucide="globe" style="width: 1rem; height: 1rem; color: var(--primary);"></i>
            <span class="pkg-label">Domains</span>
            <span class="pkg-value"><?= $usage_dom ?> / <?= $clientData['max_domains'] ?></span>
        </div>
        <div class="pkg-strip-item">
            <i data-lucide="database" style="width: 1rem; height: 1rem; color: var(--primary);"></i>
            <span class="pkg-label">Databases</span>
            <span class="pkg-value"><?= $usage_db ?> / <?= $clientData['max_databases'] ?></span>
        </div>
        <div class="pkg-strip-item">
            <span class="badge badge-success">Active</span>
        </div>
    </div>

    <!-- Stats Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem;">
        <!-- Domains -->
        <div class="stat-card animate-count"
            style="padding: 1.5rem; position: relative; overflow: hidden;">
            <div
                style="position: absolute; right: -1rem; top: -1rem; width: 6rem; height: 6rem; background: rgba(37, 99, 235, 0.1); border-radius: 50%; filter: blur(20px);">
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; position: relative; z-index: 10;">
                <div
                    style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: rgba(37, 99, 235, 0.1); color: var(--primary); border-radius: 0.75rem;">
                    <i data-lucide="globe" style="width: 24px; height: 24px;"></i>
                </div>
                <?php
                $dom_pct = ($clientData['max_domains'] > 0) ? round(($usage_dom / $clientData['max_domains']) * 100) : 0;
                $dom_trend_class = $dom_pct > 80 ? 'trend-down' : ($dom_pct > 50 ? 'trend-neutral' : 'trend-up');
                $dom_trend_icon = $dom_pct > 80 ? '↑' : ($dom_pct > 50 ? '→' : '✓');
                ?>
                <span class="stat-trend <?= $dom_trend_class ?>"><?= $dom_trend_icon ?> <?= $dom_pct ?>%</span>
            </div>
            <h3 class="metric-value animate-count">
                <?= $usage_dom ?>
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-secondary); font-weight: 500; position: relative; z-index: 10; margin-top: 0.25rem;">
                Active Domains &mdash; <?= $usage_dom ?> / <?= $clientData['max_domains'] ?></p>
            <div
                style="width: 100%; background: var(--bg-body); height: 6px; margin-top: 1rem; border-radius: 9999px; overflow: hidden;">
                <div class="progress-bar-fill"
                    style="background: linear-gradient(90deg, #60a5fa, #3b82f6); height: 100%; border-radius: 9999px; width: <?= $dom_pct ?>%; transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 0 10px rgba(59,130,246,0.3);">
                </div>
            </div>
        </div>

        <!-- Databases -->
        <div class="stat-card animate-count"
            style="padding: 1.5rem; position: relative; overflow: hidden; animation-delay: 0.1s;">
            <div
                style="position: absolute; right: -1rem; top: -1rem; width: 6rem; height: 6rem; background: rgba(168, 85, 247, 0.1); border-radius: 50%; filter: blur(20px);">
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; position: relative; z-index: 10;">
                <div
                    style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: rgba(168, 85, 247, 0.1); color: #a855f7; border-radius: 0.75rem;">
                    <i data-lucide="database" style="width: 24px; height: 24px;"></i>
                </div>
                <?php
                $db_pct = ($clientData['max_databases'] > 0) ? round(($usage_db / $clientData['max_databases']) * 100) : 0;
                $db_trend_class = $db_pct > 80 ? 'trend-down' : ($db_pct > 50 ? 'trend-neutral' : 'trend-up');
                $db_trend_icon = $db_pct > 80 ? '↑' : ($db_pct > 50 ? '→' : '✓');
                ?>
                <span class="stat-trend <?= $db_trend_class ?>"><?= $db_trend_icon ?> <?= $db_pct ?>%</span>
            </div>
            <h3 class="metric-value">
                <?= $usage_db ?>
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-secondary); font-weight: 500; position: relative; z-index: 10; margin-top: 0.25rem;">
                Databases &mdash; <?= $usage_db ?> / <?= $clientData['max_databases'] ?></p>
            <div
                style="width: 100%; background: var(--bg-body); height: 6px; margin-top: 1rem; border-radius: 9999px; overflow: hidden;">
                <div class="progress-bar-fill"
                    style="background: linear-gradient(90deg, #c084fc, #a855f7); height: 100%; border-radius: 9999px; width: <?= $db_pct ?>%; transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 0 10px rgba(168,85,247,0.3);">
                </div>
            </div>
        </div>

        <!-- Emails -->
        <div class="stat-card animate-count"
            style="padding: 1.5rem; position: relative; overflow: hidden; animation-delay: 0.2s;">
            <div
                style="position: absolute; right: -1rem; top: -1rem; width: 6rem; height: 6rem; background: rgba(16, 185, 129, 0.1); border-radius: 50%; filter: blur(20px);">
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; position: relative; z-index: 10;">
                <div
                    style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: rgba(16, 185, 129, 0.1); color: var(--accent-emerald); border-radius: 0.75rem;">
                    <i data-lucide="mail" style="width: 24px; height: 24px;"></i>
                </div>
                <?php
                $mail_pct = ($clientData['max_emails'] > 0) ? round(($usage_mail / $clientData['max_emails']) * 100) : 0;
                $mail_trend_class = $mail_pct > 80 ? 'trend-down' : ($mail_pct > 50 ? 'trend-neutral' : 'trend-up');
                $mail_trend_icon = $mail_pct > 80 ? '↑' : ($mail_pct > 50 ? '→' : '✓');
                ?>
                <span class="stat-trend <?= $mail_trend_class ?>"><?= $mail_trend_icon ?> <?= $mail_pct ?>%</span>
            </div>
            <h3 class="metric-value">
                <?= $usage_mail ?>
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-secondary); font-weight: 500; position: relative; z-index: 10; margin-top: 0.25rem;">
                Email Accounts &mdash; <?= $usage_mail ?> / <?= $clientData['max_emails'] ?></p>
            <div
                style="width: 100%; background: var(--bg-body); height: 6px; margin-top: 1rem; border-radius: 9999px; overflow: hidden;">
                <div class="progress-bar-fill"
                    style="background: linear-gradient(90deg, #34d399, #10b981); height: 100%; border-radius: 9999px; width: <?= $mail_pct ?>%; transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 0 10px rgba(16,185,129,0.3);">
                </div>
            </div>
        </div>

        <!-- Storage -->
        <div class="stat-card animate-count"
            style="padding: 1.5rem; position: relative; overflow: hidden; animation-delay: 0.3s;">
            <div
                style="position: absolute; right: -1rem; top: -1rem; width: 6rem; height: 6rem; background: rgba(249, 115, 22, 0.1); border-radius: 50%; filter: blur(20px);">
            </div>
            <div
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; position: relative; z-index: 10;">
                <div
                    style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: rgba(249, 115, 22, 0.1); color: #f97316; border-radius: 0.75rem;">
                    <i data-lucide="hard-drive" style="width: 24px; height: 24px;"></i>
                </div>
                <?php $disk_trend_class = $disk_percent > 80 ? 'trend-down' : ($disk_percent > 50 ? 'trend-neutral' : 'trend-up'); ?>
                <span class="stat-trend <?= $disk_trend_class ?>"><?= $disk_percent > 80 ? '↑' : ($disk_percent > 50 ? '→' : '✓') ?> <?= round($disk_percent) ?>%</span>
            </div>
            <h3 class="metric-value">
                <?= $used_mb ?> <span style="font-size: 1rem; font-weight: 500;">MB</span>
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-secondary); font-weight: 500; position: relative; z-index: 10; margin-top: 0.25rem;">
                of <?= $clientData['disk_mb'] ?> MB Used</p>
            <div
                style="width: 100%; background: var(--bg-body); height: 6px; margin-top: 1rem; border-radius: 9999px; overflow: hidden;">
                <div class="progress-bar-fill"
                    style="background: linear-gradient(90deg, #fb923c, #f97316); height: 100%; border-radius: 9999px; width: <?= $disk_percent ?>%; transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 0 10px rgba(249,115,22,0.3);">
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
        <!-- Left Column: Traffic Graph -->
        <div style="grid-column: span 2 / span 2; display: flex; flex-direction: column; gap: 2rem;">
            <div class="premium-glass" style="padding: 1.5rem; border-radius: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <div>
                        <h3 style="font-size: 1.125rem; font-weight: 500; color: var(--slate-900);">Network Traffic</h3>
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
                <a href="emails.php" class="glass-card action-card"
                    style="padding: 1.5rem 1rem; text-align: center; text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid transparent;"
                    onmouseover="this.style.transform='translateY(-6px)'; this.style.borderColor='rgba(59, 130, 246, 0.3)'; this.style.boxShadow='0 20px 25px -5px rgba(59, 130, 246, 0.1), 0 8px 10px -6px rgba(59, 130, 246, 0.1)';"
                    onmouseout="this.style.transform='translateY(0)'; this.style.borderColor='transparent'; this.style.boxShadow='var(--shadow-md)';">
                    <div class="action-icon"
                        style="display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 50%; color: var(--primary); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid rgba(59, 130, 246, 0.2);">
                        <i data-lucide="mail-plus" style="width: 24px; height: 24px;"></i>
                    </div>
                    <span style="font-weight: 800; font-size: 0.875rem; color: var(--slate-800);">New Email</span>
                </a>
                <a href="databases.php" class="glass-card action-card"
                    style="padding: 1.5rem 1rem; text-align: center; text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid transparent;"
                    onmouseover="this.style.transform='translateY(-6px)'; this.style.borderColor='rgba(168, 85, 247, 0.3)'; this.style.boxShadow='0 20px 25px -5px rgba(168, 85, 247, 0.1), 0 8px 10px -6px rgba(168, 85, 247, 0.1)';"
                    onmouseout="this.style.transform='translateY(0)'; this.style.borderColor='transparent'; this.style.boxShadow='var(--shadow-md)';">
                    <div class="action-icon"
                        style="display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(168, 85, 247, 0.1); border-radius: 50%; color: #a855f7; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid rgba(168, 85, 247, 0.2);">
                        <i data-lucide="database" style="width: 24px; height: 24px;"></i>
                    </div>
                    <span style="font-weight: 800; font-size: 0.875rem; color: var(--slate-800);">Add Database</span>
                </a>
                <a href="domains.php" class="glass-card action-card"
                    style="padding: 1.5rem 1rem; text-align: center; text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid transparent;"
                    onmouseover="this.style.transform='translateY(-6px)'; this.style.borderColor='rgba(16, 185, 129, 0.3)'; this.style.boxShadow='0 20px 25px -5px rgba(16, 185, 129, 0.1), 0 8px 10px -6px rgba(16, 185, 129, 0.1)';"
                    onmouseout="this.style.transform='translateY(0)'; this.style.borderColor='transparent'; this.style.boxShadow='var(--shadow-md)';">
                    <div class="action-icon"
                        style="display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 50%; color: var(--accent-emerald); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid rgba(16, 185, 129, 0.2);">
                        <i data-lucide="globe" style="width: 24px; height: 24px;"></i>
                    </div>
                    <span style="font-weight: 800; font-size: 0.875rem; color: var(--slate-800);">Add Domain</span>
                </a>
                <a href="tools.php" class="glass-card action-card"
                    style="padding: 1.5rem 1rem; text-align: center; text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid transparent;"
                    onmouseover="this.style.transform='translateY(-6px)'; this.style.borderColor='rgba(249, 115, 22, 0.3)'; this.style.boxShadow='0 20px 25px -5px rgba(249, 115, 22, 0.1), 0 8px 10px -6px rgba(249, 115, 22, 0.1)';"
                    onmouseout="this.style.transform='translateY(0)'; this.style.borderColor='transparent'; this.style.boxShadow='var(--shadow-md)';">
                    <div class="action-icon"
                        style="display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(249, 115, 22, 0.1); border-radius: 50%; color: #f97316; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid rgba(249, 115, 22, 0.2);">
                        <i data-lucide="wrench" style="width: 24px; height: 24px;"></i>
                    </div>
                    <span style="font-weight: 800; font-size: 0.875rem; color: var(--slate-800);">Tools &
                        Settings</span>
                </a>
            </div>
        </div>

        <!-- Right Column: Logs & Info -->
        <div style="display: flex; flex-direction: column; gap: 2rem;">
            <!-- Server Info -->
            <div class="premium-glass" style="padding: 1.5rem; border-radius: 1.25rem;">
                <h3 style="font-size: 1.125rem; font-weight: 500; color: var(--slate-900); margin-bottom: 1rem;">Server
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
                        <span style="font-family: monospace; color: var(--primary);"><?= phpversion() ?></span>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; font-size: 0.875rem; padding: 0.5rem 0; border-bottom: 1px solid var(--slate-200);">
                        <span style="color: var(--slate-700);">Web Server</span>
                        <?php $srv_sw = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
                        $srv_display = explode('/', explode(' ', $srv_sw)[0])[0]; ?>
                        <span style="font-family: monospace; color: var(--accent-emerald);"
                            title="<?= htmlspecialchars($srv_sw) ?>"><?= htmlspecialchars($srv_display) ?></span>
                    </div>
                    <div style="margin-top: 1rem; padding-top: 0.5rem;">
                        <?php
                        $load_avg = sys_getloadavg();
                        $load_val = isset($load_avg[0]) ? round($load_avg[0], 2) : 0;
                        $load_pct = min(100, round($load_val / 4 * 100));
                        if ($load_pct < 50) {
                            $lc = 'var(--accent-emerald)';
                            $ll = 'Healthy';
                        } elseif ($load_pct < 80) {
                            $lc = 'var(--accent-amber)';
                            $ll = 'Moderate';
                        } else {
                            $lc = 'var(--accent-red)';
                            $ll = 'High';
                        }
                        ?>
                        <div
                            style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 0.25rem;">
                            <span style="color: var(--slate-700);">System Load</span>
                            <span style="color: <?= $lc ?>; font-family: monospace;"><?= $load_val ?> &mdash;
                                <?= $ll ?></span>
                        </div>
                        <div
                            style="height: 6px; background: var(--slate-100); border-radius: 9999px; overflow: hidden;">
                            <div
                                style="height: 100%; background: <?= $lc ?>; width: <?= $load_pct ?>%; transition: width 0.6s ease;">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error Logs -->
            <div class="premium-glass"
                style="overflow: hidden; display: flex; flex-direction: column; height: 300px; padding: 0; border-radius: 1.25rem;">
                <div
                    style="padding: 1rem; border-bottom: 1px solid var(--slate-200); display: flex; justify-content: space-between; align-items: center; background: var(--slate-50);">
                    <h3
                        style="font-weight: 500; color: var(--slate-900); font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem;">
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
                        style="display: flex; align-items: center; gap: 0.5rem; font-size: 10px; color: var(--accent-emerald); font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em;">
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
                borderColor: 'var(--slate-200)',
                strokeDashArray: 4,
            },
            theme: { mode: 'light' },
            tooltip: {
                theme: 'light',
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