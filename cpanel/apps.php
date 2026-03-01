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
        verify_csrf();
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

<h2 style="font-size: 1.5rem; line-height: 2rem; font-weight: 700; mb-8; color: var(--slate-900); font-family: 'Lexend', sans-serif; margin-bottom: 2rem;">Application Installer</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
    <?php
    $apps = [
        'wordpress' => ['WordPress', 'The world\'s most popular CMS.', '#2563eb'],
        'laravel' => ['Laravel', 'The PHP Framework for Web Artisans.', '#dc2626'],
        'codeigniter' => ['CodeIgniter', 'Powerful PHP framework with a small footprint.', '#ea580c'],
        'react' => ['React App', 'Create React App boilerplate.', '#06b6d4']
    ];
    foreach ($apps as $key => $info): ?>
        <div class="glass-panel" style="padding: 2rem; position: relative; overflow: hidden; transition: transform 0.5s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="position: absolute; right: -1.5rem; top: -1.5rem; width: 8rem; height: 8rem; border-radius: 9999px; filter: blur(24px); transition: opacity 0.3s; opacity: 0.2; background-color: <?= $info[2] ?>;">
            </div>
            <h3 style="font-size: 1.25rem; line-height: 1.75rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.5rem; position: relative; z-index: 10;">
                <?= $info[0] ?>
            </h3>
            <p style="color: var(--slate-700); font-size: 0.875rem; margin-bottom: 1.5rem; position: relative; z-index: 10; height: 2.5rem;">
                <?= $info[1] ?>
            </p>
            <button onclick="openAppModal('<?= $key ?>', '<?= $info[0] ?>')"
                style="width: 100%; padding: 0.75rem; background-color: <?= $info[2] ?>; color: #fff; font-weight: 700; border-radius: 0.75rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); transition: opacity 0.2s; position: relative; z-index: 10; border: none; cursor: pointer;"
                onmouseover="this.style.opacity='0.9';" onmouseout="this.style.opacity='1';">
                Install
            </button>
        </div>
    <?php endforeach; ?>
</div>

<div style="margin-top: 3rem;">
    <h2 style="font-size: 1.5rem; line-height: 2rem; font-weight: 700; color: var(--slate-900); font-family: 'Lexend', sans-serif; margin-bottom: 1.5rem;">Installed Applications</h2>
    <div class="glass-panel" style="padding: 0; overflow: hidden;">
        <div class="table-container">
            <table class="modern-table" style="width: 100%; text-align: left; color: var(--slate-900);">
                <thead style="background-color: var(--slate-50); font-size: 0.75rem; text-transform: uppercase; color: var(--slate-700); border-bottom: 1px solid var(--slate-300);">
                    <tr>
                        <th style="padding: 1rem; font-weight: 700;">Application</th>
                        <th style="padding: 1rem; font-weight: 700;">Domain</th>
                        <th style="padding: 1rem; font-weight: 700;">Database</th>
                        <th style="padding: 1rem; font-weight: 700;">Status</th>
                        <th style="padding: 1rem; font-weight: 700;">Date</th>
                        <th style="padding: 1rem; font-weight: 700; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($installations)): ?>
                        <tr><td colspan="6" style="padding: 1.5rem; text-align: center; color: var(--slate-700);">No applications installed yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($installations as $inst): ?>
                            <tr style="border-bottom: 1px solid var(--slate-200); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='rgba(248, 250, 252, 0.5)'" onmouseout="this.style.backgroundColor='transparent'">
                                <td style="padding: 1rem; font-weight: 500; text-transform: capitalize;"><?= $inst['app_type'] ?></td>
                                <td style="padding: 1rem;"><a href="http://<?= $inst['domain'] ?>" target="_blank" style="color: #60a5fa; transition: color 0.2s;" onmouseover="this.style.color='var(--slate-900)'" onmouseout="this.style.color='#60a5fa'"><?= $inst['domain'] ?></a></td>
                                <td style="padding: 1rem;">
                                    <span style="font-size: 0.75rem; background-color: rgba(51, 65, 85, 0.1); color: var(--slate-700); padding: 0.25rem 0.5rem; border-radius: 0.25rem;"><?= $inst['db_name'] ?></span>
                                </td>
                                <td style="padding: 1rem;">
                                    <span style="font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: 0.25rem; text-transform: capitalize; <?= $inst['status'] == 'active' ? 'background-color: rgba(16, 185, 129, 0.1); color: #34d399;' : 'background-color: rgba(202, 138, 4, 0.1); color: #ca8a04;' ?>"><?= $inst['status'] ?></span>
                                </td>
                                <td style="padding: 1rem; color: var(--slate-700); font-size: 0.875rem;"><?= date('M d, Y', strtotime($inst['created_at'])) ?></td>
                                <td style="padding: 1rem; text-align: right;">
                                    <button onclick="uninstallApp(<?= $inst['id'] ?>, '<?= $inst['app_type'] ?>', '<?= $inst['domain'] ?>')" 
                                            style="color: #f87171; font-size: 0.875rem; font-weight: 700; transition: color 0.2s; background: transparent; border: none; cursor: pointer;" onmouseover="this.style.color='#fca5a5'" onmouseout="this.style.color='#f87171'">Uninstall</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

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
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

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

