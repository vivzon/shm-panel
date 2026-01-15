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
    <title>Vivzon Cpanel | Client Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: #f1f5f9;
        }

        /* Glass Cards */
        .glass-card {
            background: rgba(30, 41, 59, 0.6);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
            border-radius: 1.5rem;
        }

        /* Sidebar Nav */
        .nav-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            color: #94a3b8;
            margin-bottom: 4px;
            border-left: 3px solid transparent;
            text-decoration: none;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.03);
            color: #e2e8f0;
        }

        .nav-btn.active {
            background: rgba(37, 99, 235, 0.1);
            color: #60a5fa;
            border: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: 0 0 15px rgba(37, 99, 235, 0.1);
        }

        .nav-btn.active i {
            color: #60a5fa;
            stroke-width: 2.5px;
        }
    </style>
</head>

<body class="flex h-screen overflow-hidden text-sm">

    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="flex-1 flex flex-col h-full bg-[#0b1120] relative overflow-hidden">
        <!-- Top Header -->
        <header
            class="h-16 px-8 flex items-center justify-between border-b border-slate-800 bg-slate-900/50 backdrop-blur-md sticky top-0 z-10 w-full">
            <div class="flex items-center gap-4">
                <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse shadow-[0_0_10px_#10b981]"></span>
                <span class="text-xs font-bold text-slate-400 font-mono">SYSTEM ONLINE</span>
            </div>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-slate-800 rounded-full border border-slate-700">
                    <div
                        class="w-6 h-6 rounded-full bg-gradient-to-tr from-blue-500 to-purple-500 flex items-center justify-center text-[10px] font-bold text-white">
                        <?= strtoupper(substr($username, 0, 1)) ?>
                    </div>
                    <span class="text-xs font-bold text-slate-300">
                        <?= $username ?>
                    </span>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8 pb-24">