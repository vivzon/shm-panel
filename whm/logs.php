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
        if ($action == 'get_logs') {
            $type = $_POST['type'];
            if (!in_array($type, ['auth', 'web', 'sys']))
                throw new Exception("Invalid Log Type");

            $output = cmd("get-logs " . escapeshellarg($type) . " 50");
            echo json_encode(['status' => 'success', 'data' => $output]);
            exit;
        }

        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

include 'layout/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--slate-900); font-family: var(--font-heading);">Security
        Monitor</h2>
    <div style="display: flex; gap: 0.5rem;">
        <select id="log-type" onchange="fetchLogs()" class="form-input"
            style="background: var(--slate-50); color: var(--slate-900); padding: 0.5rem; border-radius: 0.5rem; border: 1px solid var(--border-color); font-size: 0.875rem; font-weight: 700;">
            <option value="auth">Auth Logs (SSH/Sudo)</option>
            <option value="web">Web Server Errors</option>
            <option value="sys">System Log (Syslog)</option>
        </select>
        <button onclick="fetchLogs()" class="btn btn-primary"
            style="padding: 0.5rem; border-radius: 0.5rem; transition: all 0.2s; box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2);"><i
                data-lucide="refresh-cw" style="width: 1rem; height: 1rem;"></i></button>
    </div>
</div>
<div class="glass-card"
    style="padding: 0; border-radius: 1rem; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
    <div
        style="background: white; padding: 0.75rem; display: flex; gap: 0.5rem; border-bottom: 1px solid var(--border-color);">
        <div style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: #ef4444;"></div>
        <div style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: #eab308;"></div>
        <div style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: #22c55e;"></div>
        <div style="margin-left: auto; font-size: 0.75rem; font-family: monospace; color: var(--slate-700);"
            id="log-time">Last updated: Never</div>
    </div>
    <pre id="log-terminal"
        style="padding: 1.5rem; font-size: 0.75rem; font-family: monospace; color: #34d399; background: #0a0f1c; height: 600px; overflow: auto; white-space: pre-wrap;">Select a log source to view stream...</pre>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    let logInterval = null;

    async function fetchLogs() {
        const type = document.getElementById('log-type').value;
        const term = document.getElementById('log-terminal');
        const time = document.getElementById('log-time');

        const fd = new FormData();
        fd.append('ajax_action', 'get_logs');
        fd.append('type', type);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                term.innerText = res.data || 'No logs available or empty.';
                term.scrollTop = term.scrollHeight; // Auto-scroll
                time.innerText = 'Last updated: ' + new Date().toLocaleTimeString();
            }
        } catch (e) { console.error('Log fetch error'); }
    }

    // Auto-start
    fetchLogs();
    logInterval = setInterval(fetchLogs, 3000);
</script>