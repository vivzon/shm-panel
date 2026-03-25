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
    <h2 style="font-size: 1.5rem; font-weight: 500; color: var(--text-primary); font-family: var(--font-heading);">
        Security
        Monitor</h2>
    <div style="display: flex; gap: 0.5rem;">
        <select id="log-type" onchange="fetchLogs()" class="form-input"
            style="background: var(--bg-body); color: var(--text-primary); padding: 0.5rem; border-radius: 0.5rem; border: 1px solid var(--border-color); font-size: 0.875rem; font-weight: 500;">
            <option value="auth">Auth Logs (SSH/Sudo)</option>
            <option value="web">Web Server Errors</option>
            <option value="sys">System Log (Syslog)</option>
        </select>
        <button id="pause-btn" onclick="togglePause()" class="btn" title="Pause/Resume"
            style="background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-primary); padding: 0.5rem; border-radius: 0.5rem; transition: all var(--transition-normal);">
            <i data-lucide="pause" style="width: 1rem; height: 1rem;"></i>
        </button>
        <button onclick="clearTerminal()" class="btn" title="Clear Terminal"
            style="background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-primary); padding: 0.5rem; border-radius: 0.5rem; transition: all var(--transition-normal);">
            <i data-lucide="trash-2" style="width: 1rem; height: 1rem;"></i>
        </button>
        <button onclick="fetchLogs()" class="btn btn-primary"
            style="padding: 0.5rem; border-radius: 0.5rem; transition: all var(--transition-normal); box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2);">
            <i data-lucide="refresh-cw" id="refresh-icon" style="width: 1rem; height: 1rem;"></i>
        </button>
    </div>
</div>
<div class="glass-card animate-slide-right hover-glow"
    style="padding: 0; border-radius: 1rem; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid var(--border-color);">
    <div
        style="background: var(--bg-surface); padding: 0.75rem; display: flex; gap: 0.5rem; border-bottom: 1px solid var(--border-color);">
        <div style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: var(--accent-red);"></div>
        <div style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: #eab308;"></div>
        <div style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background: var(--accent-emerald);"></div>
        <div style="margin-left: auto; font-size: 0.75rem; font-family: monospace; color: var(--text-secondary);"
            id="log-time">Last updated: Never</div>
    </div>
    <pre id="log-terminal" class="custom-scrollbar"
        style="padding: 1.5rem; font-size: 0.75rem; font-family: 'Fira Code', monospace; color: var(--accent-emerald); background: #0a0f1c; height: 600px; overflow: auto; white-space: pre-wrap; line-height: 1.5;">Select a log source to view stream...</pre>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    let logInterval = null;
    let isPaused = false;

    function togglePause() {
        isPaused = !isPaused;
        const btn = document.getElementById('pause-btn');
        btn.innerHTML = isPaused ? '<i data-lucide="play" style="width: 1rem; height: 1rem;"></i>' : '<i data-lucide="pause" style="width: 1rem; height: 1rem;"></i>';
        btn.style.color = isPaused ? 'var(--accent-emerald)' : 'var(--text-primary)';
        lucide.createIcons();
        
        if (typeof showToast !== 'undefined') {
            showToast(isPaused ? 'info' : 'success', isPaused ? 'Paused' : 'Resumed', isPaused ? 'Auto-refresh disabled' : 'Auto-refresh enabled');
        }
    }

    function clearTerminal() {
        document.getElementById('log-terminal').textContent = 'Terminal cleared. Waiting for next update...';
    }

    async function fetchLogs() {
        if (isPaused) return;

        const type = document.getElementById('log-type').value;
        const term = document.getElementById('log-terminal');
        const time = document.getElementById('log-time');
        const refreshIcon = document.getElementById('refresh-icon');

        if (refreshIcon) refreshIcon.classList.add('animate-spin');

        const fd = new FormData();
        fd.append('ajax_action', 'get_logs');
        fd.append('type', type);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                term.textContent = res.data || 'No logs available or empty.';
                term.scrollTop = term.scrollHeight; // Auto-scroll
                time.innerText = 'Last updated: ' + new Date().toLocaleTimeString();
            }
        } catch (e) { 
            console.error('Log fetch error'); 
        } finally {
            if (refreshIcon) refreshIcon.classList.remove('animate-spin');
        }
    }

    // Initial Load
    fetchLogs();
    logInterval = setInterval(fetchLogs, 3000);

    // Cleanup on unmount (if in SPA-like environment, though here it's simple redirect)
    window.addEventListener('beforeunload', () => {
        if (logInterval) clearInterval(logInterval);
    });
</script>