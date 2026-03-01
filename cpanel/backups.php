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
        verify_csrf();
        if ($action == 'create_backup') {
            cmd("backup create " . escapeshellarg($username));
            sendResponse($res);
            exit;
        }
        if ($action == 'list_backups') {
            $out = cmd("backup list " . escapeshellarg($username));
            $backups = [];
            foreach (explode("\n", $out) as $line) {
                if (!trim($line))
                    continue;
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 5) {
                    $backups[] = [
                        'name' => end($parts),
                        'size' => $parts[0],
                        'date' => $parts[1] . ' ' . $parts[2] . ' ' . $parts[3]
                    ];
                }
            }
            echo json_encode(['status' => 'success', 'data' => $backups]);
            exit;
        }
        if ($action == 'restore_backup') {
            cmd("backup restore " . escapeshellarg($username) . " " . escapeshellarg($_POST['file']));
            sendResponse($res);
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

<div class="flex justify-between items-center mb-8">
    <h2 class="text-2xl font-bold text-slate-900">Backups</h2>
    <form onsubmit="handleGeneric(event, 'create_backup')">
        <button
            class="bg-blue-600 hover:bg-blue-500 text-slate-900 px-5 py-3 rounded-xl font-bold shadow-lg shadow-blue-600/20 flex items-center gap-2 transition">
            <i data-lucide="plus-circle" class="w-4"></i> Create Backup
        </button>
    </form>
</div>

<div class="glass-card overflow-hidden">
    <table class="w-full text-left">
        <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-700">
            <tr>
                <th class="p-4">Filename</th>
                <th class="p-4">Size</th>
                <th class="p-4 text-right">Actions</th>
            </tr>
        </thead>
        <tbody id="backup-list" class="divide-y divide-slate-700/50">
            <tr>
                <td class="p-4 text-center text-slate-700" colspan="3">Loading...</td>
            </tr>
        </tbody>
    </table>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    async function loadBackups() {
        const list = document.getElementById('backup-list');
        const fd = new FormData(); fd.append('ajax_action', 'list_backups');
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';
            if (res.data && res.data.length > 0) {
                res.data.forEach(b => {
                    const safeName = b.name.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
                    list.innerHTML += `
                            <tr class="hover:bg-slate-50/30 transition">
                                <td class="p-4 font-bold text-slate-700">${safeName}</td>
                                <td class="p-4 text-slate-700 text-xs">${b.size}</td>
                                <td class="p-4 text-right">
                                    <button onclick="restoreBackup('${safeName}')" class="text-blue-400 font-bold text-xs uppercase hover:text-slate-900 mr-4 transition">Restore</button>
                                </td>
                            </tr>
                        `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-slate-700">No backups found.</td></tr>';
            }
        } catch (e) { list.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-red-400">Error loading.</td></tr>'; }
    }

    async function restoreBackup(file) {
        if (!confirm('Restoring will overwrite current files and DBs. Continue?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'restore_backup');
        fd.append('file', file);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        showToast('info', 'Processing...', 'Restore job started.');
        await fetch('', { method: 'POST', body: fd });
        showToast('success', 'Restore Initiated', 'System is restoring backup.');
    }

    // Hook into generic handler to reload list on create
    const originalHandle = handleGeneric;
    handleGeneric = async function (e, action) {
        await originalHandle(e, action);
        if (action === 'create_backup') setTimeout(loadBackups, 2000);
    }

    loadBackups();
</script>


