<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);

// Page Titles
$page_titles = [
    'index.php' => ['title' => 'Dashboard Overview', 'icon' => 'layout-dashboard'],
    'accounts.php' => ['title' => 'User Accounts', 'icon' => 'users'],
    'packages.php' => ['title' => 'Service Packages', 'icon' => 'package'],
    'services.php' => ['title' => 'Service Engine', 'icon' => 'cpu'],
    'server_config.php' => ['title' => 'Server Config', 'icon' => 'server-cog'],
    'tools.php' => ['title' => 'System Tools', 'icon' => 'wrench'],
    'terminal.php' => ['title' => 'Web Terminal', 'icon' => 'terminal'],
    'logs.php' => ['title' => 'Security Monitor', 'icon' => 'shield-alert'],
    'files-sh.php' => ['title' => 'File Manager', 'icon' => 'folder-open'],
];
$page_meta = $page_titles[$current_page] ?? ['title' => 'WHM Console', 'icon' => 'monitor'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= htmlspecialchars($page_meta['title']) ?> | <?= htmlspecialchars(get_branding()) ?></title>
    <link rel="stylesheet" href="../../assets/css/modern-design.css">
    <link rel="stylesheet" href="../../assets/css/premium-glass.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* WHM-specific: slimmer nav links */
        .nav-link {
            font-size: 0.8125rem;
            padding: 0.5rem 0.75rem;
        }

        /* Breadcrumb style page indicator */
        .page-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem 0.25rem 0.5rem;
            background: var(--bg-body);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-secondary);
        }
    </style>
</head>

<body
    style="display: flex; height: 100vh; overflow: hidden; font-size: 0.875rem; background: var(--bg-body); color: var(--text-primary); font-family: var(--font-premium);">
    
    <!-- Premium Aura Background -->
    <div class="aura-bg">
        <div class="aura-1"></div>
        <div class="aura-2"></div>
    </div>

    <script>
        // Apply theme immediately to prevent flash
        (function () {
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <div class="mobile-backdrop" id="mobileBackdrop" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content"
        style="flex: 1; display: flex; flex-direction: column; height: 100%; position: relative; overflow: hidden; min-width: 0;">

        <!-- Top Header -->
        <header class="premium-glass"
            style="height: 3.75rem; margin: 0.75rem 1rem 0; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 10; flex-shrink: 0; gap: 1rem; border-radius: 0.875rem;">
            <!-- Left: hamburger + page info -->
            <div style="display: flex; align-items: center; gap: 0.875rem; min-width: 0;">
                <button onclick="toggleSidebar()" class="show-mobile"
                    style="background: transparent; border: none; color: var(--text-primary); cursor: pointer; padding: 0.375rem; border-radius: 0.5rem; display: flex; align-items: center; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);"
                    onmouseover="this.style.background='var(--bg-body)'; this.style.transform='scale(1.05)';"
                    onmouseout="this.style.background='transparent'; this.style.transform='scale(1)';">
                    <i data-lucide="menu" style="width: 1.25rem; height: 1.25rem;"></i>
                </button>

                <!-- Page title area -->
                <div style="display: flex; align-items: center; gap: 0.625rem; min-width: 0;">
                    <div
                        style="padding: 0.375rem; background: var(--primary-light); border-radius: 0.5rem; color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <i data-lucide="<?= $page_meta['icon'] ?>" style="width: 1rem; height: 1rem;"></i>
                    </div>
                    <h1
                        style="font-size: 0.9375rem; font-weight: 500; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-family: var(--font-heading);">
                        <?= htmlspecialchars($page_meta['title']) ?>
                    </h1>
                </div>
            </div>

            <!-- Right: actions -->
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
                <!-- System status ping -->
                <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.75rem; border-radius: var(--radius-full); background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.15);"
                    class="hidden-mobile">
                    <span style="position: relative; display: inline-flex; height: 0.5rem; width: 0.5rem;">
                        <span
                            style="animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite; position: absolute; display: inline-flex; height: 100%; width: 100%; border-radius: 9999px; background: var(--accent-emerald); opacity: 0.75;"></span>
                        <span
                            style="position: relative; display: inline-flex; border-radius: 9999px; height: 0.5rem; width: 0.5rem; background: var(--accent-emerald);"></span>
                    </span>
                    <span
                        style="font-size: 0.6875rem; font-weight: 500; color: var(--accent-emerald); font-family: monospace; letter-spacing: 0.05em;">ONLINE</span>
                </div>

                <!-- Theme toggle -->
                <button onclick="toggleTheme()" title="Toggle Dark Mode"
                    style="background: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); cursor: pointer; padding: 0.4375rem; border-radius: 0.5rem; transition: all var(--transition-fast) cubic-bezier(0.4, 0, 0.2, 1); display: flex; align-items: center; justify-content: center;"
                    onmouseover="this.style.background='var(--bg-body)'; this.style.color='var(--text-primary)'; this.style.borderColor='var(--primary)'; this.style.transform='scale(1.05)';"
                    onmouseout="this.style.background='transparent'; this.style.color='var(--text-secondary)'; this.style.borderColor='var(--border-color)'; this.style.transform='scale(1)';">
                    <i data-lucide="moon" id="themeIcon" style="width: 1.0625rem; height: 1.0625rem;"></i>
                </button>

                <!-- Panel version badge -->
                <span
                    style="padding: 0.25rem 0.625rem; border-radius: var(--radius-full); background: var(--primary-light); border: 1px solid rgba(59, 130, 246, 0.2); font-size: 0.625rem; font-weight: 800; color: var(--primary); letter-spacing: 0.05em; white-space: nowrap;">
                    <?= defined('PANEL_VERSION') ? htmlspecialchars(PANEL_VERSION) : 'v5.0' ?>
                </span>
            </div>
        </header>

        <div style="flex: 1; overflow-y: auto; padding: 1.5rem; padding-bottom: 4rem;" class="custom-scrollbar">

            <script>
                function toggleSidebar() {
                    const sidebar = document.getElementById('sidebar');
                    const backdrop = document.getElementById('mobileBackdrop');
                    sidebar.classList.toggle('open');
                    backdrop.classList.toggle('open');
                }

                function toggleTheme() {
                    const html = document.documentElement;
                    const icon = document.getElementById('themeIcon');
                    const isDark = html.getAttribute('data-theme') === 'dark';
                    if (isDark) {
                        html.removeAttribute('data-theme');
                        localStorage.setItem('theme', 'light');
                        icon.setAttribute('data-lucide', 'moon');
                    } else {
                        html.setAttribute('data-theme', 'dark');
                        localStorage.setItem('theme', 'dark');
                        icon.setAttribute('data-lucide', 'sun');
                    }
                    lucide.createIcons();
                }

                // Set correct icon on load
                document.addEventListener('DOMContentLoaded', () => {
                    if (document.documentElement.getAttribute('data-theme') === 'dark') {
                        document.getElementById('themeIcon').setAttribute('data-lucide', 'sun');
                        lucide.createIcons();
                    }
                });
            </script>
