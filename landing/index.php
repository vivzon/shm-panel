<?php
/**
 * VIVZON CLOUD - LANDING PAGE (v5.0)
 * Premium Hosting Solutions
 */
require_once __DIR__ . '/../shared/config.php';



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

$brandName = get_branding();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $brandName ?> | SHM (Server Hosting Management)</title>
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
                <span class="text-xl font-bold font-heading tracking-tight"><?= strtoupper($brandName) ?></span>
            </div>

            <div class="hidden md:flex gap-8 text-sm font-medium text-slate-400">
                <a href="#features" class="hover:text-white transition">Features</a>
                <a href="<?= $scheme ?>client.<?= $base ?>" class="hover:text-white transition">Client Area</a>
            </div>

            <a href="<?= $scheme ?>client.<?= $base ?>"
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
                <a href="#features"
                    class="w-full md:w-auto px-8 py-4 bg-blue-600 hover:bg-blue-500 text-white rounded-2xl font-bold text-lg transition shadow-[0_10px_40px_-10px_rgba(37,99,235,0.5)] flex items-center justify-center gap-2 group">
                    View Features <i data-lucide="arrow-right" class="w-5 h-5 group-hover:translate-x-1 transition"></i>
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

    <!-- Features Section -->
    <section id="features" class="py-32 relative z-10 bg-slate-900/10">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-20">
                <h2 class="text-4xl md:text-5xl font-bold font-heading mb-6">Enterprise-Grade Infrastructure</h2>
                <p class="text-slate-400 max-w-xl mx-auto">Built for speed, security, and reliability. Our platform
                    handles the complexity of cloud hosting so you can focus on your code.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="glass-card p-8 rounded-3xl hover:bg-slate-800/50 transition duration-300">
                    <div
                        class="bg-blue-600/20 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 text-blue-500">
                        <i data-lucide="zap" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-4">Blazing Fast NVMe</h3>
                    <p class="text-slate-400 leading-relaxed">Experience up to 10x faster storage I/O compared to
                        traditional SSDs. Your applications load instantly and database queries fly.</p>
                </div>

                <!-- Feature 2 -->
                <div class="glass-card p-8 rounded-3xl hover:bg-slate-800/50 transition duration-300">
                    <div
                        class="bg-purple-600/20 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 text-purple-500">
                        <i data-lucide="shield" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-4">DDoS Protection</h3>
                    <p class="text-slate-400 leading-relaxed">Our advanced edge network filters malicious traffic in
                        real-time, ensuring your legitimate users always have access to your services.</p>
                </div>

                <!-- Feature 3 -->
                <div class="glass-card p-8 rounded-3xl hover:bg-slate-800/50 transition duration-300">
                    <div
                        class="bg-emerald-600/20 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 text-emerald-500">
                        <i data-lucide="globe" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-4">Global Low Latency</h3>
                    <p class="text-slate-400 leading-relaxed">Strategically located data centers ensure your content is
                        delivered with minimal latency to users anywhere in the world.</p>
                </div>

                <!-- Feature 4 -->
                <div class="glass-card p-8 rounded-3xl hover:bg-slate-800/50 transition duration-300">
                    <div
                        class="bg-orange-600/20 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 text-orange-500">
                        <i data-lucide="box" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-4">Container Ready</h3>
                    <p class="text-slate-400 leading-relaxed">Native support for Docker/Podman environments. Deploys
                        microservices with isolated resources and full control.</p>
                </div>

                <!-- Feature 5 -->
                <div class="glass-card p-8 rounded-3xl hover:bg-slate-800/50 transition duration-300">
                    <div
                        class="bg-pink-600/20 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 text-pink-500">
                        <i data-lucide="lock" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-4">SSL & Privacy</h3>
                    <p class="text-slate-400 leading-relaxed">Automated Let's Encrypt SSL certificates for all your
                        domains. We engage strictly zero-logging policies on our infrastructure edge.</p>
                </div>

                <!-- Feature 6 -->
                <div class="glass-card p-8 rounded-3xl hover:bg-slate-800/50 transition duration-300">
                    <div
                        class="bg-cyan-600/20 w-14 h-14 rounded-2xl flex items-center justify-center mb-6 text-cyan-500">
                        <i data-lucide="life-buoy" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-4">24/7 Expert Support</h3>
                    <p class="text-slate-400 leading-relaxed">Our team of engineers is always awake. Whether it is a
                        server misconfiguration or code advice, we are here to help.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-white/5 bg-[#01030b] pt-20 pb-10">
        <div class="max-w-7xl mx-auto px-6">
            <div class="flex flex-col md:flex-row justify-content-center items-center gap-6 mb-12">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-800 p-2 rounded-lg">
                        <i data-lucide="cloud" class="w-6 h-6 text-white"></i>
                    </div>
                    <span class="text-xl font-bold font-heading"><?= strtoupper($brandName) ?></span>
                </div>

            </div>

            <div class="border-t border-white/5 pt-8 text-center text-slate-600 text-sm">
                &copy; <?= date('Y') ?> <?= $brandName ?>. All rights reserved. <br>
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