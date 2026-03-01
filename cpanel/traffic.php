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
                style="font-size: 1.875rem; line-height: 2.25rem; font-weight: 700; color: var(--slate-900); font-family: 'Lexend', sans-serif; letter-spacing: -0.025em; margin-bottom: 0.5rem;">
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
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
        <div class="glass-panel" style="padding: 1.5rem; border-color: rgba(59, 130, 246, 0.2);">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                <div style="padding: 0.75rem; background-color: rgba(59, 130, 246, 0.1); border-radius: 0.75rem;">
                    <i data-lucide="activity" style="width: 1.5rem; height: 1.5rem; color: #60a5fa;"></i>
                </div>
                <div>
                    <h3 style="font-weight: 700; color: var(--slate-900); font-size: 1.125rem;">Total Hits</h3>
                    <p
                        style="font-size: 0.75rem; color: var(--slate-700); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">
                        All Time</p>
                </div>
            </div>
            <div
                style="font-size: 1.875rem; line-height: 2.25rem; font-weight: 700; font-family: 'Lexend', sans-serif; color: var(--slate-900);">
                <?= number_format($monthly_total_hits) ?>
            </div>
        </div>

        <div class="glass-panel" style="padding: 1.5rem; border-color: rgba(168, 85, 247, 0.2);">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                <div style="padding: 0.75rem; background-color: rgba(168, 85, 247, 0.1); border-radius: 0.75rem;">
                    <i data-lucide="hard-drive" style="width: 1.5rem; height: 1.5rem; color: #c084fc;"></i>
                </div>
                <div>
                    <h3 style="font-weight: 700; color: var(--slate-900); font-size: 1.125rem;">Total Bandwidth</h3>
                    <p
                        style="font-size: 0.75rem; color: var(--slate-700); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">
                        All Time</p>
                </div>
            </div>
            <div
                style="font-size: 1.875rem; line-height: 2.25rem; font-weight: 700; font-family: 'Lexend', sans-serif; color: var(--slate-900);">
                <?= round($monthly_total_bytes / 1024 / 1024, 2) ?> MB
            </div>
        </div>
    </div>

    <div class="glass-panel" style="padding: 0; overflow: hidden; font-size: 0.875rem;">
        <div
            style="padding: 1rem; border-bottom: 1px solid var(--slate-300); background-color: var(--slate-50); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-weight: 700; color: var(--slate-900);">Domain Traffic</h3>
            <button onclick="location.reload()" style="color: var(--slate-700); transition: color 0.2s;"
                onmouseover="this.style.color='var(--slate-900)'" onmouseout="this.style.color='var(--slate-700)'">
                <i data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i>
            </button>
        </div>
        <div class="table-container">
            <table class="modern-table" style="width: 100%; text-align: left;">
                <thead
                    style="background-color: var(--slate-50); font-size: 0.625rem; text-transform: uppercase; color: var(--slate-700); font-weight: 700; letter-spacing: 0.05em;">
                    <tr>
                        <th style="padding: 1.25rem; font-weight: 700;">Domain Name</th>
                        <th style="padding: 1.25rem; font-weight: 700;">Hits Count</th>
                        <th style="padding: 1.25rem; font-weight: 700; text-align: right; width: 250px;">Bandwidth Sent
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($domains_traffic)): ?>
                        <tr>
                            <td colspan="3" style="padding: 2rem; text-align: center; color: var(--slate-700);">No domains
                                found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($domains_traffic as $t): ?>
                            <tr style="transition: background-color 0.2s;"
                                onmouseover="this.style.backgroundColor='rgba(248, 250, 252, 0.5)'"
                                onmouseout="this.style.backgroundColor='transparent'">
                                <td style="padding: 1.25rem; border-bottom: 1px solid var(--slate-200);">
                                    <div style="font-weight: 700; color: var(--slate-900);">
                                        <?= htmlspecialchars($t['domain']) ?>
                                    </div>
                                    <div
                                        style="font-size: 0.625rem; color: var(--slate-700); margin-top: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">
                                        Last Activity:
                                        <?= $t['last_activity'] ? date('M j, Y', strtotime($t['last_activity'])) : 'Never' ?>
                                    </div>
                                </td>
                                <td style="padding: 1.25rem; border-bottom: 1px solid var(--slate-200);">
                                    <span
                                        style="font-weight: 700; color: #34d399; background-color: rgba(16, 185, 129, 0.1); padding: 0.25rem 0.5rem; border-radius: 9999px; border: 1px solid rgba(16, 185, 129, 0.2);">
                                        <?= number_format((float) $t['total_hits']) ?>
                                    </span>
                                </td>
                                <td
                                    style="padding: 1.25rem; text-align: right; font-family: monospace; font-size: 0.75rem; color: #60a5fa; border-bottom: 1px solid var(--slate-200);">
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