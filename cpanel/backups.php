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
        if ($action == 'create_backup') {
            cmd("shm-manage backup create " . escapeshellarg($username) . " > /dev/null 2>&1 &");
            echo json_encode(['status' => 'success', 'msg' => 'Backup job started.']);
            exit;
        }
        if ($action == 'list_backups') {
            $out = cmd("shm-manage backup list " . escapeshellarg($username));
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
        if ($action == 'del_backup') {
            cmd("shm-manage backup delete " . escapeshellarg($username) . " " . escapeshellarg($_POST['file']));
            echo json_encode(['status' => 'success', 'msg' => 'Backup deleted.']);
            exit;
        }
        if ($action == 'get_backup_status') {
            $status = cmd("shm-manage backup get-status " . escapeshellarg($username));
            echo json_encode(['status' => 'success', 'data' => trim($status)]);
            exit;
        }
        if ($action == 'restore_backup') {
            cmd("shm-manage backup restore " . escapeshellarg($username) . " " . escapeshellarg($_POST['file']) . " > /dev/null 2>&1 &");
            echo json_encode(['status' => 'success', 'msg' => 'Restore initiated.']);
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
    <div>
        <h2 class="text-2xl font-bold text-white">Backups</h2>
        <p class="text-slate-400 text-sm">Create, restore, or manage your account backups.</p>
    </div>
    <button onclick="createBackup()"
        class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-3 rounded-xl font-bold shadow-lg shadow-blue-600/20 flex items-center gap-2 transition">
        <i data-lucide="plus-circle" class="w-4"></i> Create Backup
    </button>
</div>

<!-- BACKUP PROGRESS BANNER -->
<div id="status-banner"
    class="hidden mb-6 p-4 rounded-2xl bg-blue-600/10 border border-blue-500/20 flex items-center gap-4">
    <div class="w-10 h-10 rounded-full bg-blue-600/20 flex items-center justify-center">
        <i data-lucide="loader-2" class="w-5 h-5 text-blue-400 animate-spin"></i>
    </div>
    <div class="flex-1">
        <h4 class="text-sm font-bold text-white uppercase tracking-wider mb-1">Backup in Progress</h4>
        <div class="w-full bg-slate-800 rounded-full h-1.5 mt-2">
            <div id="status-progress" class="bg-blue-500 h-1.5 rounded-full transition-all duration-500"
                style="width: 10%"></div>
        </div>
        <p id="status-text" class="text-[10px] text-slate-400 font-mono mt-2 italic">Initializing...</p>
    </div>
</div>

<div class="glass-card overflow-hidden">
    <table class="w-full text-left">
        <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
            <tr>
                <th class="p-4">Filename</th>
                <th class="p-4">Size</th>
                <th class="p-4">Created On</th>
                <th class="p-4 text-right">Actions</th>
            </tr>
        </thead>
        <tbody id="backup-list" class="divide-y divide-slate-700/50">
            <tr>
                <td class="p-4 text-center text-slate-500" colspan="4">Loading...</td>
            </tr>
        </tbody>
    </table>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    let statusInterval = null;

    async function loadBackups() {
        const list = document.getElementById('backup-list');
        const fd = new FormData(); fd.append('ajax_action', 'list_backups');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';
            if (res.data && res.data.length > 0) {
                res.data.forEach(b => {
                    const safeName = b.name.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
                    list.innerHTML += `
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-bold text-slate-300 text-sm">${safeName}</td>
                            <td class="p-4 text-slate-400 text-xs">${b.size}</td>
                            <td class="p-4 text-slate-400 text-xs">${b.date}</td>
                            <td class="p-4 text-right">
                                <button onclick="restoreBackup('${safeName}')" class="text-blue-400 font-bold text-[10px] uppercase hover:text-white mr-4 transition">Restore</button>
                                <button onclick="deleteBackup('${safeName}')" class="text-rose-400 font-bold text-[10px] uppercase hover:text-white transition">Delete</button>
                            </td>
                        </tr>
                    `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-slate-500">No backups found.</td></tr>';
            }
        } catch (e) { list.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-red-400">Error loading.</td></tr>'; }
    }

    async function createBackup() {
        const fd = new FormData(); fd.append('ajax_action', 'create_backup');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('info', 'Backup Started', 'Job added to queue.');
                checkStatus();
            }
        } catch (e) { showToast('error', 'Failed', 'Server error'); }
    }

    async function deleteBackup(file) {
        if (!confirm(`Are you sure you want to delete ${file}?`)) return;
        const fd = new FormData(); fd.append('ajax_action', 'del_backup'); fd.append('file', file);
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Deleted', 'Backup removed.');
                loadBackups();
            }
        } catch (e) { showToast('error', 'Failed', 'Server error'); }
    }

    async function restoreBackup(file) {
        if (!confirm('Restoring will OVERWRITE current files and databases. This CANNOT be undone. Continue?')) return;
        const fd = new FormData(); fd.append('ajax_action', 'restore_backup'); fd.append('file', file);
        try {
            showToast('info', 'Restoring...', 'System is restoring backup in background.');
            await fetch('', { method: 'POST', body: fd });
        } catch (e) { showToast('error', 'Failed', 'Server error'); }
    }

    async function checkStatus() {
        const banner = document.getElementById('status-banner');
        const progress = document.getElementById('status-progress');
        const text = document.getElementById('status-text');

        const fd = new FormData(); fd.append('ajax_action', 'get_backup_status');
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            const s = res.data;

            if (s === 'idle' || s === 'finished' || s === 'failed') {
                banner.classList.add('hidden');
                if (statusInterval) {
                    clearInterval(statusInterval);
                    statusInterval = null;
                    loadBackups();
                }
                return;
            }

            banner.classList.remove('hidden');
            if (s === 'dumping_db') {
                progress.style.width = '30%';
                text.innerText = 'Dumping databases...';
            } else if (s === 'compressing') {
                progress.style.width = '70%';
                text.innerText = 'Compressing files and database dumps...';
            }

            if (!statusInterval) statusInterval = setInterval(checkStatus, 3000);

        } catch (e) { console.error('Status check error', e); }
    }

    loadBackups();
    checkStatus();
</script>