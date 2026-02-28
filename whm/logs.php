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

<div class="flex justify-between items-center mb-6">
    <h2 class="text-2xl font-bold text-white font-heading">Security Monitor</h2>
    <div class="flex gap-2">
        <select id="log-type" onchange="fetchLogs()"
            class="bg-slate-800 text-white p-2 rounded-lg border border-slate-700 text-sm font-bold">
            <option value="auth">Auth Logs (SSH/Sudo)</option>
            <option value="web">Web Server Errors</option>
            <option value="sys">System Log (Syslog)</option>
        </select>
        <button onclick="fetchLogs()"
            class="bg-blue-600 hover:bg-blue-500 text-white p-2 rounded-lg transition shadow-lg"><i
                data-lucide="refresh-cw" class="w-4"></i></button>
    </div>
</div>
<div class="glass-panel p-0 rounded-2xl overflow-hidden shadow-2xl">
    <div class="bg-slate-950 p-3 flex gap-2 border-b border-slate-800">
        <div class="w-3 h-3 rounded-full bg-red-500"></div>
        <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
        <div class="w-3 h-3 rounded-full bg-green-500"></div>
        <div class="ml-auto text-xs font-mono text-slate-500" id="log-time">Last updated: Never</div>
    </div>
    <pre id="log-terminal"
        class="p-6 text-xs font-mono text-emerald-400 bg-[#0a0f1c] h-[600px] overflow-auto whitespace-pre-wrap">Select a log source to view stream...</pre>
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