<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];

// DASHBOARD DATA
$clientData = $pdo->query("SELECT c.*, p.name as pkg_name, p.max_emails, p.max_databases, p.max_domains, p.disk_mb FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();

// Usage Stats
try {
    $usage_db = $pdo->query("SELECT COUNT(*) FROM client_databases WHERE client_id = $cid")->fetchColumn();
} catch (Exception $e) {
    $usage_db = 0;
}

$usage_dom = count($domains);
$usage_mail = $pdo->query("SELECT COUNT(*) FROM mail_users WHERE domain_id IN (SELECT id FROM mail_domains WHERE domain IN (SELECT domain FROM domains WHERE client_id = $cid))")->fetchColumn();

// Simple Usage Bar Helper
function usageBar($curr, $max, $color)
{
    if ($max == 0)
        $pct = 100;
    else
        $pct = min(100, round(($curr / $max) * 100));
    // Determine color class if not passed explicitly? passed explicitly mainly.
    return "
    <div class='flex justify-between items-center mb-1'>
        <span class='text-xs font-bold text-slate-400'>$curr / $max</span>
        <span class='text-xs font-bold text-slate-500'>$pct%</span>
    </div>
    <div class='h-2 bg-slate-800 rounded-full overflow-hidden'>
        <div class='h-full $color transition-all duration-1000' style='width: $pct%'></div>
    </div>
    ";
}

include 'layout/header.php';
?>

<h2 class="text-2xl font-bold mb-8 text-white font-heading">Dashboard</h2>

<!-- Usage Grid -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
    <div class="glass-card p-6">
        <div class="flex items-center gap-4 mb-6">
            <div class="p-3 bg-blue-500/10 text-blue-400 rounded-xl"><i data-lucide="globe" class="w-6 h-6"></i></div>
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Domains</h4>
                <div class="text-lg font-bold text-white"><?= $usage_dom ?> Active</div>
            </div>
        </div>
        <?= usageBar($usage_dom, $clientData['max_domains'], 'bg-blue-500') ?>
    </div>

    <div class="glass-card p-6">
        <div class="flex items-center gap-4 mb-6">
            <div class="p-3 bg-purple-500/10 text-purple-400 rounded-xl"><i data-lucide="database" class="w-6 h-6"></i>
            </div>
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Databases</h4>
                <div class="text-lg font-bold text-white"><?= $usage_db ?> Usage</div>
            </div>
        </div>
        <?= usageBar($usage_db, $clientData['max_databases'], 'bg-purple-500') ?>
    </div>

    <div class="glass-card p-6">
        <div class="flex items-center gap-4 mb-6">
            <div class="p-3 bg-emerald-500/10 text-emerald-400 rounded-xl"><i data-lucide="mail" class="w-6 h-6"></i>
            </div>
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Emails</h4>
                <div class="text-lg font-bold text-white"><?= $usage_mail ?> Accounts</div>
            </div>
        </div>
        <?= usageBar($usage_mail, $clientData['max_emails'], 'bg-emerald-500') ?>
    </div>

    <div class="glass-card p-6">
        <div class="flex items-center gap-4 mb-6">
            <div class="p-3 bg-orange-500/10 text-orange-400 rounded-xl"><i data-lucide="package" class="w-6 h-6"></i>
            </div>
            <div>
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Plan</h4>
                <div class="text-lg font-bold text-white"><?= htmlspecialchars($clientData['pkg_name']) ?></div>
            </div>
        </div>
        <div class="text-xs text-slate-500 font-mono mt-1">
            Storage: <?= $clientData['disk_mb'] ?> MB
        </div>
    </div>
</div>

<!-- Shortcuts or Recents -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-8">
    <div class="glass-card p-8 relative overflow-hidden">
        <div class="absolute right-0 top-0 p-10 opacity-5"><i data-lucide="activity" class="w-32 h-32 text-white"></i>
        </div>
        <h3 class="text-xl font-bold text-white mb-2">Quick Actions</h3>
        <div class="grid grid-cols-2 gap-4 mt-6 relative z-10">
            <a href="files.php"
                class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-700 rounded-xl flex items-center gap-3 transition group">
                <i data-lucide="folder-up" class="text-blue-400 group-hover:scale-110 transition"></i>
                <span class="font-bold text-slate-300">File Manager</span>
            </a>
            <a href="emails.php"
                class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-700 rounded-xl flex items-center gap-3 transition group">
                <i data-lucide="mail-plus" class="text-emerald-400 group-hover:scale-110 transition"></i>
                <span class="font-bold text-slate-300">New Email</span>
            </a>
            <a href="databases.php"
                class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-700 rounded-xl flex items-center gap-3 transition group">
                <i data-lucide="database" class="text-purple-400 group-hover:scale-110 transition"></i>
                <span class="font-bold text-slate-300">Add DB</span>
            </a>
            <a href="domains.php"
                class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-700 rounded-xl flex items-center gap-3 transition group">
                <i data-lucide="globe" class="text-orange-400 group-hover:scale-110 transition"></i>
                <span class="font-bold text-slate-300">Add Domain</span>
            </a>
        </div>
    </div>

    <div class="glass-card p-8">
        <h3 class="text-xl font-bold text-white mb-6">Server Information</h3>
        <div class="space-y-4 font-mono text-sm text-slate-400">
            <div class="flex justify-between border-b border-slate-800 pb-2">
                <span>Shared IP</span>
                <span class="text-slate-200"><?= $_SERVER['SERVER_ADDR'] ?></span>
            </div>
            <div class="flex justify-between border-b border-slate-800 pb-2">
                <span>OS</span>
                <span class="text-slate-200">SHM-Linux 5.0 (Debian/Ubuntu)</span>
            </div>
            <div class="flex justify-between border-b border-slate-800 pb-2">
                <!-- Just getting count of PHP Handlers if possible, or static -->
                <span>PHP Versions</span>
                <span class="text-slate-200">8.1, 8.2, 8.3</span>
            </div>
        </div>
    </div>
</div>

<?php include 'layout/footer.php'; ?>