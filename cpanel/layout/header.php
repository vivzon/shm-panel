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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= get_branding() ?> | Client Portal</title>
    <!-- Modern Vanilla CSS System -->
    <link rel="stylesheet" href="/assets/css/modern-design.css">
    <link rel="stylesheet" href="/assets/css/premium-glass.css">

    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body
    style="display: flex; height: 100vh; overflow: hidden; background: var(--bg-body); color: var(--text-primary); font-family: var(--font-premium);">

    <!-- Premium Aura Background -->
    <div class="aura-bg">
        <div class="aura-1"></div>
        <div class="aura-2"></div>
    </div>

    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="dashboard-main custom-scrollbar"
        style="flex: 1; display: flex; flex-direction: column; overflow: hidden; position: relative;">
        <!-- Top Header -->
        <header class="premium-glass"
            style="display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0.75rem; z-index: 10; padding: 1rem; margin: 0 1rem; border-radius: 0.875rem; height: 60px;">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span style="position: relative; display: flex; width: 8px; height: 8px;">
                    <span class="animate-pulse"
                        style="position: absolute; width: 100%; height: 100%; rounded-full; background: var(--secondary); opacity: 0.75; border-radius: 50%;"></span>
                    <span
                        style="position: relative; width: 8px; height: 8px; border-radius: 50%; background: var(--accent-emerald);"></span>
                </span>
                <span
                    style="font-size: 0.75rem; font-weight: 700; color: var(--accent-emerald); font-family: monospace; letter-spacing: 0.1em; text-transform: uppercase;">System
                    Online</span>
            </div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div
                    style="display: flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.75rem; border-radius: 9999px; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid var(--slate-200); background: var(--slate-100);"
                    onmouseover="this.style.transform='scale(1.05)'; this.style.borderColor='var(--primary)';"
                    onmouseout="this.style.transform='scale(1)'; this.style.borderColor='var(--slate-200)';">
                    <div
                        style="width: 24px; height: 24px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--secondary)); display: flex; align-items: center; justify-content: center; font-size: 0.625rem; font-weight: 700; color: white; box-shadow: var(--shadow-sm);">
                        <?= strtoupper(substr($username, 0, 1)) ?>
                    </div>
                    <span
                        style="font-size: 0.875rem; font-weight: 600; color: var(--slate-700); padding-right: 4px;"><?= htmlspecialchars($username) ?></span>
                    <i data-lucide="chevron-down" style="width: 12px; height: 12px; color: var(--slate-400);"></i>
                </div>
            </div>
        </header>

        <div style="flex: 1; overflow-y: auto; padding: 2rem; padding-bottom: 6rem;" class="custom-scrollbar">