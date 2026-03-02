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
    <link rel="stylesheet" href="/whm/css/modern-design.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        /* ── Cards ── */
        .glass-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            border-radius: 1rem;
        }

        .glass-panel {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border-radius: 1rem;
        }

        /* ── Sidebar Nav ── */
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 13px;
            color: #475569;
            transition: all 0.18s;
            margin-bottom: 2px;
            text-decoration: none;
        }

        .nav-link:hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        .nav-link.active {
            background: #eff6ff;
            color: #2563eb;
            font-weight: 600;
        }

        .nav-link.active svg {
            color: #2563eb;
        }

        /* ── Scrollbar ── */
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 20px;
        }

        /* ── Utility overrides for light theme ── */
        /* Tables */
        table {
            border-collapse: collapse;
        }

        th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        tr:hover td {
            background: #f8fafc;
        }

        /* Inputs */
        input[type=text],
        input[type=number],
        input[type=email],
        input[type=password],
        select,
        textarea {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #1e293b;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Badges */
        .badge-active {
            background: #dcfce7;
            color: #16a34a;
        }

        .badge-warning {
            background: #fef9c3;
            color: #ca8a04;
        }

        .badge-error {
            background: #fee2e2;
            color: #dc2626;
        }

        /* Slate-X overrides — force light bg on panels that hardcode slate-9xx */
        [class*="bg-slate-9"],
        [class*="bg-slate-8"] {
            background-color: #f1f5f9 !important;
        }

        [class*="border-slate-9"],
        [class*="border-slate-8"] {
            border-color: #e2e8f0 !important;
        }

        [class*="text-slate-4"],
        [class*="text-slate-5"] {
            color: #475569 !important;
        }

        [class*="text-white"]:not(button):not(.btn):not([class*="bg-blue"]):not([class*="bg-indigo"]):not([class*="bg-red"]):not([class*="bg-green"]):not([class*="bg-emerald"]):not([class*="bg-amber"]):not([class*="bg-violet"]) {
            color: #1e293b !important;
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
                    style="padding: 0.125rem 0.5rem; border-radius: 0.375rem; background: var(--slate-100); border: 1px solid var(--slate-200); font-size: 0.625rem; font-weight: 700; color: var(--slate-500);">v5.0-STABLE</span>
            </div>
        </header>

        <div style="flex: 1; overflow-y: auto; padding: 2rem; padding-bottom: 6rem;" class="custom-scrollbar">