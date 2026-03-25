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
        if ($action == 'add_ssh') {
            cmd("ssh-key add " . escapeshellarg($username) . " " . escapeshellarg($_POST['key']));
            sendResponse($res);
            exit;
        }
        if ($action == 'del_ssh') {
            cmd("ssh-key delete " . escapeshellarg($username) . " " . (int) $_POST['line']);
            sendResponse($res);
            exit;
        }
        if ($action == 'list_ssh') {
            $out = cmd("ssh-key list " . escapeshellarg($username));
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

<div style="max-width: 56rem; margin-left: auto; margin-right: auto;">
    <h2
        style="font-size: 1.5rem; line-height: 2rem; font-weight: 500; color: var(--slate-900); font-family: 'Lexend', sans-serif; margin-bottom: 2rem;">
        SSH Key Management</h2>

    <div class="glass-panel" style="padding: 2rem; margin-bottom: 2rem;">
        <h3 style="font-weight: 500; color: var(--slate-900); margin-bottom: 1rem;">Add Public Key</h3>
        <form onsubmit="handleGeneric(event, 'add_ssh')" style="display: flex; flex-direction: column; gap: 1rem;">
            <textarea name="key" required placeholder="ssh-rsa AAAA..." rows="4"
                style="width: 100%; background-color: var(--slate-50); border: 1px solid var(--slate-300); padding: 1rem; border-radius: 0.75rem; color: var(--slate-900); font-family: monospace; font-size: 0.75rem; outline: none; transition: border-color 0.2s; resize: vertical;"
                onfocus="this.style.borderColor='#3b82f6'"
                onblur="this.style.borderColor='var(--slate-300)'"></textarea>
            <button class="btn btn-primary"
                style="align-self: flex-start; padding: 0.75rem 1.5rem; font-weight: 500; border-radius: 0.75rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">Add
                Key</button>
        </form>
    </div>

    <div class="glass-panel" style="padding: 2rem;">
        <h3 style="font-weight: 500; color: var(--slate-900); font-size: 1.125rem; margin-bottom: 1.5rem;">Authorized
            Keys</h3>
        <div id="ssh-list" style="display: flex; flex-direction: column; gap: 0.5rem;">
            <div style="display: flex; gap: 1rem; animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;">
                <div
                    style="flex: 1; display: flex; flex-direction: column; gap: 1rem; padding-top: 0.25rem; padding-bottom: 0.25rem;">
                    <div style="height: 1rem; background-color: var(--slate-300); border-radius: 0.25rem; width: 75%;">
                    </div>
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
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            list.innerHTML = '';
            if (res.data && res.data.length > 0) {
                res.data.forEach((line, i) => {
                    list.innerHTML += `
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; background-color: var(--slate-50); border-radius: 0.75rem; border: 1px solid var(--slate-300); margin-bottom: 0.5rem;">
                                <div style="font-family: monospace; font-size: 0.75rem; color: var(--slate-700); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 75%;">${line}</div>
                                <button onclick="handleGeneric(event, 'del_ssh', {line: ${parseInt(line)} })" style="padding: 0.5rem; color: #f87171; border-radius: 0.5rem; transition: background-color 0.2s; border: none; background: transparent; cursor: pointer;" onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'" onmouseout="this.style.backgroundColor='transparent'"><i data-lucide="trash-2" style="width: 1rem; height: 1rem;"></i></button>
                            </div>
                        `;
                });
                lucide.createIcons();
            } else {
                list.innerHTML = '<div style="text-align: center; color: var(--slate-700); padding-top: 1rem; padding-bottom: 1rem;">No SSH keys found.</div>';
            }
        } catch (e) { list.innerHTML = '<div style="text-align: center; color: #f87171; padding-top: 1rem; padding-bottom: 1rem;">Error loading keys.</div>'; }
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
            fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
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