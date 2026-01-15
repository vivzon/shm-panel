<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Stats
$stats = explode('|', (string) cmd("get-stats"));

include 'layout/header.php';
?>

<h2 class="text-2xl font-bold mb-6 text-white font-heading">System Overview</h2>
<div class="grid grid-cols-1 md:grid-cols-4 gap-6">
    <?php
    $metrics = [
        ['CPU Load', 'cpu', 'text-blue-400', 'bg-blue-500/10', 'border-blue-500/20'],
        ['RAM Usage', 'layers', 'text-purple-400', 'bg-purple-500/10', 'border-purple-500/20'],
        ['Disk Space', 'hard-drive', 'text-emerald-400', 'bg-emerald-500/10', 'border-emerald-500/20'],
        ['Uptime', 'clock', 'text-orange-400', 'bg-orange-500/10', 'border-orange-500/20']
    ];
    foreach ($metrics as $i => $m):
        ?>
        <div class="glass-panel p-6 rounded-2xl relative overflow-hidden group">
            <div class="absolute right-0 top-0 p-6 opacity-10 group-hover:scale-110 transition duration-500">
                <i data-lucide="<?= $m[1] ?>" class="w-16 h-16 text-white"></i>
            </div>
            <div class="flex items-center gap-3 mb-4">
                <div class="p-2 rounded-lg <?= $m[3] ?> <?= $m[2] ?> border <?= $m[4] ?>">
                    <i data-lucide="<?= $m[1] ?>" class="w-5 h-5"></i>
                </div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest"><?= $m[0] ?></span>
            </div>
            <p class="text-3xl font-bold text-white tracking-tight">
                <?= $stats[$i] ?? '0' ?>     <?= $i < 3 ? '%' : '' ?>
            </p>
        </div>
    <?php endforeach; ?>
</div>

<!-- Network Config Card -->
<div class="mt-6 glass-panel p-6 rounded-2xl relative overflow-hidden group flex items-center justify-between">
    <div class="flex items-center gap-6">
        <div class="p-4 bg-slate-800 rounded-xl text-blue-400">
            <i data-lucide="network" class="w-8 h-8"></i>
        </div>
        <div>
            <h3 class="text-lg font-bold text-white mb-1">Server Network Configuration</h3>
            <?php $md = str_replace('whm.', '', $_SERVER['SERVER_NAME']); ?>
            <div class="flex gap-6 text-sm text-slate-400 font-mono">
                <span class="flex items-center gap-2"><i data-lucide="server" class="w-4"></i> IP:
                    <?= $_SERVER['SERVER_ADDR'] ?></span>
                <span class="flex items-center gap-2"><i data-lucide="globe" class="w-4"></i> NS:
                    ns1.<?= $md ?></span>
                <span class="flex items-center gap-2"><i data-lucide="mail" class="w-4"></i> MX:
                    mail.<?= $md ?></span>
            </div>
        </div>
    </div>
</div>

<?php include 'layout/footer.php'; ?>