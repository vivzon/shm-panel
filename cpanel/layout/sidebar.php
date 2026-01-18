<aside
    class="w-72 bg-slate-950 border-r border-slate-900 flex flex-col z-20 shadow-2xl h-screen overflow-y-auto custom-scrollbar <?= isset($collapse_sidebar) && $collapse_sidebar ? 'hidden' : '' ?>">
    <div class="p-8 pb-6">
        <div class="flex items-center gap-4 mb-10">
            <div
                class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-lg shadow-blue-500/30">
                <i data-lucide="layers" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="text-lg font-bold text-white font-heading tracking-tight leading-none">SHM CLIENT</h1>
                <span class="text-[10px] font-bold text-blue-500 uppercase tracking-widest">Self Service</span>
            </div>
        </div>

        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest pl-4 mb-3">Core</div>
        <nav class="space-y-1">
            <a href="index.php" class="nav-btn <?= $current_page == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard" class="w-4"></i> Overview
            </a>
            <a href="files.php" target="_blank" class="nav-btn <?= $current_page == 'files.php' ? 'active' : '' ?>">
                <i data-lucide="folder-open" class="w-4"></i> File Manager
            </a>
        </nav>

        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest pl-4 mb-3 mt-8">Hosting Services
        </div>
        <nav class="space-y-1">
            <a href="databases.php" class="nav-btn <?= $current_page == 'databases.php' ? 'active' : '' ?>">
                <i data-lucide="database" class="w-4"></i> Databases
            </a>
            <a href="emails.php" class="nav-btn <?= $current_page == 'emails.php' ? 'active' : '' ?>">
                <i data-lucide="mail" class="w-4"></i> Email Accounts
            </a>
            <a href="domains.php" class="nav-btn <?= $current_page == 'domains.php' ? 'active' : '' ?>">
                <i data-lucide="globe" class="w-4"></i> Domains & DNS
            </a>
        </nav>

        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest pl-4 mb-3 mt-8">Tools</div>
        <nav class="space-y-1">
            <a href="tools.php?tab=apps"
                class="nav-btn <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? 'apps') == 'apps') ? 'active' : '' ?>">
                <i data-lucide="box" class="w-4"></i> App Installer
            </a>
            <a href="tools.php?tab=security"
                class="nav-btn <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? '') == 'security') ? 'active' : '' ?>">
                <i data-lucide="shield" class="w-4"></i> Security (SSH)
            </a>
            <a href="tools.php?tab=backups"
                class="nav-btn <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? '') == 'backups') ? 'active' : '' ?>">
                <i data-lucide="save" class="w-4"></i> Backups
            </a>
        </nav>
    </div>

    <div class="mt-auto p-6 border-t border-slate-900 bg-slate-950/50">
        <a href="logout.php"
            class="flex items-center gap-3 text-slate-400 hover:text-red-400 transition group p-3 rounded-lg hover:bg-red-500/10">
            <i data-lucide="log-out" class="w-4 group-hover:-translate-x-1 transition"></i>
            <span class="font-bold text-xs">Sign Out</span>
        </a>
    </div>
</aside>