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

<h2
    style="font-size: 1.5rem; font-weight: 700; margin-bottom: 2rem; color: var(--slate-900); font-family: var(--font-heading);">
    Service Engine</h2>
<div id="services-container"
    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
    <?php foreach ($services as $id => $name): ?>
        <div data-service="<?= $id ?>" class="glass-card"
            style="padding: 1.5rem; border-radius: 1rem; display: flex; justify-content: space-between; align-items: center; transition: border-color 0.2s; border: 1px solid var(--border-color);"
            onmouseover="this.style.borderColor='rgba(59, 130, 246, 0.3)'"
            onmouseout="this.style.borderColor='var(--border-color)'">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="position: relative;">
                    <div class="status-dot"
                        style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: var(--slate-500); box-shadow: 0 0 10px #64748b;">
                    </div>
                    <div class="status-dot-ping"
                        style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: var(--slate-500); position: absolute; top: 0; animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite; opacity: 0.75;">
                    </div>
                </div>
                <div>
                    <p style="font-weight: 700; font-size: 1.125rem; color: var(--slate-900); transition: color 0.2s;"
                        onmouseover="this.style.color='#60a5fa'" onmouseout="this.style.color='var(--slate-900)'">
                        <?= $name ?>
                    </p>
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                        <p
                            style="font-size: 0.625rem; font-family: monospace; color: var(--slate-700); text-transform: uppercase; letter-spacing: 0.1em;">
                            <?= $id ?>
                        </p>
                        <span class="status-badge"
                            style="padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.5625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; background: rgba(100, 116, 139, 0.1); color: var(--slate-700); border: 1px solid rgba(100, 116, 139, 0.2);">
                            CHECKING...
                        </span>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button onclick="servAction('<?= $id ?>','restart')" title="Restart"
                    style="padding: 0.75rem; background: var(--slate-50); border-radius: 0.75rem; color: #60a5fa; border: 1px solid var(--slate-300); transition: all 0.2s; box-shadow: var(--shadow-lg); cursor: pointer;"
                    onmouseover="this.style.color='var(--slate-900)'; this.style.backgroundColor='#2563eb'"
                    onmouseout="this.style.color='#60a5fa'; this.style.backgroundColor='var(--slate-50)'">
                    <i data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i>
                </button>
                <button onclick="servAction('<?= $id ?>','stop')" title="Stop"
                    style="padding: 0.75rem; background: var(--slate-50); border-radius: 0.75rem; color: #ef4444; border: 1px solid var(--slate-300); transition: all 0.2s; box-shadow: var(--shadow-lg); cursor: pointer;"
                    onmouseover="this.style.color='var(--slate-900)'; this.style.backgroundColor='#dc2626'"
                    onmouseout="this.style.color='#ef4444'; this.style.backgroundColor='var(--slate-50)'">
                    <i data-lucide="power" style="width: 1rem; height: 1rem;"></i>
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
                            dot.style.background = '#10b981';
                            dot.style.boxShadow = '0 0 10px #10b981';
                            dotPing.style.background = '#10b981';
                            badge.style.background = 'rgba(16, 185, 129, 0.1)';
                            badge.style.color = '#10b981';
                            badge.style.borderColor = 'rgba(16, 185, 129, 0.2)';
                            badge.textContent = 'ACTIVE';
                        } else {
                            // Inactive state - red
                            dot.style.background = '#ef4444';
                            dot.style.boxShadow = '0 0 10px #ef4444';
                            dotPing.style.background = '#ef4444';
                            badge.style.background = 'rgba(239, 68, 68, 0.1)';
                            badge.style.color = '#ef4444';
                            badge.style.borderColor = 'rgba(239, 68, 68, 0.2)';
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
        const buttons = event.target.closest('.glass-card').querySelectorAll('button');
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