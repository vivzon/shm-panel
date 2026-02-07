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
        if ($action == 'save_package') {
            $id = $_POST['id'] ?? null;
            $name = trim($_POST['name']);
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
            $id = (int) $_POST['id'];
            // Check if any clients are using this package
            $chk = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE package_id = ?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                throw new Exception("Cannot delete package: It is currently assigned to one or more clients.");
            }
            $pdo->prepare("DELETE FROM packages WHERE id = ?")->execute([$id]);
        }

        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

$packages = $pdo->query("SELECT * FROM packages")->fetchAll(PDO::FETCH_ASSOC);

include 'layout/header.php';
?>

<div class="flex justify-between items-center mb-8">
    <h2 class="text-2xl font-bold text-white font-heading">Service Packages</h2>
    <button onclick="openPkgModal()"
        class="bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-2.5 rounded-xl font-bold shadow-lg shadow-emerald-900/20 text-sm flex items-center gap-2 transition border border-emerald-500/50">
        <i data-lucide="plus" class="w-4"></i> Add Package
    </button>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <?php foreach ($packages as $p): ?>
        <div class="glass-panel p-6 rounded-2xl relative group hover:border-slate-600 transition">
            <div class="flex justify-between items-start mb-6">
                <h3 class="text-lg font-bold text-white">
                    <?= $p['name'] ?>
                </h3>
                <div class="p-2 bg-slate-800 rounded-lg text-slate-400"><i data-lucide="box" class="w-4"></i></div>
            </div>
            <div class="space-y-4 text-sm text-slate-400 mb-8 font-medium">
                <div class="flex items-center gap-3 p-2 rounded-lg bg-slate-900/30 border border-slate-800/50">
                    <i data-lucide="hard-drive" class="w-4 text-blue-400"></i>
                    <?= $p['disk_mb'] ?> MB
                    Storage
                </div>
                <div class="flex items-center gap-3 p-2 rounded-lg bg-slate-900/30 border border-slate-800/50">
                    <i data-lucide="globe" class="w-4 text-emerald-400"></i>
                    <?= $p['max_domains'] ?>
                    Domains
                </div>
                <div class="flex items-center gap-3 p-2 rounded-lg bg-slate-900/30 border border-slate-800/50">
                    <i data-lucide="mail" class="w-4 text-purple-400"></i>
                    <?= $p['max_emails'] ?> Emails
                </div>
            </div>
            <div class="flex gap-3">
                <button onclick='openPkgModal(<?= json_encode($p) ?>)'
                    class="flex-1 bg-slate-800 hover:bg-slate-700 py-2.5 rounded-xl text-xs font-bold uppercase tracking-widest text-slate-300 transition border border-slate-700">Edit</button>
                <button onclick="delPkg(<?= $p['id'] ?>)"
                    class="bg-red-500/10 hover:bg-red-500/20 p-2.5 rounded-xl text-red-400 border border-red-500/20 transition"><i
                        data-lucide="trash-2" class="w-4"></i></button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- PACKAGE MODAL -->
<div id="modal-pkg"
    class="fixed inset-0 bg-slate-950/80 backdrop-blur-md hidden flex items-center justify-center z-50 p-6">
    <form id="form-pkg" onsubmit="handleGeneric(event, 'save_package')"
        class="glass-panel p-10 rounded-3xl w-full max-w-md relative">
        <h3 id="pkg-title" class="text-2xl font-bold mb-8 text-white font-heading">Plan Configuration</h3>
        <input type="hidden" name="id" id="pkg-id">

        <div class="space-y-5">
            <input name="name" id="pkg-name" placeholder="Package Name" required
                class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">

            <div class="grid grid-cols-3 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-1">Disk</label>
                    <input name="disk" id="pkg-disk" type="number" placeholder="MB" required
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white focus:bg-slate-900 transition text-center">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-1">Doms</label>
                    <input name="doms" id="pkg-doms" type="number" placeholder="#" required
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white focus:bg-slate-900 transition text-center">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-1">Mail</label>
                    <input name="mails" id="pkg-mails" type="number" placeholder="#" required
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-emerald-500 text-white focus:bg-slate-900 transition text-center">
                </div>
            </div>

            <div class="flex gap-4 pt-4">
                <button type="button" onclick="closeModal('modal-pkg')"
                    class="flex-1 p-4 rounded-xl font-bold text-slate-400 hover:bg-slate-800 transition">Cancel</button>
                <button type="submit"
                    class="flex-1 bg-emerald-600 hover:bg-emerald-500 p-4 rounded-xl font-bold text-white shadow-lg shadow-emerald-600/20 transition">Save
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
        const fd = new FormData();
        fd.append('ajax_action', 'delete_package');
        fd.append('id', id);
        fetch('', { method: 'POST', body: fd }).then(() => location.reload());
    }
</script>