<?php
/**
 * VIVZON CLOUD - LANDING PAGE (v5.0)
 * Premium Hosting Solutions
 */
require_once __DIR__ . '/../shared/config.php';

// Fetch Packages
try {
    $stmt = $pdo->query("SELECT * FROM packages ORDER BY price ASC");
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $packages = [];
}

$host = $_SERVER['HTTP_HOST'];
if (filter_var($host, FILTER_VALIDATE_IP)) {
    $base = $host;
    $scheme = "http://";
} else {
    $parts = explode('.', $host);
    $base = implode('.', array_slice($parts, -2));
    $scheme = "http://";
}

// Payment Gateways Config (Placeholder for Frontend)
$RazorpayEnabled = true;
$PayPalEnabled = true;
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vivzon Cloud | Next-Gen Hosting Infrastructure</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #020617;
            color: white;
            overflow-x: hidden;
        }

        .font-heading {
            font-family: 'Outfit', sans-serif;
        }

        /* Glassmorphism */
        .glass {
            background: rgba(30, 41, 59, 0.4);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .glass-card {
            background: linear-gradient(180deg, rgba(30, 41, 59, 0.6) 0%, rgba(15, 23, 42, 0.6) 100%);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        /* Animations */
        @keyframes float {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-20px);
            }
        }

        .animate-float {
            animation: float 6s ease-in-out infinite;
        }

        .blob {
            position: absolute;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.4) 0%, rgba(0, 0, 0, 0) 70%);
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
            opacity: 0.6;
        }
    </style>
</head>

