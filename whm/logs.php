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
        if ($action == 'get_logs') {
            $type = $_POST['type'];
            $lines = (int) ($_POST['lines'] ?? 50);
            if ($lines < 10 || $lines > 1000)
                $lines = 50;

            if (!in_array($type, ['auth', 'web', 'sys']))
                throw new Exception("Invalid Log Type");

            $output = cmd("shm-manage get-logs " . escapeshellarg($type) . " " . $lines);
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

<div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
    <div>
        <h2 class="text-2xl font-bold text-white font-heading">Security Monitor</h2>
        <p class="text-slate-400 text-sm mt-1">Live stream of system security and server logs.</p>
    </div>

    <div class="flex flex-wrap gap-3 w-full md:w-auto">
        <select id="log-type" onchange="fetchLogs()"
            class="bg-slate-900/80 text-white p-2.5 rounded-xl border border-slate-700 text-sm font-semibold outline-none focus:border-blue-500 transition">
            <option value="auth">Auth Logs (SSH/Sudo)</option>
            <option value="web">Web Server Errors</option>
            <option value="sys">System Log (Syslog)</option>
        </select>

        <select id="log-lines" onchange="fetchLogs()"
            class="bg-slate-900/80 text-white p-2.5 rounded-xl border border-slate-700 text-sm font-semibold outline-none focus:border-blue-500 transition">
            <option value="50">Last 50 lines</option>
            <option value="100">Last 100 lines</option>
            <option value="200">Last 200 lines</option>
            <option value="500">Last 500 lines</option>
        </select>

        <button onclick="fetchLogs()" id="refresh-btn"
            class="bg-slate-800 hover:bg-slate-700 text-blue-400 p-2.5 rounded-xl border border-slate-700 transition-all flex items-center justify-center">
            <i data-lucide="refresh-cw" class="w-4 h-4"></i>
        </button>
    </div>
</div>

<div
    class="glass-panel p-0 rounded-2xl overflow-hidden shadow-2xl border border-slate-800 flex flex-col h-[700px] bg-slate-950/50">
    <!-- TOOLBAR -->
    <div class="bg-slate-900/80 p-3 flex flex-wrap items-center gap-4 border-b border-slate-800 backdrop-blur-md">
        <div class="flex gap-1.5 px-2">
            <div class="w-2.5 h-2.5 rounded-full bg-red-500/80"></div>
            <div class="w-2.5 h-2.5 rounded-full bg-yellow-500/80"></div>
            <div class="w-2.5 h-2.5 rounded-full bg-green-500/80"></div>
        </div>

        <div class="flex-1 min-w-[200px] relative">
            <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
            <input type="text" id="log-filter" placeholder="Filter logs..." oninput="applyFilter()"
                class="w-full bg-slate-950/50 text-slate-300 text-xs py-2 pl-9 pr-4 rounded-lg border border-slate-800 outline-none focus:border-blue-500/50 transition">
        </div>

        <div class="flex items-center gap-2">
            <button onclick="toggleAutoRefresh()" id="pause-btn"
                class="flex items-center gap-2 px-3 py-1.5 text-[10px] font-bold uppercase rounded-lg border border-emerald-500/30 bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 transition">
                <i data-lucide="pause" class="w-3 h-3"></i> <span>Live</span>
            </button>
            <button onclick="clearTerminal()"
                class="flex items-center gap-2 px-3 py-1.5 text-[10px] font-bold uppercase rounded-lg border border-slate-700 text-slate-400 hover:text-white hover:bg-slate-800 transition">
                <i data-lucide="trash-2" class="w-3 h-3"></i> Clear
            </button>
            <label class="flex items-center gap-2 cursor-pointer group">
                <input type="checkbox" id="lock-scroll" checked class="hidden peer">
                <div
                    class="w-4 h-4 rounded border border-slate-700 peer-checked:bg-blue-600 peer-checked:border-blue-600 flex items-center justify-center transition group-hover:border-slate-500">
                    <i data-lucide="check" class="w-3 h-3 text-white"></i>
                </div>
                <span class="text-[10px] font-bold text-slate-500 uppercase select-none">Scroll Lock</span>
            </label>
        </div>

        <div class="ml-auto text-[10px] font-mono text-slate-500" id="log-time">Updated: Never</div>
    </div>

    <!-- TERMINAL VIEW -->
    <div id="log-terminal"
        class="flex-1 p-6 text-[11px] font-mono bg-[#0a0f1c] overflow-y-auto overflow-x-hidden whitespace-pre-wrap selection:bg-blue-500/30 leading-relaxed custom-scrollbar">
        <div class="text-slate-600 animate-pulse">Initializing terminal connection...</div>
    </div>
</div>

<style>
    .ansi-31 {
        color: #ff5555;
        font-weight: bold;
    }

    .ansi-32 {
        color: #50fa7b;
    }

    .ansi-33 {
        color: #f1fa8c;
    }

    .ansi-34 {
        color: #8be9fd;
    }

    .ansi-35 {
        color: #ff79c6;
    }

    .ansi-36 {
        color: #8be9fd;
    }

    .ansi-1 {
        font-weight: bold;
    }

    .log-entry {
        margin-bottom: 2px;
    }

    .log-entry.hidden {
        display: none;
    }
</style>

<?php include 'layout/footer.php'; ?>

<script>
    let logInterval = null;
    let isPaused = false;
    let lastRawData = "";

    function ansiToHtml(text) {
        if (!text) return "";
        let html = text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

        // Basic ANSI escape sequence replacement
        const regex = /\033\[([0-9;]+)m/g;
        let result = html.replace(regex, (match, p1) => {
            const codes = p1.split(';');
            let classes = codes.map(c => 'ansi-' + c).join(' ');
            return `<span class="${classes}">`;
        });

        // Count opened spans and close them at the end of reset
        let openSpans = (result.match(/<span/g) || []).length;
        let closedSpans = (result.match(/<\/span/g) || []).length;

        // Crude way to handle reset \033[0m
        result = result.replace(/\033\[0m/g, "</span>".repeat(openSpans));

        return result;
    }

    async function fetchLogs(isAuto = false) {
        if (isPaused && isAuto) return;

        const type = document.getElementById('log-type').value;
        const lines = document.getElementById('log-lines').value;
        const term = document.getElementById('log-terminal');
        const time = document.getElementById('log-time');
        const refreshBtn = document.getElementById('refresh-btn');

        if (!isAuto) refreshBtn.classList.add('animate-spin');

        const fd = new FormData();
        fd.append('ajax_action', 'get_logs');
        fd.append('type', type);
        fd.append('lines', lines);

        try {
            const response = await fetch('', { method: 'POST', body: fd });
            const res = await response.json();

            if (res.status === 'success') {
                if (res.data === lastRawData && isAuto) return;
                lastRawData = res.data;

                const linesArr = (res.data || '').split('\n');
                term.innerHTML = linesArr.map(line => `<div class="log-entry">${ansiToHtml(line)}</div>`).join('');

                applyFilter();

                if (document.getElementById('lock-scroll').checked) {
                    term.scrollTop = term.scrollHeight;
                }

                time.innerText = 'Updated: ' + new Date().toLocaleTimeString();
            } else {
                term.innerHTML = `<div class="text-red-500 font-bold">Error: ${res.msg}</div>`;
            }
        } catch (e) {
            console.error('Log fetch error', e);
            term.innerHTML += `<div class="text-red-500 mt-2 px-2 py-1 bg-red-500/10 border border-red-500/20 rounded">Lost connection to server. Retrying...</div>`;
        } finally {
            if (!isAuto) setTimeout(() => refreshBtn.classList.remove('animate-spin'), 300);
        }
    }

    function applyFilter() {
        const query = document.getElementById('log-filter').value.toLowerCase();
        const entries = document.querySelectorAll('.log-entry');

        entries.forEach(entry => {
            if (!query || entry.innerText.toLowerCase().includes(query)) {
                entry.classList.remove('hidden');
                if (query) {
                    // Highlight logic could go here
                }
            } else {
                entry.classList.add('hidden');
            }
        });
    }

    function toggleAutoRefresh() {
        isPaused = !isPaused;
        const btn = document.getElementById('pause-btn');
        const span = btn.querySelector('span');
        const icon = btn.querySelector('i');

        if (isPaused) {
            btn.classList.replace('bg-emerald-500/10', 'bg-yellow-500/10');
            btn.classList.replace('text-emerald-400', 'text-yellow-400');
            btn.classList.replace('border-emerald-500/30', 'border-yellow-500/30');
            span.innerText = "Paused";
            icon.setAttribute('data-lucide', 'play');
        } else {
            btn.classList.replace('bg-yellow-500/10', 'bg-emerald-500/10');
            btn.classList.replace('text-yellow-400', 'text-emerald-400');
            btn.classList.replace('border-yellow-500/30', 'border-emerald-500/30');
            span.innerText = "Live";
            icon.setAttribute('data-lucide', 'pause');
            fetchLogs();
        }
        lucide.createIcons();
    }

    function clearTerminal() {
        document.getElementById('log-terminal').innerHTML = '<div class="text-slate-600 font-italic">[Terminal Cleared]</div>';
        lastRawData = "";
    }

    // Auto-start
    fetchLogs();
    logInterval = setInterval(() => fetchLogs(true), 3000);
</script>