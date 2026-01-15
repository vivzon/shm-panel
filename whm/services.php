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
        if ($action == 'service_action') {
            $op = $_POST['op'];
            if (!in_array($op, ['start', 'stop', 'restart', 'reload']))
                throw new Exception("Invalid Operation");

            echo json_encode($res);
            if (ob_get_level() > 0)
                ob_end_flush();
            flush();
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            cmd("service-control " . $op . " " . escapeshellarg($_POST['service']));
            exit;
        }

        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

$services = ['nginx' => 'Web Server', 'mariadb' => 'MariaDB SQL', 'php8.2-fpm' => 'PHP 8.2 Engine', 'proftpd' => 'FTP Server', 'postfix' => 'Mail Delivery'];

include 'layout/header.php';
?>

<h2 class="text-2xl font-bold mb-8 text-white font-heading">Service Engine</h2>
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <?php foreach ($services as $id => $name):
        $active = trim(cmd("service-status $id")) == 'active'; ?>
        <div
            class="glass-panel p-6 rounded-2xl flex justify-between items-center group hover:border-blue-500/30 transition">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div
                        class="w-3 h-3 rounded-full <?= $active ? 'bg-emerald-500 shadow-[0_0_10px_#10b981]' : 'bg-red-500 shadow-[0_0_10px_#ef4444]' ?>">
                    </div>
                    <div
                        class="w-3 h-3 rounded-full <?= $active ? 'bg-emerald-500' : 'bg-red-500' ?> absolute top-0 animate-ping opacity-75">
                    </div>
                </div>
                <div>
                    <p class="font-bold text-lg text-white group-hover:text-blue-400 transition">
                        <?= $name ?>
                    </p>
                    <p class="text-[10px] font-mono text-slate-500 uppercase tracking-widest">
                        <?= $id ?>
                    </p>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="servAction('<?= $id ?>','restart')" title="Restart"
                    class="p-3 bg-slate-800 rounded-xl text-blue-400 hover:text-white hover:bg-blue-600 transition-all border border-slate-700 shadow-lg">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                </button>
                <button onclick="servAction('<?= $id ?>','stop')" title="Stop"
                    class="p-3 bg-slate-800 rounded-xl text-red-500 hover:text-white hover:bg-red-600 transition-all border border-slate-700 shadow-lg">
                    <i data-lucide="power" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    function servAction(srv, op) {
        showToast('success', 'Service command sent: ' + op);
        const fd = new FormData();
        fd.append('ajax_action', 'service_action');
        fd.append('service', srv);
        fd.append('op', op);
        fetch('', { method: 'POST', body: fd });
    }
</script>