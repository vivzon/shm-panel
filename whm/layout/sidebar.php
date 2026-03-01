<aside
    class="w-64 bg-white border-r border-slate-200 flex flex-col z-20 shadow-sm h-screen overflow-y-auto custom-scrollbar">
    <div class="p-6 pb-4">
        <!-- Brand -->
        <div class="flex items-center gap-3 mb-8">
            <div
                class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-md shadow-blue-500/25">
                <i data-lucide="shield-check" class="w-4 h-4"></i>
            </div>
            <div>
                <h1 class="text-sm font-bold text-slate-900 font-heading tracking-tight leading-none">Vivzon Admin</h1>
                <span class="text-[10px] font-semibold text-blue-600 uppercase tracking-widest">WHM Console</span>
            </div>
        </div>

        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-3 mb-2">Management</div>
        <nav class="space-y-0.5">
            <a href="index.php" class="nav-link <?= $current_page == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard" class="w-4 shrink-0"></i> Overview
            </a>
            <a href="accounts.php" class="nav-link <?= $current_page == 'accounts.php' ? 'active' : '' ?>">
                <i data-lucide="users" class="w-4 shrink-0"></i> Accounts
            </a>
            <a href="packages.php" class="nav-link <?= $current_page == 'packages.php' ? 'active' : '' ?>">
                <i data-lucide="package" class="w-4 shrink-0"></i> Packages
            </a>
        </nav>

        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-3 mb-2 mt-6">Infrastructure</div>
        <nav class="space-y-0.5">
            <a href="services.php" class="nav-link <?= $current_page == 'services.php' ? 'active' : '' ?>">
                <i data-lucide="cpu" class="w-4 shrink-0"></i> Service Node
            </a>
            <a href="server_config.php" class="nav-link <?= $current_page == 'server_config.php' ? 'active' : '' ?>">
                <i data-lucide="server-cog" class="w-4 shrink-0"></i> Server Config
            </a>
            <a href="tools.php" class="nav-link <?= $current_page == 'tools.php' ? 'active' : '' ?>">
                <i data-lucide="wrench" class="w-4 shrink-0"></i> Tools
            </a>
            <a href="logs.php" class="nav-link <?= $current_page == 'logs.php' ? 'active' : '' ?>">
                <i data-lucide="shield-alert" class="w-4 shrink-0"></i> Security Logs
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