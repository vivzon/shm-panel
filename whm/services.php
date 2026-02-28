<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Action processed'];

    try {
        verify_csrf();
        if ($action == 'get_service_status') {
            $services = ['nginx' => 'Web Server', 'mariadb' => 'MariaDB SQL', 'php8.2-fpm' => 'PHP 8.2 Engine', 'proftpd' => 'FTP Server', 'postfix' => 'Mail Delivery'];
            $statuses = [];

            foreach ($services as $id => $name) {
                $status = trim(cmd("service-status $id"));
                $statuses[$id] = [
                    'name' => $name,
                    'active' => $status === 'active'
                ];
            }

            echo json_encode(['status' => 'success', 'services' => $statuses]);
            exit;
        }

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

$services = ['nginx' => 'Web Server', 'mariadb' => 'MariaDB SQL', 'php8.2-fpm' => 'PHP 8.2 Engine', 'proftpd' => 'FTP Server', 'postfix' => 'Mail Delivery'];

include 'layout/header.php';
?>

<h2 class="text-2xl font-bold mb-8 text-white font-heading">Service Engine</h2>
<div id="services-container" class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <?php foreach ($services as $id => $name): ?>
        <div data-service="<?= $id ?>"
            class="glass-panel p-6 rounded-2xl flex justify-between items-center group hover:border-blue-500/30 transition">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="status-dot w-3 h-3 rounded-full bg-slate-500 shadow-[0_0_10px_#64748b]">
                    </div>
                    <div class="status-dot-ping w-3 h-3 rounded-full bg-slate-500 absolute top-0 animate-ping opacity-75">
                    </div>
                </div>
                <div>
                    <p class="font-bold text-lg text-white group-hover:text-blue-400 transition">
                        <?= $name ?>
                    </p>
                    <div class="flex items-center gap-2 mt-1">
                        <p class="text-[10px] font-mono text-slate-500 uppercase tracking-widest">
                            <?= $id ?>
                        </p>
                        <span
                            class="status-badge px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-slate-500/10 text-slate-400 border border-slate-500/20">
                            CHECKING...
                        </span>
                    </div>
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

<?php include 'layout/footer.php'; ?>

<script>
    // Fetch and update service status
    async function updateServiceStatus() {
        const fd = new FormData();
        fd.append('ajax_action', 'get_service_status');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        try {
            const res = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.status === 'success') {
                Object.keys(data.services).forEach(serviceId => {
                    const service = data.services[serviceId];
                    const card = document.querySelector(`[data-service="${serviceId}"]`);

                    if (card) {
                        const dot = card.querySelector('.status-dot');
                        const dotPing = card.querySelector('.status-dot-ping');
                        const badge = card.querySelector('.status-badge');

                        if (service.active) {
                            // Active state - green
                            dot.className = 'status-dot w-3 h-3 rounded-full bg-emerald-500 shadow-[0_0_10px_#10b981]';
                            dotPing.className = 'status-dot-ping w-3 h-3 rounded-full bg-emerald-500 absolute top-0 animate-ping opacity-75';
                            badge.className = 'status-badge px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
                            badge.textContent = 'ACTIVE';
                        } else {
                            // Inactive state - red
                            dot.className = 'status-dot w-3 h-3 rounded-full bg-red-500 shadow-[0_0_10px_#ef4444]';
                            dotPing.className = 'status-dot-ping w-3 h-3 rounded-full bg-red-500 absolute top-0 animate-ping opacity-75';
                            badge.className = 'status-badge px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-red-500/10 text-red-400 border border-red-500/20';
                            badge.textContent = 'INACTIVE';
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Failed to fetch service status:', error);
        }
    }

    async function servAction(srv, op) {
        // Find all buttons for this service and disable them
        const buttons = event.target.closest('.glass-panel').querySelectorAll('button');
        buttons.forEach(btn => {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'wait';
        });

        showToast('info', `${op.charAt(0).toUpperCase() + op.slice(1)}ing ${srv}...`);

        const fd = new FormData();
        fd.append('ajax_action', 'service_action');
        fd.append('service', srv);
        fd.append('op', op);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        try {
            const res = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.status === 'success') {
                showToast('success', `Service ${op} command completed successfully`);
                // Immediately refresh status
                setTimeout(() => updateServiceStatus(), 1000);
                // Re-enable buttons after 3 seconds
                setTimeout(() => {
                    buttons.forEach(btn => {
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        btn.style.cursor = 'pointer';
                    });
                }, 3000);
            } else {
                showToast('error', data.msg || 'Service action failed');
                // Re-enable buttons on error
                buttons.forEach(btn => {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.style.cursor = 'pointer';
                });
            }
        } catch (error) {
            showToast('error', 'Failed to execute service command');
            // Re-enable buttons on error
            buttons.forEach(btn => {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
            });
        }
    }

    // Initial load and auto-refresh every 5 seconds
    document.addEventListener('DOMContentLoaded', () => {
        updateServiceStatus();
        setInterval(updateServiceStatus, 5000);
    });
</script>