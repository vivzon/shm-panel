<?php
// Fetch package name for the user info strip
$pkg_name = '';
try {
    $stmt_pkg = $pdo->prepare("SELECT p.name FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = ?");
    $stmt_pkg->execute([$cid]);
    $pkg_row = $stmt_pkg->fetch();
    $pkg_name = $pkg_row['name'] ?? 'Standard';
} catch (Exception $e) {
    $pkg_name = 'Standard';
}
?>
<aside id="sidebar" class="sidebar-wrapper custom-scrollbar premium-sidebar"
    style="width: 260px; display: flex; flex-direction: column; z-index: 60; height: 100vh; overflow-y: auto; overflow-x: hidden; transition: transform var(--transition-normal); flex-shrink: 0;">

    <!-- Brand Header -->
    <div style="padding: 1.25rem 1rem 1rem; border-bottom: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 2.25rem; height: 2.25rem; border-radius: 0.625rem; background: linear-gradient(135deg, var(--primary), var(--secondary)); display: flex; align-items: center; justify-content: center; color: white; box-shadow: var(--shadow-glow); flex-shrink: 0;">
                <i data-lucide="layers" style="width: 1rem; height: 1rem;"></i>
            </div>
            <div style="min-width: 0;">
                <div style="font-size: 0.9375rem; font-weight: 800; color: var(--text-primary); font-family: var(--font-heading); letter-spacing: -0.025em; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?= htmlspecialchars(get_branding()) ?>
                </div>
                <div style="font-size: 0.625rem; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.12em; margin-top: 0.1rem;">
                    Client Area
                </div>
            </div>
        </div>
    </div>

    <!-- Account Info Strip -->
    <div style="padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); background: var(--primary-light);">
        <div style="display: flex; align-items: center; gap: 0.625rem;">
            <div style="width: 2rem; height: 2rem; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--secondary)); display: flex; align-items: center; justify-content: center; color: white; font-size: 0.75rem; font-weight: 700; flex-shrink: 0;">
                <?= strtoupper(substr($username, 0, 1)) ?>
            </div>
            <div style="min-width: 0; flex: 1;">
                <div style="font-size: 0.8125rem; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?= htmlspecialchars($username) ?>
                </div>
                <div style="font-size: 0.625rem; color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;">
                    <?= htmlspecialchars($pkg_name) ?> Plan
                </div>
            </div>
            <span class="badge badge-info" style="flex-shrink: 0;">Active</span>
        </div>
    </div>

    <!-- Navigation -->
    <div style="flex: 1; padding: 0.75rem; display: flex; flex-direction: column; gap: 1.25rem; overflow-y: auto;" class="custom-scrollbar">

        <!-- Core -->
        <div>
            <span class="sidebar-section-label">Core</span>
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
        </div>

        <!-- Hosting Services -->
        <div>
            <span class="sidebar-section-label">Hosting Services</span>
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
        </div>

        <!-- Tools -->
        <div>
            <span class="sidebar-section-label">Tools</span>
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
                <a href="diagnostic.php" class="sidebar-nav-link <?= $current_page == 'diagnostic.php' ? 'active-sidebar-link' : '' ?>">
                    <i data-lucide="stethoscope"></i> Diagnostics
                </a>
            </nav>
        </div>

    </div>

    <!-- Footer / Sign out -->
    <div style="padding: 0.75rem; border-top: 1px solid var(--border-color);">
        <a href="logout.php" class="sidebar-logout">
            <i data-lucide="log-out" style="width: 0.9375rem; height: 0.9375rem; flex-shrink: 0;"></i>
            <span>Sign Out</span>
        </a>
    </div>
</aside>