<body class="antialiased selection:bg-blue-500 selection:text-white">

    <!-- Ambient Background -->
    <div class="fixed inset-0 pointer-events-none overflow-hidden">
        <div class="blob w-[800px] h-[800px] top-[-20%] left-[-10%] animate-float"></div>
        <div class="blob w-[600px] h-[600px] bottom-[-10%] right-[-10%] bg-purple-600/30 animation-delay-2000"></div>
    </div>

    <!-- Navigation -->
    <nav class="fixed w-full z-50 transition-all duration-300 backdrop-blur-md bg-[#020617]/80 border-b border-white/5">
        <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="bg-gradient-to-br from-blue-600 to-indigo-600 p-2 rounded-xl shadow-lg shadow-blue-500/20">
                    <i data-lucide="cloud" class="w-6 h-6 text-white"></i>
                </div>
                <span class="text-xl font-bold font-heading tracking-tight">VIVZON<span
                        class="text-blue-500">CLOUD</span></span>
            </div>

            <div class="hidden md:flex gap-8 text-sm font-medium text-slate-400">
                <a href="#features" class="hover:text-white transition">Features</a>
                <a href="#pricing" class="hover:text-white transition">Packages</a>
                <a href="<?= $scheme ?>client.<?= $base ?>" class="hover:text-white transition">Client Area</a>
            </div>

            <a href="#pricing"
                class="bg-white text-slate-900 px-6 py-2.5 rounded-full font-bold text-sm hover:bg-blue-50 text-center transition shadow-[0_0_20px_rgba(255,255,255,0.1)]">
                Get Started
            </a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative pt-48 pb-32 px-6 overflow-hidden">
        <div class="max-w-7xl mx-auto text-center relative z-10">
            <div
                class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass text-blue-400 text-xs font-bold tracking-widest uppercase mb-8 border border-blue-500/20 shadow-[0_0_30px_rgba(59,130,246,0.15)]">
                <span class="relative flex h-2 w-2">
                    <span
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
                </span>
                Global Infrastructure v5.0 Online
            </div>

            <h1
                class="text-6xl md:text-8xl font-bold font-heading tracking-tight mb-8 bg-clip-text text-transparent bg-gradient-to-b from-white via-white to-slate-500 leading-[1.1]">
                Cloud Hosting <br> <span class="text-blue-500">Reimagined.</span>
            </h1>

            <p class="text-slate-400 text-lg md:text-xl max-w-2xl mx-auto mb-12 leading-relaxed font-light">
                Deploy your applications in seconds on our high-performance NVMe cloud.
                Experience <span class="text-white font-medium">99.9% uptime</span>, DDoS protection, and instant
                scalability.
            </p>

            <div class="flex flex-col md:flex-row items-center justify-center gap-4">
                <a href="#pricing"
                    class="w-full md:w-auto px-8 py-4 bg-blue-600 hover:bg-blue-500 text-white rounded-2xl font-bold text-lg transition shadow-[0_10px_40px_-10px_rgba(37,99,235,0.5)] flex items-center justify-center gap-2 group">
                    Explore Packages <i data-lucide="arrow-right"
                        class="w-5 h-5 group-hover:translate-x-1 transition"></i>
                </a>
                <a href="<?= $scheme ?>client.<?= $base ?>"
                    class="w-full md:w-auto px-8 py-4 glass text-white hover:bg-white/5 rounded-2xl font-bold text-lg transition flex items-center justify-center gap-2">
                    <i data-lucide="log-in" class="w-5 h-5 text-slate-400"></i> Client Login
                </a>
            </div>

            <!-- Tech Stack -->
            <div
                class="mt-20 pt-10 border-t border-white/5 flex flex-wrap justify-center gap-12 opacity-50 grayscale hover:grayscale-0 transition duration-500">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/c/c3/Python-logo-notext.svg/1200px-Python-logo-notext.svg.png"
                    class="h-8 md:h-10 w-auto" alt="Python">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/2/27/PHP-logo.svg/2560px-PHP-logo.svg.png"
                    class="h-8 md:h-10 w-auto" alt="PHP">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/d/d9/Node.js_logo.svg/2560px-Node.js_logo.svg.png"
                    class="h-8 md:h-10 w-auto" alt="NodeJS">
                <img src="https://www.mysql.com/common/logos/logo-mysql-170x115.png" class="h-8 md:h-10 w-auto"
                    alt="MySQL">
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-32 relative z-10">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-20">
                <h2 class="text-4xl md:text-5xl font-bold font-heading mb-6">Simple, Transparent Pricing</h2>
                <p class="text-slate-400 max-w-xl mx-auto">Choose the perfect plan for your needs. Upgrade or downgrade
                    at any time with zero downtime.</p>
            </div>

            <?php if (empty($packages)): ?>
                <div class="text-center p-12 glass rounded-3xl border border-dashed border-slate-700">
                    <i data-lucide="server-off" class="w-12 h-12 text-slate-500 mx-auto mb-4"></i>
                    <h3 class="text-xl font-bold text-slate-300">No Packages Found</h3>
                    <p class="text-slate-500 mt-2">Please ask the administrator to configure packages in the database.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 items-start">
                    <?php foreach ($packages as $pkg): ?>
                        <div
                            class="glass-card p-8 rounded-[32px] group hover:-translate-y-2 transition duration-500 relative overflow-hidden">
                            <!-- Popular Badge -->
                            <?php if ($pkg['price'] > 0 && $pkg['price'] < 50): ?>
                                <div
                                    class="absolute top-0 right-0 bg-gradient-to-bl from-blue-600 to-transparent p-6 px-8 text-white font-bold text-xs tracking-widest uppercase rounded-bl-[32px]">
                                    Popular
                                </div>
                            <?php endif; ?>

                            <h3 class="text-2xl font-bold text-white mb-2"><?= htmlspecialchars($pkg['name']) ?></h3>
                            <div class="flex items-baseline gap-1 mb-6">
                                <span class="text-4xl font-bold text-blue-400">$<?= number_format($pkg['price'], 2) ?></span>
                                <span class="text-slate-500 font-medium">/mo</span>
                            </div>

                            <ul class="space-y-4 mb-8">
                                <li class="flex items-center gap-3 text-slate-300">
                                    <div class="p-1 rounded-full bg-emerald-500/20 text-emerald-400"><i data-lucide="check"
                                            class="w-3 h-3"></i></div>
                                    <span
                                        class="font-medium"><?= $pkg['disk_mb'] < 1000 ? $pkg['disk_mb'] . ' MB' : ($pkg['disk_mb'] / 1000) . ' GB' ?>
                                        NVMe Storage</span>
                                </li>
                                <li class="flex items-center gap-3 text-slate-300">
                                    <div class="p-1 rounded-full bg-emerald-500/20 text-emerald-400"><i data-lucide="check"
                                            class="w-3 h-3"></i></div>
                                    <span class="font-medium"><?= $pkg['max_domains'] ?> Domain(s)</span>
                                </li>
                                <li class="flex items-center gap-3 text-slate-300">
                                    <div class="p-1 rounded-full bg-emerald-500/20 text-emerald-400"><i data-lucide="check"
                                            class="w-3 h-3"></i></div>
                                    <span class="font-medium"><?= $pkg['max_emails'] ?> Email Accounts</span>
                                </li>
                                <li class="flex items-center gap-3 text-slate-300">
                                    <div class="p-1 rounded-full bg-emerald-500/20 text-emerald-400"><i data-lucide="check"
                                            class="w-3 h-3"></i></div>
                                    <span class="font-medium"><?= $pkg['max_databases'] ?> Databases</span>
                                </li>
                                <li class="flex items-center gap-3 text-slate-300">
                                    <div class="p-1 rounded-full bg-blue-500/20 text-blue-400"><i data-lucide="shield-check"
                                            class="w-3 h-3"></i></div>
                                    <span class="font-medium">Free SSL Certificates</span>
                                </li>
                            </ul>

                            <a href="checkout.php?pkg=<?= $pkg['id'] ?>"
                                class="block w-full py-4 rounded-xl bg-slate-800 hover:bg-blue-600 text-center font-bold text-white transition-all shadow-lg hover:shadow-blue-600/20 border border-slate-700 hover:border-blue-500">
                                Choose Plan
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-white/5 bg-[#01030b] pt-20 pb-10">
        <div class="max-w-7xl mx-auto px-6">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6 mb-12">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-800 p-2 rounded-lg">
                        <i data-lucide="cloud" class="w-6 h-6 text-white"></i>
                    </div>
                    <span class="text-xl font-bold font-heading">VIVZON<span class="text-slate-600">CLOUD</span></span>
                </div>
                <div class="flex gap-4">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/b/b5/PayPal.svg"
                        class="h-6 opacity-50 grayscale hover:grayscale-0 transition" alt="PayPal">
                    <!-- Placeholder Razorpay Icon -->
                    <span class="opacity-50 font-bold text-slate-500">Razorpay</span>
                </div>
            </div>

            <div class="border-t border-white/5 pt-8 text-center text-slate-600 text-sm">
                &copy; <?= date('Y') ?> Vivzon Cloud Services. All rights reserved. <br>
                <a href="#" class="hover:text-blue-500 transition">Privacy Policy</a> &bull; <a href="#"
                    class="hover:text-blue-500 transition">Terms of Service</a>
            </div>
        </div>
    </footer>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>