<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// 1. Central Service Definition Map (Matches shm-manage service names)
$SERVICES_MAP = [
    'nginx'      => 'Web Server',
    'mariadb'    => 'MariaDB SQL',
    'php8.2-fpm' => 'PHP 8.2 Engine',
    'proftpd'    => 'FTP Server',
    'postfix'    => 'Mail Delivery',
    'dovecot'    => 'Mail Inbox (IMAP)'
];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Action processed'];

    try {
        verify_csrf();

        // POLL STATUSES (Hardened)
        if ($action == 'get_service_status') {
            $statuses = [];

            foreach ($SERVICES_MAP as $id => $name) {
                try {
                    // Individually check each service to prevent one failure from crashing the whole update
                    $status = trim(cmd("service-status $id"));
                    $statuses[$id] = [
                        'name' => $name,
                        'active' => $status === 'active',
                        'error' => false
                    ];
                } catch (Throwable $e) {
                    $statuses[$id] = [
                        'name' => $name,
                        'active' => false,
                        'error' => true,
                        'msg' => $e->getMessage()
                    ];
                }
            }

            echo json_encode(['status' => 'success', 'services' => $statuses]);
            exit;
        }

        // CONTROL SERVICES
        if ($action == 'service_action') {
            $op = $_POST['op'];
            $srv = $_POST['service'];

            if (!in_array($op, ['start', 'stop', 'restart', 'reload']))
                throw new Exception("Invalid Operation");

            if (!isset($SERVICES_MAP[$srv]))
                 throw new Exception("Unauthorized Service Context");

            echo json_encode($res);
            if (ob_get_level() > 0)
                ob_end_flush();
            flush();

            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();

            cmd("service-control " . $op . " " . escapeshellarg($srv));
            exit;
        }

        echo json_encode($res);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

include 'layout/header.php';
?>

<h2
    style="font-size: 1.5rem; font-weight: 500; margin-bottom: 2rem; color: var(--text-primary); font-family: var(--font-heading);">
    Service Engine</h2>
<div id="services-container"
    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
    <?php foreach ($SERVICES_MAP as $id => $name): ?>
        <div data-service="<?= $id ?>" class="glass-card animate-slide-up hover-glow"
            style="padding: 1.5rem; border-radius: 1rem; display: flex; justify-content: space-between; align-items: center; transition: border-color var(--transition-normal); border: 1px solid var(--border-color);"
            onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border-color)'">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="position: relative;">
                    <div class="status-dot"
                        style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: var(--text-secondary); box-shadow: 0 0 10px var(--text-secondary);">
                    </div>
                    <div class="status-dot-ping"
                        style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: var(--text-secondary); position: absolute; top: 0; animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite; opacity: 0.75;">
                    </div>
                </div>
                <div>
                    <p style="font-weight: 500; font-size: 1.125rem; color: var(--text-primary); transition: color var(--transition-normal);"
                        onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-primary)'">
                        <?= $name ?>
                    </p>
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                        <p
                            style="font-size: 0.625rem; font-family: monospace; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em;">
                            <?= $id ?>
                        </p>
                        <span class="status-badge"
                            style="padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.5625rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; background: rgba(100, 116, 139, 0.1); color: var(--text-secondary); border: 1px solid rgba(100, 116, 139, 0.2);">
                            CHECKING...
                        </span>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button onclick="servAction('<?= $id ?>','restart')" title="Restart"
                    style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: var(--bg-body); border-radius: 0.75rem; color: var(--primary); border: 1px solid var(--border-color); transition: all var(--transition-normal); box-shadow: var(--shadow-md); cursor: pointer;"
                    onmouseover="this.style.color='white'; this.style.backgroundColor='var(--primary)'; this.style.borderColor='var(--primary)'"
                    onmouseout="this.style.color='var(--primary)'; this.style.backgroundColor='var(--bg-body)'; this.style.borderColor='var(--border-color)'">
                    <i data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i>
                </button>
                <button onclick="servAction('<?= $id ?>','stop')" title="Stop"
                    style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: var(--bg-body); border-radius: 0.75rem; color: var(--accent-red); border: 1px solid var(--border-color); transition: all var(--transition-normal); box-shadow: var(--shadow-md); cursor: pointer;"
                    onmouseover="this.style.color='white'; this.style.backgroundColor='var(--accent-red)'; this.style.borderColor='var(--accent-red)'"
                    onmouseout="this.style.color='var(--accent-red)'; this.style.backgroundColor='var(--bg-body)'; this.style.borderColor='var(--border-color)'">
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

                        if (service.error) {
                            // Error state - orange (Service check failed)
                            dot.style.background = 'var(--accent-amber)';
                            dot.style.boxShadow = '0 0 10px rgba(245, 158, 11, 0.5)';
                            dotPing.style.background = 'var(--accent-amber)';
                            badge.style.background = 'rgba(245, 158, 11, 0.1)';
                            badge.style.color = 'var(--accent-amber)';
                            badge.style.borderColor = 'rgba(245, 158, 11, 0.2)';
                            badge.textContent = 'ERROR';
                            card.title = service.msg;
                        } else if (service.active) {
                            // Active state - green
                            dot.style.background = 'var(--accent-emerald)';
                            dot.style.boxShadow = '0 0 10px rgba(16, 185, 129, 0.5)';
                            dotPing.style.background = 'var(--accent-emerald)';
                            badge.style.background = 'rgba(16, 185, 129, 0.1)';
                            badge.style.color = 'var(--accent-emerald)';
                            badge.style.borderColor = 'rgba(16, 185, 129, 0.2)';
                            badge.textContent = 'ACTIVE';
                            card.title = '';
                        } else {
                            // Inactive state - red
                            dot.style.background = 'var(--accent-red)';
                            dot.style.boxShadow = '0 0 10px rgba(239, 68, 68, 0.5)';
                            dotPing.style.background = 'var(--accent-red)';
                            badge.style.background = 'rgba(239, 68, 68, 0.1)';
                            badge.style.color = 'var(--accent-red)';
                            badge.style.borderColor = 'rgba(239, 68, 68, 0.2)';
                            badge.textContent = 'INACTIVE';
                            card.title = '';
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