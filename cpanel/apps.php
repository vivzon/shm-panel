<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        if ($action == 'install_app') {
            $app = $_POST['app'];
            $dom_id = $_POST['domain_id'];

            // Get Domain Name validation
            $d = $pdo->query("SELECT domain FROM domains WHERE id=$dom_id AND client_id=$cid")->fetchColumn();
            if (!$d)
                throw new Exception("Invalid Domain");

            // Generate Database Credentials
            $suffix = substr(md5(uniqid()), 0, 6);
            $db_name = "db_{$cid}_" . $suffix; // e.g., db_1_a1b2c3
            $db_user = "u_{$cid}_" . $suffix;
            $db_pass = bin2hex(random_bytes(8)); // 16 chars

            // Insert Tracking Record
            $stmt = $pdo->prepare("INSERT INTO app_installations (client_id, domain_id, app_type, db_name, db_user, db_pass, status) VALUES (?, ?, ?, ?, ?, ?, 'installing')");
            $stmt->execute([$cid, $dom_id, $app, $db_name, $db_user, $db_pass]);
            
            sendResponse($res);

            // Command: app-tool install <app> <domain> <db_name> <db_user> <db_pass>
            cmd("app-tool install $app " . escapeshellarg($d) . " $db_name $db_user $db_pass > /dev/null 2>&1 &");
            exit;
        }

        if ($action == 'uninstall_app') {
            $inst_id = $_POST['install_id'];
            
            // Verify ownership and get details
            $inst = $pdo->query("SELECT * FROM app_installations WHERE id=$inst_id AND client_id=$cid")->fetch();
            if (!$inst) throw new Exception("Installation not found");

            // Get Domain Name
            $d = $pdo->query("SELECT domain FROM domains WHERE id={$inst['domain_id']}")->fetchColumn();

            // Command: app-tool uninstall <app> <domain> <db_name> <db_user>
            cmd("app-tool uninstall {$inst['app_type']} " . escapeshellarg($d) . " {$inst['db_name']} {$inst['db_user']} > /dev/null 2>&1 &");

            // Remove from DB
            $pdo->exec("DELETE FROM app_installations WHERE id=$inst_id");

            sendResponse(['status' => 'success', 'msg' => 'Uninstalled Successfully']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Data
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();
// Fetch Installations
$installations = $pdo->query("SELECT i.*, d.domain FROM app_installations i JOIN domains d ON i.domain_id = d.id WHERE i.client_id = $cid ORDER BY i.created_at DESC")->fetchAll();

include 'layout/header.php';
?>

<h2 class="text-2xl font-bold mb-8 text-white">Application Installer</h2>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <?php
    $apps = [
        'wordpress' => ['WordPress', 'The world\'s most popular CMS.', 'bg-blue-600'],
        'laravel' => ['Laravel', 'The PHP Framework for Web Artisans.', 'bg-red-600'],
        'codeigniter' => ['CodeIgniter', 'Powerful PHP framework with a small footprint.', 'bg-orange-600'],
        'react' => ['React App', 'Create React App boilerplate.', 'bg-cyan-500']
    ];
    foreach ($apps as $key => $info): ?>
        <div class="glass-card p-8 relative overflow-hidden group hover:-translate-y-1 transition duration-500">
            <div
                class="absolute -right-6 -top-6 w-32 h-32 <?= $info[2] ?>/20 rounded-full blur-3xl group-hover:bg-opacity-40 transition">
            </div>
            <h3 class="text-xl font-bold text-white mb-2 relative z-10">
                <?= $info[0] ?>
            </h3>
            <p class="text-slate-400 text-sm mb-6 relative z-10 h-10">
                <?= $info[1] ?>
            </p>
            <button onclick="openAppModal('<?= $key ?>', '<?= $info[0] ?>')"
                class="w-full py-3 <?= $info[2] ?> hover:opacity-90 text-white font-bold rounded-xl shadow-lg transition relative z-10">
                Install
            </button>
        </div>
    <?php endforeach; ?>
</div>

<div class="mt-12">
    <h2 class="text-2xl font-bold mb-6 text-white">Installed Applications</h2>
    <div class="glass-card overflow-hidden">
        <table class="w-full text-left text-white">
            <thead class="bg-white/10 text-xs uppercase">
                <tr>
                    <th class="p-4">Application</th>
                    <th class="p-4">Domain</th>
                    <th class="p-4">Database</th>
                    <th class="p-4">Status</th>
                    <th class="p-4">Date</th>
                    <th class="p-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php if (empty($installations)): ?>
                    <tr><td colspan="6" class="p-6 text-center text-slate-400">No applications installed yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($installations as $inst): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="p-4 font-medium capitalize"><?= $inst['app_type'] ?></td>
                            <td class="p-4"><a href="http://<?= $inst['domain'] ?>" target="_blank" class="text-blue-400 hover:text-blue-300"><?= $inst['domain'] ?></a></td>
                            <td class="p-4">
                                <span class="text-xs bg-slate-700 px-2 py-1 rounded"><?= $inst['db_name'] ?></span>
                            </td>
                            <td class="p-4">
                                <span class="text-xs <?= $inst['status']=='active'?'bg-green-600':'bg-yellow-600' ?> px-2 py-1 rounded capitalize"><?= $inst['status'] ?></span>
                            </td>
                            <td class="p-4 text-slate-400 text-sm"><?= date('M d, Y', strtotime($inst['created_at'])) ?></td>
                            <td class="p-4 text-right">
                                <button onclick="uninstallApp(<?= $inst['id'] ?>, '<?= $inst['app_type'] ?>', '<?= $inst['domain'] ?>')" 
                                        class="text-red-400 hover:text-red-300 text-sm font-bold">Uninstall</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    async function openAppModal(app, appName) {
        // Simple Prompt-based selection to avoid complex modals for now, matching original simple logic
        // Or better, build a custom proper modal since we have better layout now? 
        // Let's use a nice prompt loop or simple confirm. The original used `prompt`.

        // We'll create a dynamic domain list for the prompt
        let domList = "Available IDs:\n";
        <?php foreach ($domains as $d)
            echo "domList += \"{$d['id']}: {$d['domain']}\\n\";\n"; ?>
        
        const domainId = prompt(`Install ${appName} to which domain? (Enter Domain ID)\n\n${domList}`);
        if (!domainId) return;

        if (!confirm(`WARNING: This will OVERWRITE existing content in the public_html folder and likely database configuration for this domain.\n\nAre you sure you want to install ${appName}?`)) return;

        handleAppInstall(app, domainId);
    }

    async function handleAppInstall(app, domainId) {
        const fd = new FormData();
        fd.append('ajax_action', 'install_app');
        fd.append('app', app);
        fd.append('domain_id', domainId);

        showToast('info', 'Installation Started', 'Creating database and installing files. Please wait...');

        try {
            await fetch('', { method: 'POST', body: fd });
            showToast('success', 'Installation Started', 'The system is installing ' + app + ' in the background.');
            setTimeout(() => location.reload(), 2000);
        } catch (e) {
            showToast('warning', 'Check Status', 'Installation request sent, but check logs if it doesn\'t appear.');
        }
    }

    async function uninstallApp(id, app, domain) {
        if (!confirm(`Are you sure you want to UNINSTALL ${app} from ${domain}?\n\nThis will DELETE ALL FILES and the DATABASE (${id}).\n\nThis action cannot be undone.`)) return;

        const fd = new FormData();
        fd.append('ajax_action', 'uninstall_app');
        fd.append('install_id', id);

        try {
            const res = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            if(data.status === 'success') {
                showToast('success', 'Uninstalled', 'Application removed successfully.');
                setTimeout(() => location.reload(), 2000);
            } else {
                showToast('error', 'Error', data.msg);
            }
        } catch (e) {
            showToast('error', 'Error', 'Request failed');
        }
    }
</script>