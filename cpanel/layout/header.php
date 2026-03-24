<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

// Page Titles map (matches WHM pattern)
$page_titles = [
    'index.php'           => ['title' => 'Overview',       'icon' => 'layout-dashboard'],
    'files.php'           => ['title' => 'File Manager',   'icon' => 'folder-open'],
    'billing.php'         => ['title' => 'Billing History','icon' => 'credit-card'],
    'databases.php'       => ['title' => 'Databases',      'icon' => 'database'],
    'emails.php'          => ['title' => 'Email Accounts', 'icon' => 'mail'],
    'domains.php'         => ['title' => 'Domains & DNS',  'icon' => 'globe'],
    'traffic.php'         => ['title' => 'Traffic & Stats','icon' => 'activity'],
    'tools.php'           => ['title' => 'Tools',          'icon' => 'wrench'],
    'apps.php'            => ['title' => 'App Installer',  'icon' => 'box'],
    'security.php'        => ['title' => 'Security',       'icon' => 'shield'],
    'backups.php'         => ['title' => 'Backups',        'icon' => 'save'],
    'editor.php'          => ['title' => 'Editor',         'icon' => 'file-edit'],
    'diagnostic.php'      => ['title' => 'Diagnostics',    'icon' => 'activity'],
];
$page_meta = $page_titles[$current_page] ?? ['title' => 'Client Portal', 'icon' => 'layout-dashboard'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= htmlspecialchars($page_meta['title']) ?> | <?= get_branding() ?></title>
    <!-- Modern Vanilla CSS System -->
    <link rel="stylesheet" href="../../assets/css/modern-design.css">
    <link rel="stylesheet" href="../../assets/css/premium-glass.css">

    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body style="display: flex; height: 100vh; overflow: hidden; background: var(--bg-body); color: var(--text-primary); font-family: var(--font-premium);">

    <!-- Premium Aura Background -->
    <div class="aura-bg">
        <div class="aura-1"></div>
        <div class="aura-2"></div>
    </div>

    <!-- Theme: apply immediately to prevent flash -->
    <script>
        (function () {
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <!-- Mobile backdrop -->
    <div class="mobile-backdrop" id="mobileBackdrop" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content" style="flex: 1; display: flex; flex-direction: column; overflow: hidden; position: relative; min-width: 0;">
        <!-- Top Header -->
        <header class="premium-glass"
            style="height: 3.75rem; margin: 0.75rem 1rem 0; padding: 0 1.25rem; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 10; flex-shrink: 0; gap: 1rem; border-radius: 0.875rem;">

            <!-- Left: hamburger + page title -->
            <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                <!-- Mobile hamburger -->
                <button onclick="toggleSidebar()" class="show-mobile btn-icon-circle" title="Menu"
                    style="background: transparent; border: 1px solid var(--border-color);">
                    <i data-lucide="menu" style="width: 1.125rem; height: 1.125rem;"></i>
                </button>

                <!-- Page title -->
                <div class="page-title-bar">
                    <div class="page-icon">
                        <i data-lucide="<?= $page_meta['icon'] ?>" style="width: 1rem; height: 1rem;"></i>
                    </div>
                    <h1><?= htmlspecialchars($page_meta['title']) ?></h1>
                </div>
            </div>

            <!-- Right: actions -->
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
                <!-- System status -->
                <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.75rem; border-radius: var(--radius-full); background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.15);"
                    class="hidden-mobile">
                    <span style="position: relative; display: inline-flex; height: 0.5rem; width: 0.5rem;">
                        <span style="animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite; position: absolute; display: inline-flex; height: 100%; width: 100%; border-radius: 9999px; background: var(--accent-emerald); opacity: 0.75;"></span>
                        <span style="position: relative; display: inline-flex; border-radius: 9999px; height: 0.5rem; width: 0.5rem; background: var(--accent-emerald);"></span>
                    </span>
                    <span style="font-size: 0.6875rem; font-weight: 700; color: var(--accent-emerald); font-family: monospace; letter-spacing: 0.05em;">ONLINE</span>
                </div>

                <!-- Theme toggle -->
                <button onclick="toggleTheme()" title="Toggle Dark Mode" class="btn-icon-circle">
                    <i data-lucide="moon" id="themeIcon" style="width: 1.0625rem; height: 1.0625rem;"></i>
                </button>

                <!-- User badge -->
                <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.75rem; border-radius: 9999px; border: 1px solid var(--border-color); background: var(--bg-surface); cursor: pointer; transition: all 0.2s ease;"
                    onmouseover="this.style.borderColor='var(--primary)'"
                    onmouseout="this.style.borderColor='var(--border-color)'">
                    <div style="width: 24px; height: 24px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--secondary)); display: flex; align-items: center; justify-content: center; font-size: 0.625rem; font-weight: 700; color: white; box-shadow: var(--shadow-sm);">
                        <?= strtoupper(substr($username, 0, 1)) ?>
                    </div>
                    <span style="font-size: 0.8125rem; font-weight: 600; color: var(--text-primary); padding-right: 2px;" class="hidden-mobile"><?= htmlspecialchars($username) ?></span>
                    <i data-lucide="chevron-down" style="width: 12px; height: 12px; color: var(--text-muted);"></i>
                </div>
            </div>
        </header>

        <div style="flex: 1; overflow-y: auto; padding: 1.5rem; padding-bottom: 6rem;" class="custom-scrollbar">

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

                document.addEventListener('DOMContentLoaded', () => {
                    if (document.documentElement.getAttribute('data-theme') === 'dark') {
                        document.getElementById('themeIcon').setAttribute('data-lucide', 'sun');
                        lucide.createIcons();
                    }
                });
            </script>
