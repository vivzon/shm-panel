<aside
    class="w-64 bg-white border-r border-slate-200 flex flex-col z-20 shadow-sm h-screen overflow-y-auto custom-scrollbar <?= isset($collapse_sidebar) && $collapse_sidebar ? 'hidden' : '' ?>">
    <div class="p-6 pb-4">
        <!-- Brand -->
        <div class="flex items-center gap-3 mb-8">
            <div
                class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-md shadow-blue-500/25">
                <i data-lucide="layers" class="w-4 h-4"></i>
            </div>
            <div>
                <h1 class="text-sm font-bold text-slate-900 font-heading tracking-tight leading-none">Vivzon Portal</h1>
                <span class="text-[10px] font-semibold text-blue-600 uppercase tracking-widest">Client Area</span>
            </div>
        </div>

        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-3 mb-2">Core</div>
        <nav class="space-y-0.5">
            <a href="index.php" class="nav-btn <?= $current_page == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard" class="w-4 shrink-0"></i> Overview
            </a>
            <a href="files.php" target="_blank" class="nav-btn <?= $current_page == 'files.php' ? 'active' : '' ?>">
                <i data-lucide="folder-open" class="w-4 shrink-0"></i> File Manager
            </a>
        </nav>

        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-3 mb-2 mt-6">Hosting Services
        </div>
        <nav class="space-y-0.5">
            <a href="databases.php" class="nav-btn <?= $current_page == 'databases.php' ? 'active' : '' ?>">
                <i data-lucide="database" class="w-4 shrink-0"></i> Databases
            </a>
            <a href="emails.php" class="nav-btn <?= $current_page == 'emails.php' ? 'active' : '' ?>">
                <i data-lucide="mail" class="w-4 shrink-0"></i> Email Accounts
            </a>
            <a href="domains.php" class="nav-btn <?= $current_page == 'domains.php' ? 'active' : '' ?>">
                <i data-lucide="globe" class="w-4 shrink-0"></i> Domains &amp; DNS
            </a>
            <a href="traffic.php" class="nav-btn <?= $current_page == 'traffic.php' ? 'active' : '' ?>">
                <i data-lucide="activity" class="w-4 shrink-0"></i> Traffic &amp; Stats
            </a>
        </nav>

        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-3 mb-2 mt-6">Tools</div>
        <nav class="space-y-0.5">
            <a href="tools.php?tab=apps"
                class="nav-btn <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? 'apps') == 'apps') ? 'active' : '' ?>">
                <i data-lucide="box" class="w-4 shrink-0"></i> App Installer
            </a>
            <a href="tools.php?tab=security"
                class="nav-btn <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? '') == 'security') ? 'active' : '' ?>">
                <i data-lucide="shield" class="w-4 shrink-0"></i> Security (SSH)
            </a>
            <a href="tools.php?tab=backups"
                class="nav-btn <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? '') == 'backups') ? 'active' : '' ?>">
                <i data-lucide="save" class="w-4 shrink-0"></i> Backups
            </a>
        </nav>
    </div>

    <div class="mt-auto p-4 border-t border-slate-100">
        <a href="logout.php"
            class="flex items-center gap-3 text-slate-500 hover:text-red-600 transition group p-3 rounded-lg hover:bg-red-50">
            <i data-lucide="log-out" class="w-4 group-hover:-translate-x-1 transition shrink-0"></i>
            <span class="font-semibold text-xs">Sign Out</span>
        </a>
    </div>
</aside>