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

<body style="display: flex; height: 100vh; overflow: hidden; font-size: 0.875rem;">

    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main
        style="flex: 1; display: flex; flex-direction: column; height: 100%; background: #f8fafc; position: relative; overflow: hidden;">
        <!-- Top Header -->
        <header
            style="height: 3.5rem; padding: 0 2rem; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); position: sticky; top: 0; z-index: 10; box-shadow: var(--shadow-sm);">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
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
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span
                    style="padding: 0.125rem 0.5rem; border-radius: 0.375rem; background: var(--slate-100); border: 1px solid var(--slate-200); font-size: 0.625rem; font-weight: 700; color: var(--slate-500);"><?= defined('PANEL_VERSION') ? htmlspecialchars(PANEL_VERSION) : 'v5.0' ?></span>
            </div>
        </header>

        <div style="flex: 1; overflow-y: auto; padding: 2rem; padding-bottom: 6rem;" class="custom-scrollbar">