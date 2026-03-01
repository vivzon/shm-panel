<?php
/**
 * VIVZON CLOUD - LANDING PAGE (v6.0 Enhanced)
 */
require_once __DIR__ . '/../shared/config.php';

$host = $_SERVER['HTTP_HOST'];
if (filter_var($host, FILTER_VALIDATE_IP)) {
    $base = $host;
    $scheme = "http://";
} else {
    $parts = explode('.', $host);
    $base = implode('.', array_slice($parts, -2));
    $scheme = isset($_SERVER['HTTPS']) ? "https://" : "http://";
}

$clientUrl = $scheme . 'client.' . $base;
$brandName = get_branding();

// Fetch plans from DB for pricing section
try {
    $plans = $pdo->query("SELECT * FROM packages ORDER BY price ASC LIMIT 3")->fetchAll();
} catch (Exception $e) {
    $plans = [];
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
        content="<?= e($brandName) ?> — Enterprise-grade NVMe cloud hosting with 99.9% uptime, DDoS protection, global CDN, and 24/7 expert support.">
    <title><?= e($brandName) ?> | Premium Cloud Hosting</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        blue: {
                            50: '#f0f5ff',
                            100: '#e0ebff',
                            200: '#cce0ff',
                            300: '#99c2ff',
                            400: '#66a3ff',
                            500: '#4880ed',
                            600: '#2563eb', /* Primary */
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                        indigo: {
                            50: '#f2f4fb',
                            100: '#e6ebfb',
                            200: '#cdcdfa',
                            300: '#9ea6eb',
                            400: '#6f7ee1',
                            500: '#3f51b5', /* Secondary */
                            600: '#36469b',
                            700: '#2c397e',
                            800: '#242f67',
                            900: '#1f2752',
                        }
                    }
                }
            }
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    <style>
        :root {
            --blue: #2563eb;
            --blue-glow: rgba(37, 99, 235, 0.35);
            --purple: #7c3aed;
            --glass: rgba(248, 250, 252, 0.85);
        }

        * {
            box-sizing: border-box;
        }

        html {
            font-size: 16px;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
            color: #1e293b;
            overflow-x: hidden;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        /* ── Gradient Text ── */
        .gradient-text {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #4f46e5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ── Glass Components ── */
        .glass {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid #cbd5e1;
        }

        .glass-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 16px rgba(100, 116, 139, 0.15), 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        }

        .glass-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 48px rgba(100, 116, 139, 0.18), 0 0 0 1px rgba(59, 130, 246, 0.2);
            border-color: rgba(59, 130, 246, 0.25);
        }

        /* ── Ambient Blobs ── */
        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.5;
            pointer-events: none;
        }

        /* ── Animations ── */
        @keyframes float {

            0%,
            100% {
                transform: translateY(0px) rotate(0deg);
            }

            50% {
                transform: translateY(-24px) rotate(2deg);
            }
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes pulse-ring {
            0% {
                transform: scale(1);
                opacity: 0.6;
            }

            100% {
                transform: scale(2.5);
                opacity: 0;
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -200% center;
            }

            100% {
                background-position: 200% center;
            }
        }

        @keyframes counter {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .animate-float {
            animation: float 7s ease-in-out infinite;
        }

        .animate-fade-up {
            animation: fadeUp 0.8s ease both;
        }

        .animate-slide-in {
            animation: slideIn 0.6s ease both;
        }

        .delay-100 {
            animation-delay: 0.1s;
        }

        .delay-200 {
            animation-delay: 0.2s;
        }

        .delay-300 {
            animation-delay: 0.3s;
        }

        .delay-400 {
            animation-delay: 0.4s;
        }

        .delay-500 {
            animation-delay: 0.5s;
        }

        /* ── Navbar ── */
        .navbar-scrolled {
            background: rgba(255, 255, 255, 0.97) !important;
            box-shadow: 0 1px 0 #e2e8f0, 0 4px 24px rgba(100, 116, 139, 0.12);
        }

        /* ── Hero Badge ── */
        .hero-badge {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(124, 58, 237, 0.08));
            border: 1px solid rgba(99, 102, 241, 0.25);
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.08);
        }

        /* ── CTA Buttons ── */
        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #4f46e5);
            box-shadow: 0 10px 40px -8px rgba(37, 99, 235, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 50px -8px rgba(37, 99, 235, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.15);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            transform: translateY(-2px);
        }

        /* ── Stats ── */
        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(100, 116, 139, 0.08);
            transition: all 0.3s;
        }

        .stat-card:hover {
            border-color: rgba(59, 130, 246, 0.3);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.1);
        }

        /* ── Feature Icon ── */
        .feature-icon-wrap {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            position: relative;
        }

        .feature-icon-wrap::after {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 17px;
            border: 1px solid rgba(226, 232, 240, 0.9);
        }

        /* ── Pricing ── */
        .pricing-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 16px rgba(100, 116, 139, 0.08);
            border-radius: 24px;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .pricing-card::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: linear-gradient(135deg, transparent 0%, rgba(59, 130, 246, 0.03) 100%);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .pricing-card:hover {
            transform: translateY(-8px);
            border-color: rgba(59, 130, 246, 0.25);
        }

        .pricing-card:hover::before {
            opacity: 1;
        }

        .pricing-card.featured {
            border-color: rgba(59, 130, 246, 0.5);
            background: linear-gradient(145deg, rgba(37, 99, 235, 0.12), rgba(15, 23, 42, 0.9));
            box-shadow: 0 0 60px rgba(37, 99, 235, 0.2), 0 20px 60px rgba(0, 0, 0, 0.4);
        }

        /* ── Shimmer Line ── */
        .shimmer-line {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(99, 102, 241, 0.6), transparent);
            background-size: 200% 100%;
            animation: shimmer 3s linear infinite;
        }

        /* ── Trust Logos ── */
        .trust-logo-wrap {
            filter: brightness(0) invert(1);
            opacity: 0.25;
            transition: opacity 0.3s;
        }

        .trust-logo-wrap:hover {
            opacity: 0.6;
        }

        /* ── Testimonial ── */
        .testimonial-card {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.5), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            transition: all 0.3s;
        }

        .testimonial-card:hover {
            border-color: rgba(99, 102, 241, 0.2);
            transform: translateY(-4px);
        }

        /* ── Custom Scrollbar ── */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #020617;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(99, 102, 241, 0.4);
            border-radius: 10px;
        }

        /* ── Section Divider ── */
        .section-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.06), transparent);
        }

        /* ── Live Dot ── */
        .live-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6);
            animation: dot-ping 2s ease-out infinite;
        }

        @keyframes dot-ping {
            0% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6);
            }

            70% {
                box-shadow: 0 0 0 8px rgba(16, 185, 129, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        /* ── Nav link hover ── */
        .nav-link {
            position: relative;
            color: #94a3b8;
            transition: color 0.2s;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            right: 0;
            height: 1px;
            background: #3b82f6;
            transform: scaleX(0);
            transition: transform 0.25s ease;
        }

        .nav-link:hover {
            color: #fff;
        }

        .nav-link:hover::after {
            transform: scaleX(1);
        }

        /* ── Check mark ── */
        .check-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .check-icon {
            width: 20px;
            height: 20px;
            min-width: 20px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 2px;
        }
    </style>
</head>

<body class="antialiased selection:bg-blue-500/30 selection:text-white">

    <!-- Ambient Background -->
    <div class="fixed inset-0 pointer-events-none overflow-hidden" aria-hidden="true">
        <div class="blob w-[900px] h-[900px] bg-blue-100/50 top-[-20%] left-[-15%] animate-float"></div>
        <div class="blob w-[700px] h-[700px] bg-purple-100/40 bottom-[-15%] right-[-10%]"
            style="animation: float 9s ease-in-out infinite reverse;"></div>
        <div class="blob w-[400px] h-[400px] bg-indigo-100/40 top-[40%] left-[50%]"
            style="animation: float 11s 2s ease-in-out infinite;"></div>

        <!-- Grid overlay -->
        <div
            style="position:absolute;inset:0;background-image:linear-gradient(rgba(148,163,184,0.1) 1px,transparent 1px),linear-gradient(90deg,rgba(148,163,184,0.1) 1px,transparent 1px);background-size:60px 60px;">
        </div>
    </div>

    <!-- ══════════════════════════════════════
         NAVIGATION
    ══════════════════════════════════════ -->
    <nav id="navbar" class="fixed w-full z-50 transition-all duration-500 backdrop-blur-md border-b border-slate-200/50"
        style="background: rgba(255,255,255,0.7);">
        <div class="max-w-7xl mx-auto px-6 h-18 flex items-center justify-between" style="height:72px;">

            <!-- Logo -->
            <a href="#" class="flex items-center gap-3 group">
                <div class="relative">
                    <div
                        class="bg-gradient-to-br from-blue-500 to-indigo-600 p-2.5 rounded-xl shadow-lg shadow-blue-500/20 transition group-hover:shadow-blue-500/40 group-hover:scale-105">
                        <i data-lucide="cloud" class="w-5 h-5 text-white"></i>
                    </div>
                </div>
                <span
                    class="text-lg font-bold font-heading tracking-tight text-slate-800"><?= e(strtoupper($brandName)) ?></span>
            </a>

            <!-- Desktop Nav Links -->
            <div class="hidden md:flex items-center gap-8 text-sm font-medium">
                <a href="#features" class="nav-link">Features</a>
                <a href="#stats" class="nav-link">Performance</a>
                <a href="#pricing" class="nav-link">Pricing</a>
                <a href="#testimonials" class="nav-link">Reviews</a>
            </div>

            <!-- CTA -->
            <div class="flex items-center gap-3">
                <a href="<?= e($clientUrl) ?>"
                    class="hidden sm:inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-slate-900 transition px-4 py-2">
                    <i data-lucide="log-in" class="w-4 h-4"></i> Login
                </a>
                <a href="#pricing"
                    class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white">
                    Get Started <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    </nav>

    <!-- ══════════════════════════════════════
         HERO
    ══════════════════════════════════════ -->
    <section class="relative min-h-screen flex items-center pt-20 pb-24 px-6 overflow-hidden">
        <div class="max-w-7xl mx-auto w-full relative z-10">
            <div class="text-center max-w-5xl mx-auto">

                <!-- Live status badge -->
                <div
                    class="animate-fade-up inline-flex items-center gap-3 hero-badge px-5 py-2.5 rounded-full text-xs font-bold tracking-widest uppercase mb-10">
                    <div class="live-dot"></div>
                    <span class="text-indigo-300">All Systems Operational · 99.97% Uptime This Month</span>
                </div>

                <!-- Headline -->
                <h1
                    class="animate-fade-up delay-100 font-heading text-5xl sm:text-7xl lg:text-8xl font-bold tracking-tight leading-[1.05] mb-8">
                    <span class="gradient-text">Hosting Built</span><br>
                    <span class="text-slate-900">For Builders.</span>
                </h1>

                <!-- Sub-headline -->
                <p
                    class="animate-fade-up delay-200 text-slate-600 text-lg sm:text-xl max-w-2xl mx-auto mb-12 leading-relaxed font-light">
                    Deploy on <span class="text-slate-800 font-semibold">NVMe-powered cloud</span> in seconds.
                    Automatic SSL, DDoS protection, and a control panel that actually makes sense.
                </p>

                <!-- Action Buttons -->
                <div
                    class="animate-fade-up delay-300 flex flex-col sm:flex-row items-center justify-center gap-4 mb-16">
                    <a href="#pricing"
                        class="btn-primary w-full sm:w-auto px-8 py-4 rounded-2xl font-bold text-base flex items-center justify-center gap-2 group text-white">
                        View Plans
                        <i data-lucide="arrow-right" class="w-5 h-5 group-hover:translate-x-1 transition"></i>
                    </a>
                    <a href="<?= e($clientUrl) ?>"
                        class="btn-secondary w-full sm:w-auto px-8 py-4 rounded-2xl font-bold text-base flex items-center justify-center gap-2">
                        <i data-lucide="layout-dashboard" class="w-5 h-5 text-slate-500"></i>
                        Client Portal
                    </a>
                </div>

                <!-- Trust Line -->
                <div class="animate-fade-up delay-400 section-divider mb-10"></div>
                <div
                    class="animate-fade-up delay-500 flex flex-wrap justify-center items-center gap-3 text-xs text-slate-500 font-semibold uppercase tracking-widest mb-14">
                    <span class="flex items-center gap-1.5"><i data-lucide="check-circle-2"
                            class="w-3.5 h-3.5 text-emerald-500"></i> No Setup Fees</span>
                    <span class="text-slate-700">·</span>
                    <span class="flex items-center gap-1.5"><i data-lucide="check-circle-2"
                            class="w-3.5 h-3.5 text-emerald-500"></i> Free SSL</span>
                    <span class="text-slate-700">·</span>
                    <span class="flex items-center gap-1.5"><i data-lucide="check-circle-2"
                            class="w-3.5 h-3.5 text-emerald-500"></i> 24/7 Support</span>
                    <span class="text-slate-700">·</span>
                    <span class="flex items-center gap-1.5"><i data-lucide="check-circle-2"
                            class="w-3.5 h-3.5 text-emerald-500"></i> 30-Day Guarantee</span>
                </div>

                <!-- Hero Dashboard Preview -->
                <div class="relative max-w-4xl mx-auto">
                    <div class="absolute inset-0 rounded-3xl bg-gradient-to-t from-[#f8fafc] via-transparent to-transparent z-10"
                        style="top:60%;"></div>
                    <div class="glass rounded-3xl p-5 shadow-2xl shadow-blue-500/10 border border-slate-200/60">
                        <!-- Fake dashboard preview bar -->
                        <div class="flex items-center gap-2 mb-4">
                            <div class="w-3 h-3 rounded-full bg-red-400"></div>
                            <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                            <div class="w-3 h-3 rounded-full bg-emerald-400"></div>
                            <div
                                class="flex-1 ml-3 h-6 rounded-lg bg-slate-100 flex items-center px-3 border border-slate-200">
                                <span
                                    class="text-[10px] font-mono text-slate-500">https://client.<?= e($base) ?>/dashboard</span>
                            </div>
                        </div>

                        <!-- Stat bars -->
                        <div class="grid grid-cols-3 gap-3 mb-4">
                            <?php
                            $metrics = [
                                ['CPU Load', '24%', 'bg-blue-500', 24, '#60a5fa'],
                                ['RAM Usage', '61%', 'bg-purple-500', 61, '#c084fc'],
                                ['Disk Space', '38%', 'bg-emerald-500', 38, '#34d399'],
                            ];
                            foreach ($metrics as $m):
                                ?>
                                <div class="bg-white rounded-xl p-4 border border-slate-100 shadow-sm">
                                    <div class="text-[10px] text-slate-500 uppercase tracking-widest mb-2"><?= $m[0] ?>
                                    </div>
                                    <div class="text-xl font-bold text-slate-800 mb-3"><?= $m[1] ?></div>
                                    <div class="h-1 bg-slate-100 rounded-full overflow-hidden">
                                        <div class="h-full <?= $m[2] ?> rounded-full" style="width:<?= $m[3] ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Fake activity rows -->
                        <div class="space-y-2">
                            <?php
                            $rows = [
                                ['domain.com', 'Active', 'bg-emerald-500'],
                                ['api.domain.com', 'Active', 'bg-emerald-500'],
                                ['staging.domain.com', 'Deploying', 'bg-yellow-500'],
                            ];
                            foreach ($rows as $r):
                                ?>
                                <div
                                    class="flex items-center justify-between bg-slate-900/40 rounded-xl px-4 py-3 border border-white/[0.03]">
                                    <div class="flex items-center gap-3">
                                        <div class="w-2 h-2 rounded-full <?= $r[2] ?>"></div>
                                        <span class="text-xs font-mono text-slate-300"><?= $r[0] ?></span>
                                    </div>
                                    <span class="text-[10px] font-bold text-slate-500"><?= $r[1] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════
         STATS STRIP
    ══════════════════════════════════════ -->
    <section id="stats" class="relative z-10 py-20 px-6">
        <div class="max-w-7xl mx-auto">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php
                $stats = [
                    ['99.97%', 'Uptime SLA', 'activity', 'text-emerald-400'],
                    ['<10ms', 'Response Time', 'zap', 'text-blue-400'],
                    ['3 DC', 'Global Locations', 'globe', 'text-indigo-400'],
                    ['24 / 7', 'Expert Support', 'headphones', 'text-purple-400'],
                ];
                foreach ($stats as $s):
                    ?>
                    <div class="stat-card rounded-2xl p-6 text-center">
                        <div class="<?= $s[3] ?> mb-2 flex justify-center">
                            <i data-lucide="<?= $s[2] ?>" class="w-6 h-6"></i>
                        </div>
                        <div class="text-3xl font-bold font-heading text-slate-800 mb-1"><?= $s[0] ?></div>
                        <div class="text-xs text-slate-500 font-semibold uppercase tracking-widest"><?= $s[1] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <div class="section-divider max-w-7xl mx-auto"></div>

    <!-- ══════════════════════════════════════
         FEATURES
    ══════════════════════════════════════ -->
    <section id="features" class="relative z-10 py-28 px-6">
        <div class="max-w-7xl mx-auto">

            <!-- Section Header -->
            <div class="text-center mb-20">
                <span
                    class="text-xs font-bold tracking-widest uppercase text-indigo-400 bg-indigo-400/10 border border-indigo-400/20 px-4 py-2 rounded-full">Why
                    <?= e($brandName) ?></span>
                <h2 class="font-heading text-4xl md:text-5xl font-bold mt-6 mb-5 text-slate-900">Enterprise-Grade, <br
                        class="hidden md:block">Developer-Friendly</h2>
                <p class="text-slate-600 max-w-xl mx-auto leading-relaxed">
                    All the power of a dedicated server, with the simplicity of a managed platform.
                </p>
            </div>

            <!-- Feature Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php
                $features = [
                    ['zap', 'from-blue-600/20 to-blue-600/5', 'text-blue-400', 'Blazing NVMe I/O', 'Up to 10× faster storage vs traditional SSD. Queries fly, apps load instantly, users stay happy.'],
                    ['shield-check', 'from-purple-600/20 to-purple-600/5', 'text-purple-400', 'DDoS Protection', 'Multi-layer traffic scrubbing absorbs attacks before they reach your app — automatically.'],
                    ['globe-2', 'from-emerald-600/20 to-emerald-600/5', 'text-emerald-400', 'Global CDN Edge', 'Content delivered from the nearest node. Lower latency means higher conversions.'],
                    ['lock', 'from-pink-600/20 to-pink-600/5', 'text-pink-400', 'Auto Free SSL', 'Let\'s Encrypt certificates issued and auto-renewed for every domain you add. Zero clicks.'],
                    ['terminal', 'from-orange-600/20 to-orange-600/5', 'text-orange-400', 'Full SSH Access', 'Root or user-level SSH, SFTP, and Git deployment hooks. Your server, your rules.'],
                    ['life-buoy', 'from-cyan-600/20 to-cyan-600/5', 'text-cyan-400', '24 / 7 Expert Support', 'Real engineers, not bots — available around the clock via ticket, chat, and phone.'],
                    ['database', 'from-violet-600/20 to-violet-600/5', 'text-violet-400', 'Managed MySQL / PG', 'Automated backups, one-click restores, and phpMyAdmin included. No DBA needed.'],
                    ['mail', 'from-rose-600/20 to-rose-600/5', 'text-rose-400', 'Business Email', 'Fully-featured mail server with spam filters, webmail, and DKIM/SPF configured out of the box.'],
                    ['refresh-cw', 'from-teal-600/20 to-teal-600/5', 'text-teal-400', 'Daily Backups', 'Automated off-site snapshots retained for 30 days. One-click point-in-time restore.'],
                ];
                foreach ($features as $f):
                    ?>
                    <div class="glass-card rounded-2xl p-7">
                        <div class="feature-icon-wrap bg-gradient-to-br <?= $f[1] ?>">
                            <i data-lucide="<?= $f[0] ?>" class="w-6 h-6 <?= $f[2] ?>"></i>
                        </div>
                        <h3 class="text-lg font-bold text-slate-800 mb-3"><?= $f[3] ?></h3>
                        <p class="text-slate-500 text-sm leading-relaxed"><?= $f[4] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <div class="section-divider max-w-7xl mx-auto"></div>

    <!-- ══════════════════════════════════════
         PRICING
    ══════════════════════════════════════ -->
    <section id="pricing" class="relative z-10 py-28 px-6">
        <div class="max-w-7xl mx-auto">

            <div class="text-center mb-20">
                <span
                    class="text-xs font-bold tracking-widest uppercase text-blue-400 bg-blue-400/10 border border-blue-400/20 px-4 py-2 rounded-full">Simple
                    Pricing</span>
                <h2 class="font-heading text-4xl md:text-5xl font-bold mt-6 mb-5 text-slate-900">Plans That Scale With
                    You
                </h2>
                <p class="text-slate-600 max-w-lg mx-auto">No hidden fees. Upgrade or downgrade anytime. Cancel with one
                    click.</p>
            </div>

            <?php if (!empty($plans)): ?>
                <div class="grid grid-cols-1 md:grid-cols-<?= min(count($plans), 3) ?> gap-6 max-w-5xl mx-auto">
                    <?php foreach ($plans as $i => $plan):
                        $featured = $i === 1;
                        $price = number_format((float) ($plan['price'] ?? 0), 0);
                        ?>
                        <div class="pricing-card p-8 <?= $featured ? 'featured' : '' ?>">
                            <?php if ($featured): ?>
                                <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2">
                                    <span
                                        class="bg-gradient-to-r from-blue-500 to-indigo-500 text-white text-[10px] font-bold uppercase tracking-widest px-4 py-1.5 rounded-full shadow-lg">Most
                                        Popular</span>
                                </div>
                            <?php endif; ?>

                            <div class="mb-6">
                                <h3 class="text-xl font-bold text-slate-900 mb-1"><?= e($plan['name'] ?? 'Plan') ?></h3>
                                <p class="text-slate-500 text-sm">Everything you need to get started</p>
                            </div>

                            <div class="mb-8">
                                <span class="text-5xl font-bold font-heading text-slate-900">₹<?= $price ?></span>
                                <span class="text-slate-500 text-sm ml-2">/ month</span>
                            </div>

                            <div class="space-y-3 mb-8">
                                <?php
                                $perks = [
                                    ($plan['disk_mb'] ?? 0) / 1024 . ' GB NVMe Storage',
                                    ($plan['max_domains'] ?? 1) . ' Domain' . (($plan['max_domains'] ?? 1) > 1 ? 's' : ''),
                                    ($plan['max_databases'] ?? 1) . ' MySQL Database' . (($plan['max_databases'] ?? 1) > 1 ? 's' : ''),
                                    ($plan['max_emails'] ?? 5) . ' Email Account' . (($plan['max_emails'] ?? 5) > 1 ? 's' : ''),
                                    'Free SSL Certificate',
                                    'DDoS Protection',
                                    '24/7 Support',
                                ];
                                foreach ($perks as $perk):
                                    ?>
                                    <div class="check-item">
                                        <div class="check-icon"><i data-lucide="check" class="w-3 h-3 text-emerald-500"></i></div>
                                        <span class="text-sm text-slate-600"><?= e($perk) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <a href="<?= e($clientUrl) ?>/checkout.php?plan=<?= (int) ($plan['id'] ?? 0) ?>" class="block text-center py-3.5 rounded-xl font-bold text-sm transition
                              <?= $featured
                                  ? 'btn-primary text-white'
                                  : 'btn-secondary text-slate-600 hover:text-slate-900' ?>">
                                Get Started →
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- Default plans if DB is empty -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-w-5xl mx-auto">
                    <?php
                    $defaultPlans = [
                        ['Starter', '₹299', '10', '1', '1', '5', false],
                        ['Business', '₹799', '50', '5', '5', '25', true],
                        ['Pro', '₹1,499', '200', '20', '20', '100', false],
                    ];
                    foreach ($defaultPlans as $pi => $dp):
                        $featured = $dp[6];
                        ?>
                        <div class="pricing-card p-8 <?= $featured ? 'featured' : '' ?>">
                            <?php if ($featured): ?>
                                <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2">
                                    <span
                                        class="bg-gradient-to-r from-blue-500 to-indigo-500 text-white text-[10px] font-bold uppercase tracking-widest px-4 py-1.5 rounded-full shadow-lg">Most
                                        Popular</span>
                                </div>
                            <?php endif; ?>
                            <div class="mb-6">
                                <h3 class="text-xl font-bold text-slate-900 mb-1"><?= $dp[0] ?></h3>
                                <p class="text-slate-500 text-sm">Perfect for
                                    <?= $pi === 0 ? 'small projects' : ($pi === 1 ? 'growing businesses' : 'large-scale apps') ?>
                                </p>
                            </div>
                            <div class="mb-8">
                                <span class="text-5xl font-bold font-heading text-white"><?= $dp[1] ?></span>
                                <span class="text-slate-400 text-sm ml-2">/ month</span>
                            </div>
                            <div class="space-y-3 mb-8">
                                <?php foreach ([
                                    $dp[2] . ' GB NVMe Storage',
                                    $dp[3] . ' Domain(s)',
                                    $dp[4] . ' MySQL Database(s)',
                                    $dp[5] . ' Email Accounts',
                                    'Free SSL',
                                    'DDoS Protection',
                                    '24/7 Support',
                                ] as $perk): ?>
                                    <div class="check-item">
                                        <div class="check-icon"><i data-lucide="check" class="w-3 h-3 text-emerald-400"></i></div>
                                        <span class="text-sm text-slate-300"><?= $perk ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <a href="<?= e($clientUrl) ?>"
                                class="block text-center py-3.5 rounded-xl font-bold text-sm transition <?= $featured ? 'btn-primary text-white' : 'btn-secondary text-slate-300 hover:text-white' ?>">
                                Get Started →
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="text-center text-slate-600 text-sm mt-10">
                Need a custom plan? <a href="mailto:support@<?= e($base) ?>"
                    class="text-blue-500 hover:text-blue-400 transition">Contact us →</a>
            </p>
        </div>
    </section>

    <div class="section-divider max-w-7xl mx-auto"></div>

    <!-- ══════════════════════════════════════
         TESTIMONIALS
    ══════════════════════════════════════ -->
    <section id="testimonials" class="relative z-10 py-28 px-6">
        <div class="max-w-7xl mx-auto">

            <div class="text-center mb-20">
                <span
                    class="text-xs font-bold tracking-widest uppercase text-purple-400 bg-purple-400/10 border border-purple-400/20 px-4 py-2 rounded-full">Loved
                    By Developers</span>
                <h2 class="font-heading text-4xl md:text-5xl font-bold mt-6 mb-5 text-slate-900">Don't Take Our Word For
                    It
                </h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php
                $testimonials = [
                    ['Switched from cPanel/WHM shared hosting — the speed difference is night and day. NVMe just works.', 'Rahul M.', 'Full-Stack Developer, Mumbai', 'RM'],
                    ['Finally a hosting panel that doesn\'t feel like it\'s from 2005. Clean UI, great support.', 'Priya S.', 'CTO, SaaS Startup', 'PS'],
                    ['Auto SSL, automatic backups, and a dashboard that shows me exactly what\'s happening. Love it.', 'Arjun K.', 'Freelance DevOps Engineer', 'AK'],
                ];
                foreach ($testimonials as $t):
                    ?>
                    <div class="testimonial-card p-7">
                        <!-- Stars -->
                        <div class="flex gap-1 mb-5">
                            <?php for ($s = 0; $s < 5; $s++): ?><i data-lucide="star"
                                    class="w-4 h-4 text-amber-400 fill-amber-400"></i><?php endfor; ?>
                        </div>
                        <p class="text-slate-600 text-sm leading-relaxed mb-6">"<?= $t[0] ?>"</p>
                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-blue-500 flex items-center justify-center text-xs font-bold text-white">
                                <?= $t[3] ?>
                            </div>
                            <div>
                                <div class="font-bold text-sm text-slate-900"><?= $t[1] ?></div>
                                <div class="text-xs text-slate-500"><?= $t[2] ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════
         CTA BAND
    ══════════════════════════════════════ -->
    <section class="relative z-10 py-28 px-6">
        <div class="max-w-4xl mx-auto text-center">
            <div class="glass-card rounded-3xl p-14 relative overflow-hidden">
                <div class="blob w-[600px] h-[600px] bg-blue-100/50 top-[-50%] left-[50%] -translate-x-1/2 animate-float"
                    style="filter:blur(80px);opacity:0.6;"></div>
                <div class="relative z-10">
                    <h2 class="font-heading text-4xl md:text-6xl font-bold text-slate-900 mb-6 leading-tight">Ready
                        to<br><span class="gradient-text">Launch Faster?</span></h2>
                    <p class="text-slate-600 text-lg mb-10 max-w-lg mx-auto">
                        Join thousands of developers who deploy on <?= e($brandName) ?> every day.
                    </p>
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                        <a href="#pricing"
                            class="btn-primary px-10 py-4 rounded-2xl font-bold text-white text-base flex items-center gap-2 group">
                            Start For Free <i data-lucide="rocket" class="w-5 h-5 group-hover:rotate-12 transition"></i>
                        </a>
                        <a href="<?= e($clientUrl) ?>"
                            class="btn-secondary px-10 py-4 rounded-2xl font-bold text-slate-600 hover:text-slate-900 text-base flex items-center gap-2">
                            <i data-lucide="log-in" class="w-5 h-5"></i> Login to Portal
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════════════
         FOOTER
    ══════════════════════════════════════ -->
    <footer class="relative z-10 border-t border-slate-200 bg-slate-50 pt-16 pb-8 px-6">
        <div class="max-w-7xl mx-auto">

            <!-- Top row -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-12 mb-14">
                <!-- Brand -->
                <div class="md:col-span-2">
                    <div class="flex items-center gap-3 mb-5">
                        <div class="bg-gradient-to-br from-blue-500 to-indigo-600 p-2.5 rounded-xl">
                            <i data-lucide="cloud" class="w-5 h-5 text-white"></i>
                        </div>
                        <span
                            class="text-lg font-bold font-heading text-slate-900"><?= e(strtoupper($brandName)) ?></span>
                    </div>
                    <p class="text-slate-500 text-sm leading-relaxed max-w-xs mb-6">
                        Enterprise-grade cloud hosting trusted by developers and agencies across India and beyond.
                    </p>
                    <div class="flex items-center gap-2 text-xs font-bold text-emerald-400">
                        <div class="live-dot"></div>
                        <span>All Systems Operational</span>
                    </div>
                </div>

                <!-- Quick Links -->
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-widest text-slate-500 mb-5">Platform</h4>
                    <div class="space-y-3">
                        <a href="#features"
                            class="block text-sm text-slate-500 hover:text-slate-900 transition">Features</a>
                        <a href="#pricing"
                            class="block text-sm text-slate-500 hover:text-slate-900 transition">Pricing</a>
                        <a href="#testimonials"
                            class="block text-sm text-slate-500 hover:text-slate-900 transition">Reviews</a>
                        <a href="<?= e($clientUrl) ?>"
                            class="block text-sm text-slate-500 hover:text-slate-900 transition">Client Portal</a>
                    </div>
                </div>

                <!-- Support -->
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-widest text-slate-500 mb-5">Support</h4>
                    <div class="space-y-3">
                        <a href="mailto:support@<?= e($base) ?>"
                            class="block text-sm text-slate-500 hover:text-slate-900 transition">Email Support</a>
                        <a href="#" class="block text-sm text-slate-500 hover:text-slate-900 transition">Privacy
                            Policy</a>
                        <a href="#" class="block text-sm text-slate-500 hover:text-slate-900 transition">Terms of
                            Service</a>
                        <a href="#" class="block text-sm text-slate-500 hover:text-slate-900 transition">SLA</a>
                    </div>
                </div>
            </div>

            <div class="shimmer-line mb-8"></div>

            <div class="flex flex-col md:flex-row items-center justify-between gap-4 text-sm text-slate-600">
                <span>&copy; <?= date('Y') ?> <?= e($brandName) ?>. All rights reserved.</span>
                <span class="flex items-center gap-2">
                    Made with <i data-lucide="heart" class="w-3.5 h-3.5 text-red-500 fill-red-500 animate-pulse"></i>
                    for the web
                </span>
            </div>
        </div>
    </footer>

    <script>
        // Init icons
        lucide.createIcons();

        // ── Scrolling Navbar ──
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 40) {
                navbar.classList.add('navbar-scrolled');
            } else {
                navbar.classList.remove('navbar-scrolled');
            }
        }, { passive: true });

        // ── Smooth scroll offset ──
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href === '#') return;
                const target = document.querySelector(href);
                if (!target) return;
                e.preventDefault();
                const top = target.getBoundingClientRect().top + window.scrollY - 80;
                window.scrollTo({ top, behavior: 'smooth' });
            });
        });

        // ── Intersection Observer for fade-in animations ──
        const obs = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.glass-card, .stat-card, .pricing-card, .testimonial-card').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            obs.observe(el);
        });
    </script>
</body>

</html>