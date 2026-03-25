<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'update_stats') {
    verify_csrf();
    if (function_exists('cmd')) {
        cmd("update-traffic-stats");
    }
    echo json_encode(['status' => 'success', 'msg' => 'Traffic data synced with Nginx logs.']);
    exit;
}

// Fetch Domains Traffic List
$domains_traffic = $pdo->query("
    SELECT d.id, d.domain, 
           SUM(t.bytes_sent) as total_bytes, 
           SUM(t.hits) as total_hits,
           MAX(t.date) as last_activity
    FROM domains d
    LEFT JOIN domain_traffic t ON d.id = t.domain_id
    WHERE d.client_id = $cid
    GROUP BY d.id
    ORDER BY total_bytes DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Monthly Total
$monthly_total_bytes = 0;
$monthly_total_hits = 0;
foreach ($domains_traffic as $t) {
    if ($t['total_bytes'])
        $monthly_total_bytes += $t['total_bytes'];
    if ($t['total_hits'])
        $monthly_total_hits += $t['total_hits'];
}

include 'layout/header.php';
?>

<div style="display: flex; flex-direction: column; gap: 2rem;">
    <!-- Header -->
    <div
        style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 1rem; border-bottom: 1px solid var(--slate-300); padding-bottom: 1.5rem;">
        <div>
            <h2
                style="font-size: 1.875rem; line-height: 2.25rem; font-weight: 500; color: var(--slate-900); font-family: 'Lexend', sans-serif; letter-spacing: -0.025em; margin-bottom: 0.5rem;">
                Traffic & Stats</h2>
            <p style="color: var(--slate-700);">Monitor website activity and bandwidth consumption across your domains.
            </p>
        </div>
        <button onclick="syncTraffic(this)" class="btn btn-primary"
            style="display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i> Sync Statistics
        </button>
    </div>

    <!-- Summary Widgets -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
        <div class="glass-card"
            style="padding: 1.75rem; border-color: transparent; position: relative; overflow: hidden;">
            <div
                style="position: absolute; top: -20px; right: -20px; width: 100px; height: 100px; background: radial-gradient(circle, rgba(59,130,246,0.15) 0%, rgba(59,130,246,0) 70%); border-radius: 50%;">
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
                <div>
                    <h3
                        style="font-weight: 800; color: var(--slate-500); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem;">
                        Total Hits</h3>
                    <p
                        style="font-size: 0.625rem; color: var(--slate-400); font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em;">
                        All Time</p>
                </div>
                <div
                    style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, rgba(59,130,246,0.1) 0%, rgba(59,130,246,0.05) 100%); display: flex; align-items: center; justify-content: center; box-shadow: inset 0 2px 4px rgba(255,255,255,0.5);">
                    <i data-lucide="activity" style="width: 24px; height: 24px; color: var(--primary);"></i>
                </div>
            </div>
            <div
                style="font-size: 2.25rem; line-height: 2.5rem; font-weight: 800; font-family: var(--font-heading); color: var(--slate-900); display: flex; align-items: baseline; gap: 0.5rem;">
                <?= number_format($monthly_total_hits) ?>
            </div>
        </div>

        <div class="glass-card"
            style="padding: 1.75rem; border-color: transparent; position: relative; overflow: hidden;">
            <div
                style="position: absolute; top: -20px; right: -20px; width: 100px; height: 100px; background: radial-gradient(circle, rgba(168,85,247,0.15) 0%, rgba(168,85,247,0) 70%); border-radius: 50%;">
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
                <div>
                    <h3
                        style="font-weight: 800; color: var(--slate-500); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem;">
                        Total Bandwidth</h3>
                    <p
                        style="font-size: 0.625rem; color: var(--slate-400); font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em;">
                        All Time</p>
                </div>
                <div
                    style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, rgba(168,85,247,0.1) 0%, rgba(168,85,247,0.05) 100%); display: flex; align-items: center; justify-content: center; box-shadow: inset 0 2px 4px rgba(255,255,255,0.5);">
                    <i data-lucide="hard-drive" style="width: 24px; height: 24px; color: #a855f7;"></i>
                </div>
            </div>
            <div
                style="font-size: 2.25rem; line-height: 2.5rem; font-weight: 800; font-family: var(--font-heading); color: var(--slate-900); display: flex; align-items: baseline; gap: 0.5rem;">
                <?= round($monthly_total_bytes / 1024 / 1024, 2) ?>
                <span style="font-size: 1rem; font-weight: 500; color: var(--slate-500);">MB</span>
            </div>
        </div>
    </div>

    <div class="glass-card table-card" style="padding: 0; overflow: hidden; font-size: 0.875rem;">
        <div
            style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--slate-200); background-color: var(--slate-50); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-weight: 800; color: var(--slate-900); font-family: var(--font-heading);">Domain Traffic
                Details</h3>
            <button onclick="location.reload()"
                style="color: var(--slate-500); background: transparent; border: none; cursor: pointer; padding: 0.5rem; border-radius: var(--radius-md); transition: all 0.2s;"
                onmouseover="this.style.color='var(--slate-900)'; this.style.backgroundColor='var(--slate-100)';"
                onmouseout="this.style.color='var(--slate-500)'; this.style.backgroundColor='transparent';">
                <i data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i>
            </button>
        </div>
        <div class="table-container custom-scrollbar">
            <table class="modern-table w-full text-left border-collapse" style="width: 100%;">
                <thead
                    style="background-color: var(--slate-50); font-size: 0.75rem; text-transform: uppercase; color: var(--slate-500); font-weight: 800; letter-spacing: 0.05em; border-bottom: 1px solid var(--slate-200);">
                    <tr>
                        <th style="padding: 1rem 1.5rem;">Domain Name</th>
                        <th style="padding: 1rem 1.5rem;">Hits Count</th>
                        <th style="padding: 1rem 1.5rem; text-align: right; width: 250px;">Bandwidth Sent
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($domains_traffic)): ?>
                        <tr>
                            <td colspan="3" style="padding: 3rem 1.5rem; text-align: center; color: var(--slate-500);">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                    <i data-lucide="bar-chart-2" style="width: 48px; height: 48px; opacity: 0.5;"></i>
                                    <span>No traffic data found.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($domains_traffic as $t): ?>
                            <tr style="border-bottom: 1px solid var(--slate-100); transition: background-color 0.2s;"
                                onmouseover="this.style.backgroundColor='var(--slate-50)'"
                                onmouseout="this.style.backgroundColor='transparent'">
                                <td style="padding: 1.25rem 1.5rem;">
                                    <div
                                        style="font-weight: 800; color: var(--slate-900); display: flex; align-items: center; gap: 0.5rem;">
                                        <i data-lucide="globe" style="width: 16px; height: 16px; color: var(--slate-400);"></i>
                                        <?= htmlspecialchars($t['domain']) ?>
                                    </div>
                                    <div
                                        style="font-size: 0.6875rem; color: var(--slate-500); margin-top: 0.375rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 500; display: flex; align-items: center; gap: 0.25rem;">
                                        <i data-lucide="clock" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                                        Last Activity:
                                        <span
                                            style="color: var(--slate-700);"><?= $t['last_activity'] ? date('M j, Y', strtotime($t['last_activity'])) : 'Never' ?></span>
                                    </div>
                                </td>
                                <td style="padding: 1.25rem 1.5rem;">
                                    <span class="badge badge-emerald" style="padding: 0.25rem 0.625rem; font-size: 0.75rem;">
                                        <?= number_format((float) $t['total_hits']) ?>
                                    </span>
                                </td>
                                <td
                                    style="padding: 1.25rem 1.5rem; text-align: right; font-family: 'JetBrains Mono', monospace; font-size: 0.8125rem; font-weight: 500; color: var(--primary);">
                                    <?= round((float) $t['total_bytes'] / 1024 / 1024, 2) ?> MB
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    async function syncTraffic(btn) {
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader-2" style="width: 1rem; height: 1rem; animation: spin 1s linear infinite;"></i> Syncing...`;
        lucide.createIcons();

        try {
            const fd = new FormData();
            fd.append('ajax_action', 'update_stats');
            fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            const res = await fetch('traffic.php', { method: 'POST', body: fd }).then(r => r.json());

            if (res.status === 'success') {
                showToast('success', 'Success', res.msg);
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', 'Error', res.msg);
            }
        } catch (e) {
            showToast('error', 'Error', 'Failed to connect to server');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
</script>

<?php include 'layout/footer.php'; ?>