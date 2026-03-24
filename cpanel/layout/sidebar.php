<aside class="dashboard-sidebar custom-scrollbar premium-sidebar <?= isset($collapse_sidebar) && $collapse_sidebar ? 'hidden' : '' ?>"
    style="width: 260px; display: flex; flex-direction: column; z-index: 20; height: 100vh; overflow-y: auto;">
    <div style="padding: 1.5rem 1.5rem 1rem;">
        <!-- Brand -->
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem;">
            <div
                style="width: 36px; height: 36px; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--secondary)); display: flex; align-items: center; justify-content: center; color: white; box-shadow: var(--shadow-sm);">
                <i data-lucide="layers" style="width: 16px; height: 16px;"></i>
            </div>
            <div>
                <h1
                    style="font-size: 1rem; font-weight: 700; color: var(--slate-900); font-family: var(--font-premium); letter-spacing: -0.02em; line-height: 1;">
                    Vivzon Cloud</h1>
                <span
                    style="font-size: 0.625rem; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.1em;">Client
                    Area</span>
            </div>
        </div>

        <div
            style="font-size: 0.625rem; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.75rem; margin-bottom: 0.5rem;">
            Core</div>
        <nav style="display: flex; flex-direction: column; gap: 0.125rem;">
            <a href="index.php" class="sidebar-nav-link <?= $current_page == 'index.php' ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="layout-dashboard"></i> Overview
            </a>
            <a href="files.php" target="_blank" class="sidebar-nav-link <?= $current_page == 'files.php' ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="folder-open"></i> File Manager
            </a>
            <a href="billing.php" class="sidebar-nav-link <?= $current_page == 'billing.php' ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="credit-card"></i> Billing History
            </a>
        </nav>

        <div
            style="font-size: 0.625rem; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.75rem; margin-bottom: 0.5rem; margin-top: 1.5rem;">
            Hosting Services</div>
        <nav style="display: flex; flex-direction: column; gap: 0.125rem;">
            <a href="databases.php" class="sidebar-nav-link <?= $current_page == 'databases.php' ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="database"></i> Databases
            </a>
            <a href="emails.php" class="sidebar-nav-link <?= $current_page == 'emails.php' ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="mail"></i> Email Accounts
            </a>
            <a href="domains.php" class="sidebar-nav-link <?= $current_page == 'domains.php' ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="globe"></i> Domains &amp; DNS
            </a>
            <a href="traffic.php" class="sidebar-nav-link <?= $current_page == 'traffic.php' ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="activity"></i> Traffic &amp; Stats
            </a>
        </nav>

        <div
            style="font-size: 0.625rem; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.75rem; margin-bottom: 0.5rem; margin-top: 1.5rem;">
            Tools</div>
        <nav style="display: flex; flex-direction: column; gap: 0.125rem;">
            <a href="tools.php?tab=apps"
                class="sidebar-nav-link <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? 'apps') == 'apps') ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="box"></i> App Installer
            </a>
            <a href="tools.php?tab=security"
                class="sidebar-nav-link <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? '') == 'security') ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="shield"></i> Security (SSH)
            </a>
            <a href="tools.php?tab=backups"
                class="sidebar-nav-link <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? '') == 'backups') ? 'active-sidebar-link' : '' ?>">
                <i data-lucide="save"></i> Backups
            </a>
        </nav>
    </div>

    <div style="margin-top: auto; padding: 1rem; border-top: 1px solid var(--slate-200);">
        <a href="logout.php" class="sidebar-logout">
            <i data-lucide="log-out" style="width: 16px; height: 16px; flex-shrink: 0;"></i>
            <span>Sign Out</span>
        </a>
    </div>
</aside>