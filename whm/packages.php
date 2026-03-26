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
        if ($action == 'save_package') {
            $id = $_POST['id'] ?? null;
            $name = shm_clean($_POST['name'] ?? '');
            if (empty($name))
                throw new Exception("Package name is required.");

            // Check Duplicate Name
            $chk = $pdo->prepare("SELECT id FROM packages WHERE name = ? AND id != ?");
            $chk->execute([$name, $id ?: 0]);
            if ($chk->fetch())
                throw new Exception("Package '$name' already exists.");

            $vals = [$name, (int) $_POST['disk'], (int) $_POST['doms'], (int) $_POST['mails']];
            if ($id) {
                // Manual merge for compatibility
                $update_vals = $vals;
                $update_vals[] = $id;
                $pdo->prepare("UPDATE packages SET name=?, disk_mb=?, max_domains=?, max_emails=? WHERE id=?")->execute($update_vals);
            } else {
                $pdo->prepare("INSERT INTO packages (name, disk_mb, max_domains, max_emails) VALUES (?,?,?,?)")->execute($vals);
            }
        }

        if ($action == 'delete_package') {
            $pdo->prepare("DELETE FROM packages WHERE id = ?")->execute([$_POST['id']]);
        }

        sendResponse($res);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

$packages = $pdo->query("SELECT * FROM packages")->fetchAll(PDO::FETCH_ASSOC);

include 'layout/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <h2 style="font-size: 1.5rem; font-weight: 500; color: var(--text-primary); font-family: var(--font-heading);">
        Service Packages</h2>
    <button onclick="openPkgModal()" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem;">
        <i data-lucide="plus" style="width: 1rem; height: 1rem;"></i> Add Package
    </button>
</div>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
    <?php foreach ($packages as $p): ?>
        <div class="glass-card animate-slide-right hover-glow"
            style="padding: 1.5rem; border-radius: 1rem; position: relative; transition: all var(--transition-normal); border: 1px solid var(--border-color);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                <h3 style="font-size: 1.125rem; font-weight: 500; color: var(--text-primary);">
                    <?= $p['name'] ?>
                </h3>
                <div
                    style="padding: 0.5rem; background: var(--bg-body); border-radius: 0.5rem; color: var(--text-secondary);">
                    <i data-lucide="box" style="width: 1rem; height: 1rem;"></i>
                </div>
            </div>
            <div
                style="display: flex; flex-direction: column; gap: 1rem; font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 2rem; font-weight: 500;">
                <div
                    style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem; border-radius: 0.5rem; background: var(--bg-body); border: 1px solid var(--border-color);">
                    <i data-lucide="hard-drive" style="width: 1rem; height: 1rem; color: var(--primary);"></i>
                    <?= $p['disk_mb'] ?> MB Storage
                </div>
                <div
                    style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem; border-radius: 0.5rem; background: var(--bg-body); border: 1px solid var(--border-color);">
                    <i data-lucide="globe" style="width: 1rem; height: 1rem; color: var(--accent-emerald);"></i>
                    <?= $p['max_domains'] ?> Domains
                </div>
                <div
                    style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem; border-radius: 0.5rem; background: var(--bg-body); border: 1px solid var(--border-color);">
                    <i data-lucide="mail" style="width: 1rem; height: 1rem; color: var(--accent-purple);"></i>
                    <?= $p['max_emails'] ?> Emails
                </div>
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <button onclick='openPkgModal(<?= json_encode($p) ?>)' class="btn btn-outline"
                    style="flex: 1; padding: 0.625rem; font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em; border-radius: 0.75rem;">Edit</button>
                <button onclick="delPkg(<?= $p['id'] ?>)"
                    style="background: rgba(239, 68, 68, 0.1); color: var(--accent-red); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 0.75rem; padding: 0.625rem; transition: background-color 0.2s;display: flex; align-items: center; justify-content: center;"
                    onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.2)'"
                    onmouseout="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'"><i data-lucide="trash-2"
                        style="width: 1rem; height: 1rem;"></i></button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- PACKAGE MODAL -->
<div id="modal-pkg" class="modal hidden"
    style="position: fixed; inset: 0; background: var(--bg-glass); backdrop-filter: blur(12px); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 1.5rem; transition: all var(--transition-normal);">
    <form id="form-pkg" onsubmit="handleGeneric(event, 'save_package')" class="glass-card animate-slide-right"
        style="padding: 2.5rem; border-radius: 1.5rem; width: 100%; max-width: 28rem; position: relative; background: var(--bg-surface);">
        <h3 id="pkg-title"
            style="font-size: 1.5rem; font-weight: 500; margin-bottom: 2rem; color: var(--text-primary); font-family: var(--font-heading);">
            Plan Configuration</h3>
        <input type="hidden" name="id" id="pkg-id">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            <input name="name" id="pkg-name" placeholder="Package Name" required class="form-input"
                style="width: 100%; padding: 1rem; border-radius: 0.75rem;">

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <label
                        style="font-size: 0.625rem; font-weight: 500; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.25rem;">Disk</label>
                    <input name="disk" id="pkg-disk" type="number" placeholder="MB" required class="form-input"
                        style="width: 100%; padding: 1rem; border-radius: 0.75rem; text-align: center;">
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <label
                        style="font-size: 0.625rem; font-weight: 500; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.25rem;">Doms</label>
                    <input name="doms" id="pkg-doms" type="number" placeholder="#" required class="form-input"
                        style="width: 100%; padding: 1rem; border-radius: 0.75rem; text-align: center;">
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <label
                        style="font-size: 0.625rem; font-weight: 500; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.25rem;">Mail</label>
                    <input name="mails" id="pkg-mails" type="number" placeholder="#" required class="form-input"
                        style="width: 100%; padding: 1rem; border-radius: 0.75rem; text-align: center;">
                </div>
            </div>

            <div style="display: flex; gap: 1rem; padding-top: 1rem;">
                <button type="button" onclick="closeModal('modal-pkg')" class="btn btn-outline"
                    style="flex: 1; padding: 1rem; border-radius: 0.75rem;">Cancel</button>
                <button type="submit" class="btn btn-primary"
                    style="flex: 1; padding: 1rem; border-radius: 0.75rem;">Save
                    Plan</button>
            </div>
        </div>
    </form>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    function openPkgModal(data = null) {
        const f = document.getElementById('form-pkg'); f.reset();
        const title = document.getElementById('pkg-title');
        if (data) {
            document.getElementById('pkg-id').value = data.id;
            document.getElementById('pkg-name').value = data.name;
            document.getElementById('pkg-disk').value = data.disk_mb;
            document.getElementById('pkg-doms').value = data.max_domains;
            document.getElementById('pkg-mails').value = data.max_emails;
        } else {
            document.getElementById('pkg-id').value = "";
        }
        document.getElementById('modal-pkg').classList.remove('hidden');
    }

    async function delPkg(id) {
        if (!confirm('Delete this package?')) return;
        
        // Create a dummy form to utilize handleGeneric for consistent toasts and CSRF appending
        const dummyForm = document.createElement('form');
        dummyForm.innerHTML = `<input type="hidden" name="id" value="${id}"><button type="submit"></button>`;
        dummyForm.onsubmit = (e) => handleGeneric(e, 'delete_package');
        
        // Trigger submission
        dummyForm.querySelector('button').click();
    }
</script>