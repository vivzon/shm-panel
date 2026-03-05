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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= get_branding() ?> | Admin Console</title>
    <link rel="stylesheet" href="/assets/css/modern-design.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* WHM-specific sizing tweaks – all colours/shadows come from modern-design.css */
        .nav-link {
            font-size: 0.8125rem;
            padding: 0.5rem 0.875rem;
        }
    </style>
</head>

<body style="display: flex; height: 100vh; overflow: hidden; font-size: 0.875rem; background: var(--bg-body); color: var(--text-primary);">
    <script>
        // Apply theme immediately to prevent flash
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
    <div class="mobile-backdrop" id="mobileBackdrop" onclick="toggleSidebar()"></div>
    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content"
        style="flex: 1; display: flex; flex-direction: column; height: 100%; position: relative; overflow: hidden; transition: margin 0.3s ease;">
        <!-- Top Header -->
        <header
            style="height: 4rem; padding: 0 1.5rem; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); background: var(--bg-glass); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); position: sticky; top: 0; z-index: 10; box-shadow: var(--shadow-sm);">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <button onclick="toggleSidebar()" class="show-mobile" style="background: transparent; border: none; color: var(--text-primary); cursor: pointer; padding: 0.25rem;">
                    <i data-lucide="menu" style="width: 1.5rem; height: 1.5rem;"></i>
                </button>
                <span style="position: relative; display: flex; height: 0.5rem; width: 0.5rem;">
                    <span
                        style="animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite; position: absolute; display: inline-flex; height: 100%; width: 100%; border-radius: 9999px; background: #34d399; opacity: 0.75;"></span>
                    <span
                        style="position: relative; display: inline-flex; border-radius: 9999px; height: 0.5rem; width: 0.5rem; background: var(--success);"></span>
                </span>
                <span
                    style="font-size: 0.625rem; font-weight: 700; color: #059669; font-family: monospace; letter-spacing: 0.1em; text-transform: uppercase;">System
                    Online</span>
            </div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <button onclick="toggleTheme()" class="theme-toggle" title="Toggle Dark Mode" style="background: transparent; border: none; color: var(--text-secondary); cursor: pointer; padding: 0.5rem; border-radius: 50%; transition: all var(--transition-fast); display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='var(--primary-light)'; this.style.color='var(--primary)'" onmouseout="this.style.background='transparent'; this.style.color='var(--text-secondary)'">
                    <i data-lucide="moon" id="themeIcon" style="width: 1.25rem; height: 1.25rem;"></i>
                </button>
                <span
                    style="padding: 0.25rem 0.75rem; border-radius: 9999px; background: var(--primary-light); border: 1px solid rgba(59, 130, 246, 0.2); font-size: 0.625rem; font-weight: 800; color: var(--primary); letter-spacing: 0.05em;"><?= defined('PANEL_VERSION') ? htmlspecialchars(PANEL_VERSION) : 'v5.0' ?></span>
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
                if (html.getAttribute('data-theme') === 'dark') {
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

            // Init icon correct state
            document.addEventListener('DOMContentLoaded', () => {
                if (document.documentElement.getAttribute('data-theme') === 'dark') {
                    document.getElementById('themeIcon').setAttribute('data-lucide', 'sun');
                    lucide.createIcons();
                }
            });
        </script>