<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['client'];
$cid = $_SESSION['cid'];

// -------- BACKEND HANDLERS --------
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        // --- APPS HANDLER ---
        if ($action == 'install_app') {
            $app = $_POST['app'];
            $dom_id = $_POST['domain_id'];
            $domain = $pdo->query("SELECT domain FROM domains WHERE id=$dom_id AND client_id=$cid")->fetchColumn();
            if (!$domain) throw new Exception("Invalid Domain");

            $rand = substr(md5(uniqid()), 0, 6);
            $db_name = $username . "_wp_" . $rand;
            $db_user = $username . "_" . $rand;
            $db_pass = bin2hex(random_bytes(8));

            $stmt = $pdo->prepare("INSERT INTO app_installations (client_id, domain_id, app_type, db_name, db_user, db_pass, status) VALUES (?, ?, ?, ?, ?, ?, 'installing')");
            $stmt->execute([$cid, $dom_id, $app, $db_name, $db_user, $db_pass]);

            $cmd = "app-tool install " . escapeshellarg($app) . " " . escapeshellarg($domain) . " " . escapeshellarg($db_name) . " " . escapeshellarg($db_user) . " " . escapeshellarg($db_pass);
            if (function_exists('cmd')) cmd("$cmd > /dev/null 2>&1 &");

            sendResponse($res);
            exit;
        }

        // --- FTP HANDLERS ---
        if ($action == 'add_ftp') {
            if ($_POST['pass'] !== $_POST['pass2']) throw new Exception("Passwords do not match");

            $ftp_user = strtolower($_POST['ftp_user'] . '@' . $username);
            $pass = md5($_POST['pass']);
            $home = "/var/www/clients/$username/public_html" . ($_POST['dir'] ? '/' . trim($_POST['dir'], '/') : '');

            $sys_user_info = function_exists('posix_getpwnam') ? posix_getpwnam($username) : ['uid'=>1000,'gid'=>1000];
            $uid = $sys_user_info['uid'] ?? 1000;
            $gid = $sys_user_info['gid'] ?? 1000;

            $check = $pdo->prepare("SELECT count(*) FROM ftp_users WHERE userid = ?");
            $check->execute([$ftp_user]);
            if ($check->fetchColumn() > 0) throw new Exception("FTP User already exists");

            $pdo->prepare("INSERT INTO ftp_users (userid, passwd, homedir, uid, gid) VALUES (?,?,?,?,?)")->execute([$ftp_user, $pass, $home, $uid, $gid]);
            sendResponse($res);
            exit;
        }

        if ($action == 'del_ftp') {
            $userToDelete = $_POST['user'];
            if (!str_ends_with($userToDelete, "@$username")) throw new Exception("Permission Denied");
            $pdo->prepare("DELETE FROM ftp_users WHERE userid = ?")->execute([$userToDelete]);
            sendResponse($res);
            exit;
        }

        if ($action == 'list_ftp') {
            $stmt = $pdo->prepare("SELECT userid, homedir FROM ftp_users WHERE userid LIKE ?");
            $stmt->execute(["%@$username"]);
            echo json_encode(['status'=>'success','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // --- SECURITY HANDLERS ---
        if ($action == 'add_ssh') {
            cmd("shm-manage ssh-key add " . escapeshellarg($username) . " " . escapeshellarg($_POST['key']));
            sendResponse($res);
            exit;
        }
        if ($action == 'del_ssh') {
            cmd("shm-manage ssh-key delete " . escapeshellarg($username) . " " . (int)$_POST['line']);
            sendResponse($res);
            exit;
        }
        if ($action == 'list_ssh') {
            $out = cmd("shm-manage ssh-key list " . escapeshellarg($username));
            $lines = array_filter(explode("\n",$out));
            echo json_encode(['status'=>'success','data'=>array_values($lines)]);
            exit;
        }

        if ($action == 'fix_perms') {
            cmd("shm-manage fix-permissions " . escapeshellarg($username));
            sendResponse($res);
            exit;
        }

        // --- BACKUP HANDLERS ---
        if ($action == 'create_backup') {
            cmd("shm-manage backup create " . escapeshellarg($username));
            sendResponse($res);
            exit;
        }
        if ($action == 'list_backups') {
            $out = cmd("shm-manage backup list " . escapeshellarg($username));
            $backups = [];
            foreach (explode("\n",$out) as $line) {
                if (!trim($line)) continue;
                $parts = preg_split('/\s+/',$line);
                if (count($parts) >= 5) {
                    $backups[] = ['name'=>end($parts),'size'=>$parts[0],'date'=>$parts[1].' '.$parts[2].' '.$parts[3]];
                }
            }
            echo json_encode(['status'=>'success','data'=>$backups]);
            exit;
        }
        if ($action == 'restore_backup') {
            cmd("shm-manage backup restore " . escapeshellarg($username) . " " . escapeshellarg($_POST['file']));
            sendResponse($res);
            exit;
        }

        // --- TROUBLESHOOT HANDLERS ---
        if ($action == 'fix_website' || $action == 'restart_services' || $action == 'fix_config') {
            $did = (int)$_POST['domain_id'];
            $chk = $pdo->prepare("SELECT id, domain FROM domains WHERE id=? AND client_id=?");
            $chk->execute([$did,$cid]);
            $domainData = $chk->fetch();
            if (!$domainData) throw new Exception("Access Denied");

            cmd("shm-manage troubleshoot fix-perms $did");
            cmd("shm-manage troubleshoot fix-default-page $did");
            cmd("shm-manage troubleshoot reload-services $did");

            if($action=='fix_config'){
                $domain = $domainData['domain'];
                cmd("shm-manage troubleshoot fix-config $domain");
                sendResponse(['status'=>'success','msg'=>'Configuration fixes applied.']);
            } else {
                sendResponse($res);
            }
            exit;
        }

    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode(['status'=>'error','msg'=>$e->getMessage()]);
    }
    exit;
}

// -------- FRONTEND DATA --------
$active_tab = $_GET['tab'] ?? 'apps';
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();

include 'layout/header.php';
?>

<!-- Dashboard Header -->
<div class="mb-8">
    <h2 class="text-2xl font-bold text-white mb-2">System Tools</h2>
    <p class="text-slate-400 text-sm">Manage applications, security, and backups.</p>
</div>

<!-- TABS -->
<div class="flex border-b border-slate-800 mb-8 overflow-x-auto">
    <a href="?tab=apps" class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab=='apps'?'border-blue-500 text-white':'border-transparent text-slate-500 hover:text-slate-300' ?>">App Installer</a>
    <a href="?tab=ftp" class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab=='ftp'?'border-blue-500 text-white':'border-transparent text-slate-500 hover:text-slate-300' ?>">FTP Manager</a>
    <a href="?tab=security" class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab=='security'?'border-blue-500 text-white':'border-transparent text-slate-500 hover:text-slate-300' ?>">Security (SSH)</a>
    <a href="?tab=backups" class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab=='backups'?'border-blue-500 text-white':'border-transparent text-slate-500 hover:text-slate-300' ?>">Backups</a>
    <a href="?tab=troubleshoot" class="px-6 py-3 text-sm font-bold border-b-2 transition whitespace-nowrap <?= $active_tab=='troubleshoot'?'border-emerald-500 text-white':'border-transparent text-slate-500 hover:text-slate-300' ?>">Troubleshoot</a>
</div>

<!-- Include your existing tab content here (Apps, FTP, Security, Backups) -->
<!-- Only adding the new Fix Config button in Troubleshoot tab -->

<div id="tab-troubleshoot" class="<?= $active_tab=='troubleshoot'?'':'hidden' ?>">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- Existing Troubleshoot Buttons -->
        <div class="glass-card p-8 bg-gradient-to-br from-indigo-900/20 to-indigo-900/5 border-indigo-500/20">
            <h3 class="font-bold text-white mb-4">Display Doctor</h3>
            <button onclick="fixWebsite()" class="w-full py-4 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold shadow-lg flex items-center justify-center gap-2 transition">
                <i data-lucide="wand-2" class="w-5 h-5"></i> Fix Website Display
            </button>
        </div>

        <div class="glass-card p-8 bg-gradient-to-br from-slate-900/50 to-slate-900/20">
            <h3 class="font-bold text-white mb-4">Restart Services</h3>
            <button onclick="restartServices()" class="w-full py-4 bg-slate-700 hover:bg-slate-600 text-white rounded-xl font-bold shadow-lg flex items-center justify-center gap-2 transition">
                <i data-lucide="power" class="w-5 h-5"></i> Restart Services
            </button>
        </div>

        <!-- NEW FIX CONFIG BUTTON -->
        <div class="glass-card p-8 bg-gradient-to-br from-rose-900/20 to-rose-900/5 border-rose-500/20 mt-6">
            <h3 class="font-bold text-white mb-4">Fix Config Issues</h3>
            <button onclick="fixConfig()" class="w-full py-4 bg-rose-600 hover:bg-rose-500 text-white rounded-xl font-bold shadow-lg flex items-center justify-center gap-2 transition">
                <i data-lucide="wrench" class="w-5 h-5"></i> Fix Config
            </button>
        </div>
    </div>
</div>

<?php include 'layout/footer.php'; ?>

<script>
// Utility: Prompt domain ID
function getDomId() {
    let domList = "Available IDs:\n";
    <?php foreach($domains as $d) echo "domList += \"{$d['id']}: {$d['domain']}\\n\";\n"; ?>
    return prompt(`Select Domain ID:\n\n${domList}`);
}

// Troubleshoot AJAX
async function fixWebsite() {
    const did = getDomId(); if(!did) return;
    if(!confirm("This will fix permissions and default pages for this domain. Continue?")) return;
    const fd = new FormData(); fd.append('ajax_action','fix_website'); fd.append('domain_id',did);
    await fetch('',{method:'POST',body:fd}).then(r=>r.json());
    showToast('success','Website Fixed');
}
async function restartServices() {
    const did = getDomId(); if(!did) return;
    const fd = new FormData(); fd.append('ajax_action','restart_services'); fd.append('domain_id',did);
    await fetch('',{method:'POST',body:fd});
    showToast('success','Services Restarted');
}

// NEW: Fix Config
async function fixConfig() {
    const did = getDomId(); if(!did) return;
    if(!confirm("This will fix server configuration issues for this domain. Continue?")) return;
    const fd = new FormData(); fd.append('ajax_action','fix_config'); fd.append('domain_id',did);
    const res = await fetch('',{method:'POST',body:fd}).then(r=>r.json());
    if(res.status==='success') showToast('success','Config Fixed',res.msg);
    else showToast('error','Failed',res.msg);
}
</script>
