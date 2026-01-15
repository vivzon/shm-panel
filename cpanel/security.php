<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$username = $_SESSION['client'];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        if ($action == 'add_ssh') {
            cmd("shm-manage ssh-key add " . escapeshellarg($username) . " " . escapeshellarg($_POST['key']));
            sendResponse($res);
            exit;
        }
        if ($action == 'del_ssh') {
            cmd("shm-manage ssh-key delete " . escapeshellarg($username) . " " . (int) $_POST['line']);
            sendResponse($res);
            exit;
        }
        if ($action == 'list_ssh') {
            $out = cmd("shm-manage ssh-key list " . escapeshellarg($username));
            $lines = array_filter(explode("\n", $out));
            echo json_encode(['status' => 'success', 'data' => array_values($lines)]);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

include 'layout/header.php';
?>

<div class="max-w-4xl mx-auto">
    <h2 class="text-2xl font-bold mb-8 text-white">SSH Key Management</h2>

    <div class="glass-card p-8 mb-8">
        <h3 class="font-bold mb-4 text-white">Add Public Key</h3>
        <form onsubmit="handleGeneric(event, 'add_ssh')">
            <textarea name="key" required placeholder="ssh-rsa AAAA..." rows="4"
                class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl outline-none focus:border-blue-500 text-white font-mono text-xs mb-4"></textarea>
            <button
                class="bg-blue-600 text-white px-6 py-3 rounded-xl font-bold shadow-lg hover:bg-blue-500 transition">Add
                Key</button>
        </form>
    </div>

    <div class="glass-card p-8">
        <h3 class="font-bold mb-6 text-white text-lg">Authorized Keys</h3>
        <div id="ssh-list" class="space-y-2">
            <div class="animate-pulse flex space-x-4">
                <div class="flex-1 space-y-4 py-1">
                    <div class="h-4 bg-slate-700 rounded w-3/4"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    async function loadSSH() {
        const list = document.getElementById('ssh-list');
        const fd = new FormData(); fd.append('ajax_action', 'list_ssh');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';
            if (res.data && res.data.length > 0) {
                res.data.forEach((line, i) => {
                    list.innerHTML += `
                            <div class="flex items-center justify-between p-4 bg-slate-900/50 rounded-xl border border-slate-700/50 mb-2">
                                <div class="font-mono text-xs text-slate-300 truncate w-3/4">${line}</div>
                                <button onclick="handleGeneric(event, 'del_ssh', {line: ${parseInt(line)} })" class="p-2 text-red-400 hover:bg-red-500/10 rounded-lg transition"><i data-lucide="trash-2" class="w-4"></i></button>
                            </div>
                        `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<div class="text-center text-slate-500 py-4">No SSH keys found.</div>';
            }
        } catch (e) { list.innerHTML = '<div class="text-center text-red-400">Error loading keys.</div>'; }
    }

    // Override generic handle to reload keys
    const originalHandle = handleGeneric;
    handleGeneric = async function (e, action, data = {}) {
        if (action === 'del_ssh') {
            // Mock Event for delete button click
            e = { target: { querySelector: () => document.createElement('button') }, preventDefault: () => { } };

            if (!confirm('Are you sure?')) return;
            const fd = new FormData();
            fd.append('ajax_action', 'del_ssh');
            fd.append('line', data.line);
            await fetch('', { method: 'POST', body: fd });
            showToast('success', 'Deleted', 'Key deleted.');
            loadSSH();
            return;
        }
        await originalHandle(e, action);
        if (action === 'add_ssh') loadSSH();
    }

    loadSSH();
</script>