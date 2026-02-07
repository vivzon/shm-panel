<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// 1. Determine PHP Service Name (Dynamic)
$php_service = 'php8.2-fpm'; // Fallback
try {
    // Try to get default php version from some system config or just check what's installed
    // For now, we'll keep it as a variable that could be fetched from DB/Config
} catch (Exception $e) {
}

$services = [
    'nginx' => 'Web Server',
    'mariadb' => 'MariaDB SQL',
    $php_service => 'PHP Engine',
    'proftpd' => 'FTP Server',
    'postfix' => 'Mail Delivery'
];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Action processed'];

    try {
        if ($action == 'service_action') {
            $op = $_POST['op'];
            if (!in_array($op, ['start', 'stop', 'restart', 'reload']))
                throw new Exception("Invalid Operation");

            // Execute command
            $output = cmd("service-control " . $op . " " . escapeshellarg($_POST['service']));

            // In a real scenario, we might want to check the output
            echo json_encode(['status' => 'success', 'msg' => "Service $op command sent successfully"]);
            exit;
        }

        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// 2. Batch Status Check (Optimization)
$service_ids = array_keys($services);
$status_output = cmd("service-status-batch " . implode(',', $service_ids));
$statuses = [];
foreach (explode("\n", $status_output) as $line) {
    if (strpos($line, ':') !== false) {
        list($s_id, $s_status) = explode(':', trim($line), 2);
        $statuses[$s_id] = ($s_status === 'active');
    }
}

include 'layout/header.php';
?>

<h2 class="text-2xl font-bold mb-8 text-white font-heading">Service Engine</h2>
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <?php foreach ($services as $id => $name):
        $active = isset($statuses[$id]) ? $statuses[$id] : false; ?>
        <div
            class="glass-panel p-6 rounded-2xl flex justify-between items-center group hover:border-blue-500/30 transition">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div
                        class="w-3 h-3 rounded-full <?= $active ? 'bg-emerald-500 shadow-[0_0_10px_#10b981]' : 'bg-red-500 shadow-[0_0_10px_#ef4444]' ?>">
                    </div>
                    <?php if ($active): ?>
                        <div class="w-3 h-3 rounded-full bg-emerald-500 absolute top-0 animate-ping opacity-75">
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="font-bold text-lg text-white group-hover:text-blue-400 transition">
                        <?= $name ?>
                    </p>
                    <p class="text-[10px] font-mono text-slate-500 uppercase tracking-widest">
                        <?= $id ?>
                    </p>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="servAction(this, '<?= $id ?>','restart')" title="Restart"
                    class="p-3 bg-slate-800 rounded-xl text-blue-400 hover:text-white hover:bg-blue-600 transition-all border border-slate-700 shadow-lg">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                </button>
                <button onclick="servAction(this, '<?= $id ?>','stop')" title="Stop"
                    class="p-3 bg-slate-800 rounded-xl text-red-500 hover:text-white hover:bg-red-600 transition-all border border-slate-700 shadow-lg">
                    <i data-lucide="power" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    async function servAction(btn, srv, op) {
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>';
        lucide.createIcons();

        try {
            const fd = new FormData();
            fd.append('ajax_action', 'service_action');
            fd.append('service', srv);
            fd.append('op', op);

            const response = await fetch('', { method: 'POST', body: fd });
            const data = await response.json();

            if (data.status === 'success') {
                showToast('success', data.msg);
            } else {
                showToast('error', data.msg || 'Action failed');
            }
        } catch (error) {
            showToast('error', 'Network error or server failure');
            console.error(error);
        } finally {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            lucide.createIcons();
        }
    }
</script>