<aside id="sidebar"
    style="width: 16rem; background: var(--bg-surface); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; z-index: 60; box-shadow: var(--shadow-lg); height: 100vh; overflow-y: auto;"
    class="sidebar-wrapper custom-scrollbar">
    <div style="padding: 1.5rem; padding-bottom: 1rem;">
        <!-- Brand -->
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem;">
            <div
                style="width: 2.25rem; height: 2.25rem; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: var(--radius-xl); display: flex; align-items: center; justify-content: center; color: white; box-shadow: var(--shadow-glow);">
                <i data-lucide="shield-check" style="width: 1rem; height: 1rem;"></i>
            </div>
            <div>
                <h1
                    style="font-size: 0.875rem; font-weight: 700; color: var(--slate-900); font-family: var(--font-heading); letter-spacing: -0.025em; line-height: 1;">
                    Vivzon Cloud</h1>
                <span
                    style="font-size: 0.625rem; font-weight: 600; color: var(--primary); text-transform: uppercase; letter-spacing: 0.1em;">WHM
                    Console</span>
            </div>
        </div>

        <div
            style="font-size: 0.625rem; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.75rem; margin-bottom: 0.5rem;">
            Management</div>
            <a href="index.php" class="nav-link hover-glow <?= $current_page == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard" style="width: 1rem; height: 1rem; flex-shrink: 0;"></i> Overview
            </a>
            <a href="accounts.php" class="nav-link hover-glow <?= $current_page == 'accounts.php' ? 'active' : '' ?>">
                <i data-lucide="users" style="width: 1rem; height: 1rem; flex-shrink: 0;"></i> Accounts
            </a>
            <a href="packages.php" class="nav-link hover-glow <?= $current_page == 'packages.php' ? 'active' : '' ?>">
                <i data-lucide="package" style="width: 1rem; height: 1rem; flex-shrink: 0;"></i> Packages
            </a>
        </nav>

        <div
            style="font-size: 0.625rem; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.75rem; margin-bottom: 0.5rem; margin-top: 1.5rem;">
            Infrastructure</div>
        <nav style="display: flex; flex-direction: column; gap: 0.125rem;">
            <a href="services.php" class="nav-link hover-glow <?= $current_page == 'services.php' ? 'active' : '' ?>">
                <i data-lucide="cpu" style="width: 1rem; height: 1rem; flex-shrink: 0;"></i> Service Node
            </a>
            <a href="server_config.php" class="nav-link hover-glow <?= $current_page == 'server_config.php' ? 'active' : '' ?>">
                <i data-lucide="server-cog" style="width: 1rem; height: 1rem; flex-shrink: 0;"></i> Server Config
            </a>
            <a href="tools.php" class="nav-link hover-glow <?= $current_page == 'tools.php' ? 'active' : '' ?>">
                <i data-lucide="wrench" style="width: 1rem; height: 1rem; flex-shrink: 0;"></i> Tools
            </a>
            <a href="logs.php" class="nav-link hover-glow <?= $current_page == 'logs.php' ? 'active' : '' ?>">
                <i data-lucide="shield-alert" style="width: 1rem; height: 1rem; flex-shrink: 0;"></i> Security Logs
            </a>
        </nav>
    </div>

    <div style="margin-top: auto; padding: 1rem; border-top: 1px solid var(--slate-200);">
        <a href="logout.php" class="sidebar-logout">
            <i data-lucide="log-out" style="width: 1rem; height: 1rem; flex-shrink: 0;"></i>
            <span>Sign Out</span>
        </a>
    </div>
</aside>