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
    <script src="https://cdn.tailwindcss.com"></script>
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
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
            border-radius: 1rem;
        }

        .glass-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            border-radius: 1rem;
        }

        /* ── Sidebar nav ── */
        .nav-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.18s;
            color: #64748b;
            margin-bottom: 2px;
            text-decoration: none;
        }

        .nav-btn:hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        .nav-btn.active {
            background: #eff6ff;
            color: #2563eb;
            font-weight: 600;
        }

        .nav-btn.active svg,
        .nav-btn.active i {
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

        /* ── Global light overrides ── */
        [class*="bg-slate-9"],
        [class*="bg-slate-8"] {
            background-color: #f1f5f9 !important;
        }

        [class*="border-slate-9"],
        [class*="border-slate-8"] {
            border-color: #e2e8f0 !important;
        }

        [class*="text-white"]:not(button):not(.btn):not([class*="bg-blue"]):not([class*="bg-indigo"]):not([class*="bg-red"]):not([class*="bg-green"]):not([class*="bg-emerald"]):not([class*="bg-amber"]):not([class*="bg-violet"]) {
            color: #1e293b !important;
        }

        input[type=text],
        input[type=number],
        input[type=email],
        input[type=password],
        select,
        textarea {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #1e293b;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
    </style>
</head>

<body class="flex h-screen overflow-hidden text-sm">

    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="flex-1 flex flex-col h-full bg-[#f8fafc] relative overflow-hidden">
        <!-- Top Header -->
        <header
            class="h-14 px-8 flex items-center justify-between border-b border-slate-200 bg-white/80 backdrop-blur-md sticky top-0 z-10 shadow-sm w-full">
            <div class="flex items-center gap-3">
                <span class="relative flex h-2 w-2">
                    <span
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                </span>
                <span class="text-[10px] font-bold text-emerald-600 font-mono tracking-widest uppercase">System
                    Online</span>
            </div>
            <div class="flex items-center gap-3">
                <div
                    class="flex items-center gap-2 px-3 py-1.5 bg-slate-100 rounded-full border border-slate-200 hover:border-slate-300 transition cursor-pointer">
                    <div
                        class="w-6 h-6 rounded-full bg-gradient-to-tr from-blue-500 to-indigo-600 flex items-center justify-center text-[10px] font-bold text-white shadow">
                        <?= strtoupper(substr($username, 0, 1)) ?>
                    </div>
                    <span class="text-xs font-semibold text-slate-700 pr-1"><?= htmlspecialchars($username) ?></span>
                    <i data-lucide="chevron-down" class="w-3 h-3 text-slate-400"></i>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8 pb-24 custom-scrollbar">