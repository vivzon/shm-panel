<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'update_stats') {
    header('Content-Type: application/json');
    try {
        verify_csrf();
        $domains_list = $pdo->prepare("SELECT id, domain FROM domains WHERE client_id = ?");
        $domains_list->execute([$cid]);
        $my_domains = $domains_list->fetchAll(PDO::FETCH_ASSOC);
        $today  = date('Y-m-d');
        $synced = 0;
        foreach ($my_domains as $dom) {
            $domain  = $dom['domain'];
            $dom_id  = $dom['id'];
            $hits    = 0;
            $bytes   = 0;
            $uniques = [];
            $log_paths = [
                "/var/log/nginx/{$domain}.access.log",
                "/var/www/clients/{$username}/logs/{$domain}.access.log",
                "/var/log/nginx/access.log",
            ];
            $parsed = false;
            foreach ($log_paths as $log_path) {
                if (!file_exists($log_path) || !is_readable($log_path)) continue;
                $fh = fopen($log_path, 'r');
                if (!$fh) continue;
                fseek($fh, max(0, filesize($log_path) - 50000));
                fgets($fh);
                while (($line = fgets($fh)) !== false) {
                    if ($log_path === "/var/log/nginx/access.log" && strpos($line, $domain) === false) continue;
                    if (preg_match('/^(\S+) \S+ \S+ \[[^\]]+\] "[^"]*" \d+ (\d+)/', $line, $m)) {
                        $bytes += (int)$m[2];
                        $hits++;
                        $uniques[$m[1]] = true;
                    }
                }
                fclose($fh);
                $parsed = true;
                break;
            }
            if (!$parsed && function_exists('cmd')) {
                $out = @cmd("traffic-stats " . escapeshellarg($domain));
                if ($out && preg_match('/hits=(\d+).*bytes=(\d+)/i', $out, $m)) {
                    $hits  = (int)$m[1];
                    $bytes = (int)$m[2];
                }
            }
            if ($hits > 0 || $bytes > 0) {
                $pdo->prepare("
                    INSERT INTO domain_traffic (domain_id, date, hits, bytes_sent, unique_visitors)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        hits            = hits + VALUES(hits),
                        bytes_sent      = bytes_sent + VALUES(bytes_sent),
                        unique_visitors = GREATEST(unique_visitors, VALUES(unique_visitors))
                ")->execute([$dom_id, $today, $hits, $bytes, count($uniques)]);
                $synced++;
            }
        }
        echo json_encode(['status' => 'success', 'msg' => "Traffic synced for $synced domain(s)."]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');

$stmt = $pdo->prepare("
    SELECT d.id, d.domain,
           COALESCE(SUM(t.bytes_sent),0)       AS total_bytes,
           COALESCE(SUM(t.hits),0)             AS total_hits,
           COALESCE(SUM(t.unique_visitors),0)  AS total_uniques,
           MAX(t.date)                         AS last_activity
    FROM domains d
    LEFT JOIN domain_traffic t ON d.id = t.domain_id
    WHERE d.client_id = ?
    GROUP BY d.id, d.domain
    ORDER BY total_bytes DESC
");
$stmt->execute([$cid]);
$domains_traffic = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mstmt = $pdo->prepare("
    SELECT COALESCE(SUM(t.bytes_sent),0)      AS mb,
           COALESCE(SUM(t.hits),0)            AS hits,
           COALESCE(SUM(t.unique_visitors),0) AS uniques
    FROM domain_traffic t
    JOIN domains d ON t.domain_id = d.id
    WHERE d.client_id = ? AND t.date BETWEEN ? AND ?
");
$mstmt->execute([$cid, $month_start, $month_end]);
$month_stats = $mstmt->fetch(PDO::FETCH_ASSOC);

$has_any_data = array_sum(array_column($domains_traffic, 'total_hits')) > 0;

include 'layout/header.php';
?>

<div style="display:flex;flex-direction:column;gap:2rem;">
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:1rem;border-bottom:1px solid var(--slate-300);padding-bottom:1.5rem;">
        <div>
            <h2 style="font-size:1.875rem;font-weight:500;color:var(--slate-900);font-family:'Lexend',sans-serif;letter-spacing:-0.025em;margin-bottom:0.5rem;">Traffic &amp; Stats</h2>
            <p style="color:var(--slate-700);">Monitor website activity and bandwidth across your domains.</p>
        </div>
        <button onclick="syncTraffic(this)" class="btn btn-primary" style="display:flex;align-items:center;gap:0.5rem;">
            <i data-lucide="refresh-cw" style="width:1rem;height:1rem;"></i> Sync Statistics
        </button>
    </div>

    <?php if (!$has_any_data): ?>
    <div style="padding:1rem 1.5rem;background:rgba(59,130,246,0.05);border:1px solid rgba(59,130,246,0.2);border-radius:0.75rem;display:flex;align-items:center;gap:0.75rem;color:var(--slate-700);font-size:0.875rem;">
        <i data-lucide="info" style="width:1.125rem;height:1.125rem;color:var(--primary);flex-shrink:0;"></i>
        No traffic data yet. Click <strong>Sync Statistics</strong> to parse Nginx logs.
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.5rem;">
        <div class="glass-card" style="padding:1.75rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;background:radial-gradient(circle,rgba(59,130,246,0.15) 0%,transparent 70%);border-radius:50%;"></div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
                <div>
                    <h3 style="font-weight:800;color:var(--slate-500);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.25rem;">Hits This Month</h3>
                    <p style="font-size:0.625rem;color:var(--slate-400);font-weight:500;text-transform:uppercase;"><?= date('F Y') ?></p>
                </div>
                <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,rgba(59,130,246,0.1),rgba(59,130,246,0.05));display:flex;align-items:center;justify-content:center;">
                    <i data-lucide="activity" style="width:24px;height:24px;color:var(--primary);"></i>
                </div>
            </div>
            <div style="font-size:2.25rem;font-weight:800;font-family:var(--font-heading);color:var(--slate-900);"><?= number_format((int)$month_stats['hits']) ?></div>
        </div>
        <div class="glass-card" style="padding:1.75rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;background:radial-gradient(circle,rgba(168,85,247,0.15) 0%,transparent 70%);border-radius:50%;"></div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
                <div>
                    <h3 style="font-weight:800;color:var(--slate-500);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.25rem;">Bandwidth This Month</h3>
                    <p style="font-size:0.625rem;color:var(--slate-400);font-weight:500;text-transform:uppercase;"><?= date('F Y') ?></p>
                </div>
                <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,rgba(168,85,247,0.1),rgba(168,85,247,0.05));display:flex;align-items:center;justify-content:center;">
                    <i data-lucide="hard-drive" style="width:24px;height:24px;color:#a855f7;"></i>
                </div>
            </div>
            <div style="font-size:2.25rem;font-weight:800;font-family:var(--font-heading);color:var(--slate-900);display:flex;align-items:baseline;gap:0.5rem;">
                <?= number_format(round($month_stats['mb'] / 1024 / 1024, 2), 2) ?>
                <span style="font-size:1rem;font-weight:500;color:var(--slate-500);">MB</span>
            </div>
        </div>
        <div class="glass-card" style="padding:1.75rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;background:radial-gradient(circle,rgba(16,185,129,0.15) 0%,transparent 70%);border-radius:50%;"></div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
                <div>
                    <h3 style="font-weight:800;color:var(--slate-500);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.25rem;">Unique Visitors</h3>
                    <p style="font-size:0.625rem;color:var(--slate-400);font-weight:500;text-transform:uppercase;"><?= date('F Y') ?></p>
                </div>
                <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,rgba(16,185,129,0.1),rgba(16,185,129,0.05));display:flex;align-items:center;justify-content:center;">
                    <i data-lucide="users" style="width:24px;height:24px;color:#10b981;"></i>
                </div>
            </div>
            <div style="font-size:2.25rem;font-weight:800;font-family:var(--font-heading);color:var(--slate-900);"><?= number_format((int)$month_stats['uniques']) ?></div>
        </div>
    </div>

    <div class="glass-card table-card" style="padding:0;overflow:hidden;font-size:0.875rem;">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--slate-200);background-color:var(--slate-50);display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-weight:800;color:var(--slate-900);font-family:var(--font-heading);">All-Time Domain Traffic</h3>
            <button onclick="location.reload()" style="color:var(--slate-500);background:transparent;border:none;cursor:pointer;padding:0.5rem;border-radius:var(--radius-md);transition:all 0.2s;" onmouseover="this.style.color='var(--slate-900)';this.style.backgroundColor='var(--slate-100)';" onmouseout="this.style.color='var(--slate-500)';this.style.backgroundColor='transparent';">
                <i data-lucide="refresh-cw" style="width:1rem;height:1rem;"></i>
            </button>
        </div>
        <div class="table-container custom-scrollbar">
            <table style="width:100%;text-align:left;border-collapse:collapse;">
                <thead style="background-color:var(--slate-50);font-size:0.75rem;text-transform:uppercase;color:var(--slate-500);font-weight:800;letter-spacing:0.05em;border-bottom:1px solid var(--slate-200);">
                    <tr>
                        <th style="padding:1rem 1.5rem;">Domain</th>
                        <th style="padding:1rem 1.5rem;">Hits</th>
                        <th style="padding:1rem 1.5rem;">Unique IPs</th>
                        <th style="padding:1rem 1.5rem;">Last Activity</th>
                        <th style="padding:1rem 1.5rem;text-align:right;">Bandwidth</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($domains_traffic)): ?>
                        <tr><td colspan="5" style="padding:3rem;text-align:center;color:var(--slate-500);">
                            <div style="display:flex;flex-direction:column;align-items:center;gap:0.5rem;">
                                <i data-lucide="bar-chart-2" style="width:48px;height:48px;opacity:0.5;"></i>
                                <span>No domains found.</span>
                            </div>
                        </td></tr>
                    <?php else: foreach ($domains_traffic as $t):
                        $mb = round($t['total_bytes'] / 1024 / 1024, 2); ?>
                    <tr style="border-bottom:1px solid var(--slate-100);transition:background-color 0.2s;" onmouseover="this.style.backgroundColor='var(--slate-50)'" onmouseout="this.style.backgroundColor='transparent'">
                        <td style="padding:1.25rem 1.5rem;">
                            <div style="font-weight:800;color:var(--slate-900);display:flex;align-items:center;gap:0.5rem;">
                                <i data-lucide="globe" style="width:16px;height:16px;color:var(--slate-400);"></i>
                                <?= htmlspecialchars($t['domain']) ?>
                            </div>
                        </td>
                        <td style="padding:1.25rem 1.5rem;">
                            <?php if ($t['total_hits'] > 0): ?>
                                <span class="badge badge-emerald" style="padding:0.25rem 0.625rem;font-size:0.75rem;"><?= number_format((int)$t['total_hits']) ?></span>
                            <?php else: ?>
                                <span style="color:var(--slate-400);font-size:0.75rem;">No data</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:1.25rem 1.5rem;color:var(--slate-600);font-size:0.85rem;">
                            <?= $t['total_uniques'] > 0 ? number_format((int)$t['total_uniques']) : '<span style="color:var(--slate-400);">—</span>' ?>
                        </td>
                        <td style="padding:1.25rem 1.5rem;font-size:0.8rem;color:var(--slate-500);">
                            <?= $t['last_activity'] ? date('M j, Y', strtotime($t['last_activity'])) : '<span style="color:var(--slate-400);">Never</span>' ?>
                        </td>
                        <td style="padding:1.25rem 1.5rem;text-align:right;font-family:monospace;font-size:0.8125rem;font-weight:500;color:var(--primary);">
                            <?= $mb > 0 ? $mb . ' MB' : '<span style="color:var(--slate-400);">0 MB</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
async function syncTraffic(btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader-2" style="width:1rem;height:1rem;animation:spin 1s linear infinite;display:inline-block;margin-right:0.4rem;"></i>Syncing…`;
    lucide.createIcons();
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'update_stats');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        const res = await fetch('traffic.php', { method: 'POST', body: fd }).then(r => r.json());
        showToast(res.status === 'success' ? 'success' : 'error', res.msg || 'Done');
        if (res.status === 'success') setTimeout(() => location.reload(), 1200);
    } catch(e) {
        showToast('error', 'Failed to connect to server');
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}
</script>

<?php include 'layout/footer.php'; ?>