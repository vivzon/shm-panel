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

            // Get Domain Name
            $d = $pdo->query("SELECT domain FROM domains WHERE id=$dom_id AND client_id=$cid")->fetchColumn();
            if (!$d)
                throw new Exception("Invalid Domain");

            sendResponse($res);
            cmd("app-tool $app " . escapeshellarg($d) . " > /dev/null 2>&1 &");
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

        showToast('info', 'Installation Started', 'The installation process is running in the background. Please wait 30-60 seconds.');

        try {
            await fetch('', { method: 'POST', body: fd });
            showToast('success', 'Installation Complete', 'The application installation command has been sent.');
        } catch (e) {
            showToast('warning', 'Check Status', 'Installation request sent, but check logs if it doesn\'t appear.');
        }
    }
</script>