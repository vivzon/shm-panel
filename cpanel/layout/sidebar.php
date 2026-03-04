<aside class="dashboard-sidebar custom-scrollbar <?= isset($collapse_sidebar) && $collapse_sidebar ? 'hidden' : '' ?>"
    style="width: 260px; background: white; border-right: 1px solid var(--slate-200); display: flex; flex-direction: column; z-index: 20; height: 100vh; overflow-y: auto;">
    <div style="padding: 1.5rem 1.5rem 1rem;">
        <!-- Brand -->
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem;">
            <div
                style="width: 36px; height: 36px; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--secondary)); display: flex; align-items: center; justify-content: center; color: white; box-shadow: var(--shadow-sm);">
                <i data-lucide="layers" style="width: 16px; height: 16px;"></i>
            </div>
            <div>
                <h1
                    style="font-size: 1rem; font-weight: 700; color: var(--slate-900); font-family: 'Outfit', sans-serif; letter-spacing: -0.02em; line-height: 1;">
                    Vivzon Cloud</h1>
                <span
                    style="font-size: 0.625rem; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.1em;">Client
                    Area</span>
            </div>
        </div>

        <div
            style="font-size: 0.625rem; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.75rem; margin-bottom: 0.5rem;">
            Core</div>
        <nav style="display: flex; flex-direction: column; gap: 2px;">
            <a href="index.php" class="nav-btn <?= $current_page == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard" style="width: 16px; height: 16px; flex-shrink: 0;"></i> Overview
            </a>
            <a href="files.php" target="_blank" class="nav-btn <?= $current_page == 'files.php' ? 'active' : '' ?>">
                <i data-lucide="folder-open" style="width: 16px; height: 16px; flex-shrink: 0;"></i> File Manager
            </a>
            <a href="billing.php" class="nav-btn <?= $current_page == 'billing.php' ? 'active' : '' ?>">
                <i data-lucide="credit-card" style="width: 16px; height: 16px; flex-shrink: 0;"></i> Billing History
            </a>
        </nav>

        <div
            style="font-size: 0.625rem; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.75rem; margin-bottom: 0.5rem; margin-top: 1.5rem;">
            Hosting Services</div>
        <nav style="display: flex; flex-direction: column; gap: 2px;">
            <a href="databases.php" class="nav-btn <?= $current_page == 'databases.php' ? 'active' : '' ?>">
                <i data-lucide="database" style="width: 16px; height: 16px; flex-shrink: 0;"></i> Databases
            </a>
            <a href="emails.php" class="nav-btn <?= $current_page == 'emails.php' ? 'active' : '' ?>">
                <i data-lucide="mail" style="width: 16px; height: 16px; flex-shrink: 0;"></i> Email Accounts
            </a>
            <a href="domains.php" class="nav-btn <?= $current_page == 'domains.php' ? 'active' : '' ?>">
                <i data-lucide="globe" style="width: 16px; height: 16px; flex-shrink: 0;"></i> Domains &amp; DNS
            </a>
            <a href="traffic.php" class="nav-btn <?= $current_page == 'traffic.php' ? 'active' : '' ?>">
                <i data-lucide="activity" style="width: 16px; height: 16px; flex-shrink: 0;"></i> Traffic &amp; Stats
            </a>
        </nav>

        <div
            style="font-size: 0.625rem; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.1em; padding-left: 0.75rem; margin-bottom: 0.5rem; margin-top: 1.5rem;">
            Tools</div>
        <nav style="display: flex; flex-direction: column; gap: 2px;">
            <a href="tools.php?tab=apps"
                class="nav-btn <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? 'apps') == 'apps') ? 'active' : '' ?>">
                <i data-lucide="box" style="width: 16px; height: 16px; flex-shrink: 0;"></i> App Installer
            </a>
            <a href="tools.php?tab=security"
                class="nav-btn <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? '') == 'security') ? 'active' : '' ?>">
                <i data-lucide="shield" style="width: 16px; height: 16px; flex-shrink: 0;"></i> Security (SSH)
            </a>
            <a href="tools.php?tab=backups"
                class="nav-btn <?= ($current_page == 'tools.php' && ($_GET['tab'] ?? '') == 'backups') ? 'active' : '' ?>">
                <i data-lucide="save" style="width: 16px; height: 16px; flex-shrink: 0;"></i> Backups
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