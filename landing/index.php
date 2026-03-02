<?php
/**
 * VIVZON CLOUD - LANDING PAGE (Modern Vanilla CSS)
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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
        content="<?= e($brandName) ?> — Enterprise-grade NVMe cloud hosting with 99.9% uptime, DDoS protection, global CDN, and 24/7 expert support.">
    <title><?= e($brandName) ?> | Premium Cloud Hosting</title>

    <!-- Unified modern design system -->
    <link rel="stylesheet" href="/assets/css/modern-design.css">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        /* Landing Page Specific Styles */
        body {
            overflow-x: hidden;
        }

        /* Ambient Blobs */
        .ambient-bg {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: -1;
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.5;
            pointer-events: none;
        }

        .blob-1 {
            width: 900px;
            height: 900px;
            background: rgba(147, 197, 253, 0.4);
            top: -20%;
            left: -15%;
            animation: float 8s ease-in-out infinite;
        }

        .blob-2 {
            width: 700px;
            height: 700px;
            background: rgba(196, 181, 253, 0.3);
            bottom: -15%;
            right: -10%;
            animation: float 10s ease-in-out infinite reverse;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px) rotate(0deg);
            }

            50% {
                transform: translateY(-24px) rotate(2deg);
            }
        }

        /* Navbar Layout */
        .navbar {
            position: fixed;
            width: 100%;
            z-index: 50;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--slate-200);
            transition: background 0.3s;
        }

        .navbar.navbar-scrolled {
            background: rgba(255, 255, 255, 0.97);
            box-shadow: var(--shadow-sm);
        }

        .navbar-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 72px;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.125rem;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            color: var(--slate-800);
            letter-spacing: -0.02em;
        }

        .nav-brand-icon {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 10px;
            border-radius: 12px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-links {
            display: none;
            gap: 2rem;
            align-items: center;
        }

        @media(min-width: 768px) {
            .nav-links {
                display: flex;
            }
        }

        .nav-link-item {
            color: var(--slate-500);
            font-weight: 500;
            font-size: 0.875rem;
            position: relative;
        }

        .nav-link-item:hover {
            color: var(--slate-900);
        }

        .nav-link-item::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
            transform: scaleX(0);
            transition: transform 0.25s ease;
        }

        .nav-link-item:hover::after {
            transform: scaleX(1);
        }

        /* Hero Section */
        .hero-section {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding-top: 5rem;
            padding-bottom: 6rem;
            position: relative;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1.25rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            background: rgba(37, 99, 235, 0.08);
            border: 1px solid rgba(99, 102, 241, 0.2);
            color: var(--secondary);
            margin-bottom: 2rem;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: var(--accent-emerald);
            border-radius: 50%;
            animation: ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
        }

        @keyframes ping {

            75%,
            100% {
                transform: scale(2);
                opacity: 0;
            }
        }

        .hero-title {
            font-size: 3.5rem;
            line-height: 1.1;
            margin-bottom: 1.5rem;
        }

        @media(min-width: 768px) {
            .hero-title {
                font-size: 5rem;
            }
        }

        .hero-subtitle {
            font-size: 1.125rem;
            color: var(--slate-600);
            max-width: 800px;
            margin: 0 auto 3rem;
            font-weight: 400;
        }

        .hero-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* Stats Grid */
        .stats-section {
            padding: 4rem 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        @media(min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: var(--bg-surface);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-lg);
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: var(--primary-light);
            box-shadow: var(--shadow-md);
            transform: translateY(-4px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            color: var(--slate-800);
            margin: 0.5rem 0;
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--slate-500);
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        /* Features Section */
        .section-padding {
            padding: 6rem 0;
        }

        .section-title-wrap {
            text-align: center;
            margin-bottom: 4rem;
        }

        .badge-label {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--secondary);
            background: rgba(79, 70, 229, 0.1);
            border: 1px solid rgba(79, 70, 229, 0.2);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-full);
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        @media(min-width: 768px) {
            .section-title {
                font-size: 3rem;
            }
        }

        .features-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media(min-width: 768px) {
            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(min-width: 1024px) {
            .features-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .feature-icon-wrap {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            background: var(--slate-100);
            color: var(--primary);
        }

        /* Pricing Section */
        .pricing-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            max-width: 1000px;
            margin: 0 auto;
        }

        @media(min-width: 768px) {
            .pricing-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .pricing-card {
            background: var(--bg-surface);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-xl);
            padding: 2.5rem 2rem;
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .pricing-card:hover {
            transform: translateY(-8px);
            border-color: var(--primary-light);
            box-shadow: var(--shadow-lg);
        }

        .pricing-card.featured {
            border-color: var(--primary);
            box-shadow: var(--shadow-glow);
        }

        .pricing-badge {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translate(-50%, -50%);
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            font-size: 0.625rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding: 0.375rem 1rem;
            border-radius: var(--radius-full);
        }

        .pricing-price {
            font-size: 3.5rem;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            color: var(--text-primary);
            margin: 1rem 0 1.5rem;
        }

        .pricing-perk {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--slate-600);
        }

        .pricing-perk i {
            color: var(--accent-emerald);
        }

        /* Footer */
        .footer {
            background: var(--slate-100);
            padding: 4rem 0 2rem;
            border-top: 1px solid var(--slate-200);
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 3rem;
            margin-bottom: 3rem;
        }

        @media(min-width: 768px) {
            .footer-grid {
                grid-template-columns: 2fr 1fr 1fr;
            }
        }

        .footer-col h4 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--slate-500);
            margin-bottom: 1.5rem;
        }

        .footer-link {
            display: block;
            color: var(--slate-600);
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
            transition: color 0.2s;
        }

        .footer-link:hover {
            color: var(--slate-900);
        }

        .footer-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            padding-top: 2rem;
            border-top: 1px solid var(--slate-200);
            font-size: 0.875rem;
            color: var(--slate-500);
        }

        /* Utility */
        .mt-4 {
            margin-top: 1rem;
        }

        .mb-4 {
            margin-bottom: 1rem;
        }

        .mb-8 {
            margin-bottom: 2rem;
        }

        .w-full {
            width: 100%;
        }

        .block {
            display: block;
        }

        .text-center {
            text-align: center;
        }
    </style>
