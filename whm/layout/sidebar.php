<aside
    class="w-72 bg-slate-950 border-r border-slate-900 flex flex-col z-20 shadow-2xl h-screen overflow-y-auto custom-scrollbar">
    <div class="p-8 pb-6">
        <div class="flex items-center gap-4 mb-10">
            <div
                class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                <i data-lucide="shield-check" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="text-lg font-bold text-white font-heading tracking-tight leading-none">SHM ADMIN</h1>
                <span class="text-[10px] font-bold text-indigo-500 uppercase tracking-widest">System Counsel</span>
            </div>
        </div>

        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest pl-4 mb-3">Management</div>
        <nav class="space-y-1">
            <a href="index.php" class="nav-link <?= $current_page == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard" class="w-4"></i> Overview
            </a>
            <a href="accounts.php" class="nav-link <?= $current_page == 'accounts.php' ? 'active' : '' ?>">
                <i data-lucide="users" class="w-4"></i> Accounts
            </a>
            <a href="packages.php" class="nav-link <?= $current_page == 'packages.php' ? 'active' : '' ?>">
                <i data-lucide="package" class="w-4"></i> Packages
            </a>
        </nav>

        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest pl-4 mb-3 mt-8">System Infrastructure
        </div>
        <nav class="space-y-1">
            <a href="services.php" class="nav-link <?= $current_page == 'services.php' ? 'active' : '' ?>">
                <i data-lucide="cpu" class="w-4"></i> Service Node
            </a>
            <a href="tools.php" class="nav-link <?= $current_page == 'tools.php' ? 'active' : '' ?>">
                <i data-lucide="wrench" class="w-4"></i> Tools
            </a>
            <a href="logs.php" class="nav-link <?= $current_page == 'logs.php' ? 'active' : '' ?>">
                <i data-lucide="shield-alert" class="w-4"></i> Security Logs
            </a>
        </nav>
    </div>

    <div class="mt-auto p-6 border-t border-slate-900 bg-slate-950/50">
        <a href="logout.php"
            class="flex items-center gap-3 text-slate-400 hover:text-red-400 transition group p-3 rounded-lg hover:bg-red-500/10">
            <i data-lucide="log-out" class="w-4 group-hover:-translate-x-1 transition"></i>
            <span class="font-bold text-xs">End Session</span>
        </a>
    </div>
</aside>