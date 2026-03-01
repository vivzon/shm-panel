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

<div class="space-y-8">
    <!-- Header -->
    <div class="flex flex-col md:flex-row justify-between items-end gap-4 border-b border-slate-300 pb-6">
        <div>
            <h2 class="text-3xl font-bold text-slate-900 font-heading tracking-tight mb-2">Traffic & Stats</h2>
            <p class="text-slate-700">Monitor website activity and bandwidth consumption across your domains.</p>
        </div>
        <button onclick="syncTraffic(this)"
            class="px-6 py-3 bg-blue-600 hover:bg-blue-500 text-slate-900 rounded-xl font-bold transition shadow-lg shadow-blue-500/20 flex items-center gap-2">
            <i data-lucide="refresh-cw" class="w-4 h-4"></i> Sync Statistics
        </button>
    </div>

    <!-- Summary Widgets -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="glass-card p-6 border-blue-500/20">
            <div class="flex items-center gap-4 mb-4">
                <div class="p-3 bg-blue-500/10 rounded-xl">
                    <i data-lucide="activity" class="w-6 h-6 text-blue-400"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 text-lg">Total Hits</h3>
                    <p class="text-xs text-slate-700 font-bold uppercase tracking-wider">All Time</p>
                </div>
            </div>
            <div class="text-3xl font-bold font-heading text-slate-900">
                <?= number_format($monthly_total_hits) ?>
            </div>
        </div>

        <div class="glass-card p-6 border-purple-500/20">
            <div class="flex items-center gap-4 mb-4">
                <div class="p-3 bg-purple-500/10 rounded-xl">
                    <i data-lucide="hard-drive" class="w-6 h-6 text-purple-400"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 text-lg">Total Bandwidth</h3>
                    <p class="text-xs text-slate-700 font-bold uppercase tracking-wider">All Time</p>
                </div>
            </div>
            <div class="text-3xl font-bold font-heading text-slate-900">
                <?= round($monthly_total_bytes / 1024 / 1024, 2) ?> MB
            </div>
        </div>
    </div>

    <div class="glass-card overflow-hidden text-sm">
        <div class="p-4 border-b border-slate-300 bg-slate-50 flex justify-between items-center">
            <h3 class="font-bold text-slate-900">Domain Traffic</h3>
            <button onclick="location.reload()" class="text-slate-700 hover:text-slate-900 transition group">
                <i data-lucide="refresh-cw" class="w-4 h-4 group-hover:rotate-180 transition duration-500"></i>
            </button>
        </div>
        <table class="w-full text-left">
            <thead class="bg-slate-50 text-[10px] uppercase text-slate-700 font-bold tracking-wider">
                <tr>
                    <th class="p-5 font-bold">Domain Name</th>
                    <th class="p-5 font-bold">Hits Count</th>
                    <th class="p-5 font-bold text-right" style="width: 250px;">Bandwidth Sent</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                <?php if (empty($domains_traffic)): ?>
                    <tr>
                        <td colspan="3" class="p-8 text-center text-slate-700">No domains found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($domains_traffic as $t): ?>
                        <tr class="hover:bg-slate-50/20 transition group">
                            <td class="p-5">
                                <div class="font-bold text-slate-900">
                                    <?= htmlspecialchars($t['domain']) ?>
                                </div>
                                <div class="text-[10px] text-slate-700 mt-1 uppercase tracking-wider font-bold">
                                    Last Activity:
                                    <?= $t['last_activity'] ? date('M j, Y', strtotime($t['last_activity'])) : 'Never' ?>
                                </div>
                            </td>
                            <td class="p-5">
                                <span
                                    class="font-bold text-emerald-400 bg-emerald-500/10 px-2 py-1 rounded-full border border-emerald-500/20">
                                    <?= number_format((float) $t['total_hits']) ?>
                                </span>
                            </td>
                            <td class="p-5 text-right font-mono text-xs text-blue-400">
                                <?= round((float) $t['total_bytes'] / 1024 / 1024, 2) ?> MB
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    async function syncTraffic(btn) {
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Syncing...`;
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



