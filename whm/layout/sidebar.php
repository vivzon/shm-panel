<?php
// Active page detection
$nav_links = [
    'management' => [
        ['href' => 'index.php', 'icon' => 'layout-dashboard', 'label' => 'Overview'],
        ['href' => 'accounts.php', 'icon' => 'users', 'label' => 'Accounts'],
        ['href' => 'packages.php', 'icon' => 'package', 'label' => 'Packages'],
    ],
    'infrastructure' => [
        ['href' => 'services.php', 'icon' => 'cpu', 'label' => 'Services'],
        ['href' => 'server_config.php', 'icon' => 'server-cog', 'label' => 'Server Config'],
        ['href' => 'files-sh.php', 'icon' => 'folder-open', 'label' => 'File Manager'],
        ['href' => 'tools.php', 'icon' => 'wrench', 'label' => 'Tools'],
        ['href' => 'logs.php', 'icon' => 'shield-alert', 'label' => 'Security Logs'],
    ],
];
?>
<aside id="sidebar" class="sidebar-wrapper custom-scrollbar"
    style="width: 15rem; background: var(--bg-surface); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; z-index: 60; box-shadow: var(--shadow-lg); height: 100vh; overflow-y: auto; overflow-x: hidden; transition: transform var(--transition-normal); flex-shrink: 0;">

    <!-- Brand Header -->
    <div style="padding: 1.25rem 1rem 1rem; border-bottom: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div
                style="width: 2.25rem; height: 2.25rem; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 0.625rem; display: flex; align-items: center; justify-content: center; color: white; box-shadow: var(--shadow-glow); flex-shrink: 0;">
                <i data-lucide="shield-check" style="width: 1rem; height: 1rem;"></i>
            </div>
            <div style="min-width: 0;">
                <div
                    style="font-size: 0.9375rem; font-weight: 800; color: var(--text-primary); font-family: var(--font-heading); letter-spacing: -0.025em; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?= htmlspecialchars(get_branding()) ?>
                </div>
                <div
                    style="font-size: 0.625rem; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.12em; margin-top: 0.1rem;">
                    WHM Console
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <div style="flex: 1; padding: 0.75rem 0.75rem; display: flex; flex-direction: column; gap: 1.5rem; overflow-y: auto;"
        class="custom-scrollbar">
        <?php foreach ($nav_links as $section => $links): ?>
            <div>
                <div
                    style="font-size: 0.5625rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.12em; padding: 0 0.5rem; margin-bottom: 0.375rem;">
                    <?= ucfirst($section) ?>
                </div>
                <nav style="display: flex; flex-direction: column; gap: 0.125rem;">
                    <?php foreach ($links as $link):
                        $is_active = ($current_page === $link['href']);
                        ?>
                        <a href="<?= $link['href'] ?>"
                            style="display: flex; align-items: center; gap: 0.625rem; padding: 0.5rem 0.625rem; border-radius: 0.5rem; font-size: 0.8125rem; font-weight: <?= $is_active ? '700' : '500' ?>; color: <?= $is_active ? 'var(--primary)' : 'var(--text-secondary)' ?>; background: <?= $is_active ? 'var(--primary-light)' : 'transparent' ?>; border: 1px solid <?= $is_active ? 'rgba(59,130,246,0.2)' : 'transparent' ?>; transition: all var(--transition-fast); text-decoration: none; position: relative;"
                            onmouseover="if(!this.dataset.active){this.style.background='var(--bg-body)';this.style.color='var(--text-primary)';}"
                            onmouseout="if(!this.dataset.active){this.style.background='transparent';this.style.color='var(--text-secondary)';}"
                            <?= $is_active ? "data-active='1'" : '' ?>>
                            <?php if ($is_active): ?>
                                <span
                                    style="position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 1.25rem; background: var(--primary); border-radius: 0 3px 3px 0;"></span>
                            <?php endif; ?>
                            <i data-lucide="<?= $link['icon'] ?>"
                                style="width: 0.9375rem; height: 0.9375rem; flex-shrink: 0;"></i>
                            <?= $link['label'] ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Footer / Sign out -->
    <div style="padding: 0.75rem; border-top: 1px solid var(--border-color);">
        <!-- Admin info -->
        <div
            style="display: flex; align-items: center; gap: 0.625rem; padding: 0.625rem; border-radius: 0.625rem; background: var(--bg-body); border: 1px solid var(--border-color); margin-bottom: 0.5rem;">
            <div
                style="width: 1.875rem; height: 1.875rem; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--accent-purple)); display: flex; align-items: center; justify-content: center; color: white; font-size: 0.6875rem; font-weight: 700; flex-shrink: 0;">
                <?= strtoupper(substr($_SESSION['admin'] ?? 'A', 0, 1)) ?>
            </div>
            <div style="min-width: 0; flex: 1;">
                <div
                    style="font-size: 0.8125rem; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?= htmlspecialchars($_SESSION['admin'] ?? 'Admin') ?>
                </div>
                <div style="font-size: 0.625rem; color: var(--text-muted); font-weight: 600;">Super Administrator</div>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout">
            <i data-lucide="log-out" style="width: 0.9375rem; height: 0.9375rem; flex-shrink: 0;"></i>
            <span>Sign Out</span>
        </a>
    </div>
</aside>