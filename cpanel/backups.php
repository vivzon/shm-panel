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

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <h2
        style="font-size: 1.5rem; line-height: 2rem; font-weight: 700; color: var(--slate-900); font-family: 'Lexend', sans-serif;">
        Backups</h2>
    <form onsubmit="handleGeneric(event, 'create_backup')">
        <button class="btn btn-primary"
            style="padding: 0.75rem 1.25rem; font-weight: 700; border-radius: 0.75rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="plus-circle" style="width: 1rem; height: 1rem;"></i> Create Backup
        </button>
    </form>
</div>

<div class="glass-panel" style="padding: 0; overflow: hidden;">
    <div class="table-container">
        <table class="modern-table" style="width: 100%; text-align: left;">
            <thead
                style="background-color: var(--slate-50); font-size: 0.625rem; font-weight: 700; text-transform: uppercase; color: var(--slate-700); letter-spacing: 0.05em; border-bottom: 1px solid var(--slate-300);">
                <tr>
                    <th style="padding: 1rem;">Filename</th>
                    <th style="padding: 1rem;">Size</th>
                    <th style="padding: 1rem; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="backup-list">
                <tr>
                    <td style="padding: 1rem; text-align: center; color: var(--slate-700);" colspan="3">Loading...</td>
                </tr>
            </tbody>
        </table>
    </div>
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
                            <tr style="border-bottom: 1px solid var(--slate-200); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='rgba(248, 250, 252, 0.5)'" onmouseout="this.style.backgroundColor='transparent'">
                                <td style="padding: 1rem; font-weight: 700; color: var(--slate-700);">${safeName}</td>
                                <td style="padding: 1rem; color: var(--slate-700); font-size: 0.75rem;">${b.size}</td>
                                <td style="padding: 1rem; text-align: right;">
                                    <button onclick="restoreBackup('${safeName}')" style="color: #60a5fa; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; margin-right: 1rem; transition: color 0.2s; background: transparent; border: none; cursor: pointer;" onmouseover="this.style.color='var(--slate-900)'" onmouseout="this.style.color='#60a5fa'">Restore</button>
                                </td>
                            </tr>
                        `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<tr><td colspan="3" style="padding: 1rem; text-align: center; color: var(--slate-700);">No backups found.</td></tr>';
            }
        } catch (e) { list.innerHTML = '<tr><td colspan="3" style="padding: 1rem; text-align: center; color: #f87171;">Error loading.</td></tr>'; }
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