</head>

<body>

    <!-- Ambient BG -->
    <div class="ambient-bg">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
    </div>

    <!-- Navbar -->
    <nav class="navbar" id="navbar">
        <div class="container navbar-content">
            <a href="#" class="nav-brand">
                <div class="nav-brand-icon"><i data-lucide="cloud" style="width:20px;height:20px;"></i></div>
                <span><?= e(strtoupper($brandName)) ?></span>
            </a>
            <div class="nav-links">
                <a href="#features" class="nav-link-item">Features</a>
                <a href="#stats" class="nav-link-item">Performance</a>
                <a href="#pricing" class="nav-link-item">Pricing</a>
                <a href="#testimonials" class="nav-link-item">Reviews</a>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= e($clientUrl) ?>" class="btn btn-secondary">
                    <i data-lucide="log-in" style="width:16px;height:16px;"></i> Login
                </a>
                <a href="#pricing" class="btn btn-primary">
                    Get Started <i data-lucide="arrow-right" style="width:16px;height:16px;"></i>
                </a>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="hero-section">
        <div class="container text-center">
            <div class="hero-badge animate-fade-in">
                <div class="live-dot"></div>
                All Systems Operational · 99.97% Uptime This Month
            </div>

            <h1 class="hero-title animate-fade-in" style="animation-delay: 0.1s;">
                Hosting Built<br>
                <span class="text-gradient">For Builders.</span>
            </h1>

            <p class="hero-subtitle animate-fade-in" style="animation-delay: 0.2s;">
                Deploy on <strong>NVMe-powered cloud</strong> in seconds.
                Automatic SSL, DDoS protection, and a control panel that actually makes sense.
            </p>

            <div class="hero-actions animate-fade-in" style="animation-delay: 0.3s;">
                <a href="#pricing" class="btn btn-primary" style="padding: 1rem 2rem; font-size: 1rem;">
                    View Plans <i data-lucide="arrow-right"></i>
                </a>
                <a href="<?= e($clientUrl) ?>" class="btn btn-secondary" style="padding: 1rem 2rem; font-size: 1rem;">
                    <i data-lucide="layout-dashboard"></i> Client Portal
                </a>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <section id="stats" class="stats-section">
        <div class="container">
            <div class="stats-grid">
                <?php
                $stats = [
                    ['99.97%', 'Uptime SLA', 'activity', '#10b981'],
                    ['<10ms', 'Response Time', 'zap', '#2563eb'],
                    ['3 DC', 'Global Locations', 'globe', '#4f46e5'],
                    ['24 / 7', 'Expert Support', 'headphones', '#7c3aed'],
                ];
                foreach ($stats as $s):
                    ?>
                    <div class="stat-card">
                        <i data-lucide="<?= $s[2] ?>" style="width:24px;height:24px;color:<?= $s[3] ?>;"></i>
                        <div class="stat-value"><?= $s[0] ?></div>
                        <div class="stat-label"><?= $s[1] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section id="features" class="section-padding">
        <div class="container">
            <div class="section-title-wrap">
                <span class="badge-label">Why <?= e($brandName) ?></span>
                <h2 class="section-title">Enterprise-Grade, <br>Developer-Friendly</h2>
                <p style="color:var(--slate-600);max-width:600px;margin:1rem auto;">
                    All the power of a dedicated server, with the simplicity of a managed platform.
                </p>
            </div>

            <div class="features-grid">
                <?php
                $features = [
                    ['zap', 'Blazing NVMe I/O', 'Up to 10× faster storage vs traditional SSD. Queries fly, apps load.'],
                    ['shield-check', 'DDoS Protection', 'Multi-layer traffic scrubbing absorbs attacks before they reach your app.'],
                    ['globe-2', 'Global CDN Edge', 'Content delivered from the nearest node. Lower latency.'],
                    ['lock', 'Auto Free SSL', 'Let\'s Encrypt certificates issued and auto-renewed out of the box.'],
                    ['terminal', 'Full SSH Access', 'Root or user-level SSH, SFTP, and Git deployment hooks.'],
                    ['life-buoy', '24/7 Expert Support', 'Real engineers available around the clock.'],
                ];
                foreach ($features as $f):
                    ?>
                    <div class="glass-card">
                        <div class="feature-icon-wrap">
                            <i data-lucide="<?= $f[0] ?>"></i>
                        </div>
                        <h3 style="margin-bottom: 0.5rem; font-size: 1.125rem;"><?= $f[1] ?></h3>
                        <p style="color:var(--slate-600); font-size:0.875rem;"><?= $f[2] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section id="pricing" class="section-padding" style="background: rgba(255,255,255,0.4);">
        <div class="container">
            <div class="section-title-wrap">
                <span class="badge-label" style="color:var(--primary); background:var(--primary-light);">Simple
                    Pricing</span>
                <h2 class="section-title">Plans That Scale With You</h2>
            </div>

            <!-- Pricing Grid -->
            <div class="pricing-grid"
                style="grid-template-columns: repeat(min(4, 1fr), 1fr); @media(min-width: 1024px) { grid-template-columns: repeat(4, 1fr); } max-width: 1200px;">
                <?php
                $plans = [
                    [
                        'name' => 'Basic Plan',
                        'price' => '₹49',
                        'popular' => false,
                        'perks' => ['1 Website', '1 GB SSD Storage', '2 Email Accounts', 'Free SSL Certificate', 'Standard Speed', 'Beginner Friendly']
                    ],
                    [
                        'name' => 'Smart Plan',
                        'price' => '₹149',
                        'popular' => true,
                        'perks' => ['3 Websites', '5 GB SSD Storage', '10 Email Accounts', 'Free SSL', 'Faster Performance', 'Priority Support']
                    ],
                    [
                        'name' => 'Pro Plan',
                        'price' => '₹249',
                        'popular' => false,
                        'perks' => ['10 Websites', '15 GB SSD Storage', '25 Email Accounts', 'Free SSL + Backup', 'High Performance', 'Developer Friendly']
                    ],
                    [
                        'name' => 'Agency Plan',
                        'price' => '₹399',
                        'popular' => false,
                        'perks' => ['Unlimited Websites', '40 GB SSD Storage', '100 Email Accounts', 'Free SSL + Backup', 'Priority Resources', '24/7 Support']
                    ]
                ];

                foreach ($plans as $plan):
                    $featured = $plan['popular'];
                    ?>
                    <div class="pricing-card <?= $featured ? 'featured' : '' ?>">
                        <?php if ($featured): ?>
                            <div class="pricing-badge">Most Popular</div>
                        <?php endif; ?>
                        <h3 style="font-size:1.25rem; font-weight:700;"><?= $plan['name'] ?></h3>
                        <div class="pricing-price"><?= $plan['price'] ?><span
                                style="font-size:0.875rem;color:var(--slate-500);font-weight:400;font-family:'Plus Jakarta Sans',sans-serif;">/mo</span>
                        </div>

                        <div style="margin-bottom: 2rem;">
                            <?php foreach ($plan['perks'] as $perk): ?>
                                <div class="pricing-perk">
                                    <i data-lucide="check" style="width:16px;height:16px; flex-shrink:0;"></i>
                                    <span style="flex-grow:1;"><?= e($perk) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?= e($clientUrl) ?>/checkout.php?plan=<?= urlencode($plan['name']) ?>"
                            class="btn w-full block text-center <?= $featured ? 'btn-primary' : 'btn-secondary' ?>">Get
                            Started</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col" style="grid-column: span 1;">
                    <div class="nav-brand" style="margin-bottom:1rem;">
                        <div class="nav-brand-icon" style="padding:6px;"><i data-lucide="cloud"
                                style="width:16px;height:16px;"></i></div>
                        <span><?= e(strtoupper($brandName)) ?></span>
                    </div>
                    <p style="color:var(--slate-500);font-size:0.875rem;line-height:1.6;max-width:300px;">
                        Enterprise-grade cloud hosting trusted by developers and agencies.
                    </p>
                </div>
                <div class="footer-col">
                    <h4>Platform</h4>
                    <a href="#features" class="footer-link">Features</a>
                    <a href="#pricing" class="footer-link">Pricing</a>
                    <a href="<?= e($clientUrl) ?>" class="footer-link">Client Portal</a>
                </div>
                <div class="footer-col">
                    <h4>Support</h4>
                    <a href="mailto:support@<?= e($base) ?>" class="footer-link">Email Support</a>
                    <a href="#" class="footer-link">Privacy Policy</a>
                    <a href="#" class="footer-link">Terms of Service</a>
                </div>
            </div>
            <div class="footer-bottom">
                <div>&copy; <?= date('Y') ?> <?= e($brandName) ?>. All rights reserved.</div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    Made with <i data-lucide="heart"
                        style="width:14px;height:14px;color:var(--accent-red);fill:var(--accent-red);"></i> for the web
                </div>
            </div>
        </div>
    </footer>

    <script>
        lucide.createIcons();

        // Navbar Scroll Effect
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 40) {
                navbar.classList.add('navbar-scrolled');
            } else {
                navbar.classList.remove('navbar-scrolled');
            }
        });
    </script>
</body>

</html>