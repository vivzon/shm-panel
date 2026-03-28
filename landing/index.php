<?php
/**
 * LANDING PAGE - Premium Redesign
 */
require_once __DIR__ . '/../shared/config.php';

$host   = $_SERVER['HTTP_HOST'];
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';

if (filter_var($host, FILTER_VALIDATE_IP)) {
    // IP-based access — cpanel at same host, no subdomain trick
    $base       = $host;
    $cpanelUrl  = $scheme . $host . '/cpanel';
    $landingUrl = $scheme . $host;
} else {
    $parts = explode('.', $host);
    $base  = implode('.', array_slice($parts, -2)); // e.g. vivzon.cloud
    // cpanel lives at client.<base> (mirrors WHM at admin.<base>)
    $cpanelUrl  = $scheme . 'client.' . $base;
    $landingUrl  = $scheme . $base;              // e.g. https://vivzon.cloud
    // Allow override via config constant
    if (defined('CPANEL_URL'))  $cpanelUrl  = rtrim(constant('CPANEL_URL'), '/');
    if (defined('LANDING_URL')) $landingUrl = rtrim(constant('LANDING_URL'), '/');
}

$brandName = get_branding();

$plans = [
    ['name' => 'Starter',  'price' => '₹49',  'popular' => false, 'color' => '#3b82f6',
     'perks' => ['1 Website', '1 GB NVMe Storage', '2 Email Accounts', '2 MySQL DBs', 'Free SSL']],
    ['name' => 'Smart',    'price' => '₹149', 'popular' => true,  'color' => '#6366f1',
     'perks' => ['3 Websites', '5 GB NVMe Storage', '10 Email Accounts', '5 MySQL DBs', 'Free SSL + CDN']],
    ['name' => 'Pro',      'price' => '₹249', 'popular' => false, 'color' => '#8b5cf6',
     'perks' => ['10 Websites', '15 GB NVMe Storage', '25 Email Accounts', '20 MySQL DBs', 'SSL + Backup']],
    ['name' => 'Agency',   'price' => '₹399', 'popular' => false, 'color' => '#10b981',
     'perks' => ['Unlimited Websites', '40 GB NVMe Storage', '100 Email Accounts', 'Unlimited DBs', 'Dedicated Resources']],
];
$features = [
    ['zap',          '#f59e0b', 'NVMe Speed', 'Up to 10× faster storage. Sub-millisecond I/O that keeps your apps flying.'],
    ['shield-check', '#10b981', 'DDoS Shield', 'Multi-layer traffic scrubbing absorbs attacks before they touch your app.'],
    ['globe-2',      '#3b82f6', 'Global CDN',  'Edge nodes deliver your content from the closest point. Latency drops, rankings rise.'],
    ['lock',         '#6366f1', 'Auto SSL',    "Let's Encrypt certs issued and renewed automatically. Zero config."],
    ['terminal',     '#ec4899', 'Full SSH',    'Root or user SSH, SFTP, WP-CLI, and Git deploy hooks out of the box.'],
    ['headphones',   '#f97316', '24/7 Support','Real engineers, not bots. Average first response: under 4 minutes.'],
];
$faqs = [
    ["What is NVMe Cloud Hosting?",       "NVMe (Non-Volatile Memory Express) is a modern storage protocol that is up to 10× faster than traditional SSDs, ensuring your site loads instantly."],
    ["How does free SSL work?",           "We automatically provision Let's Encrypt certificates for every domain at no extra cost and handle renewals before they expire."],
    ["Can I upgrade my plan later?",      "Absolutely. You can upgrade any time from the client portal with no downtime. Resources are provisioned immediately."],
    ["Is there a money-back guarantee?",  "Yes — every plan comes with a 7-day money-back guarantee, no questions asked."],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= e($brandName) ?> — Enterprise NVMe cloud hosting with DDoS protection, auto SSL, and 24/7 support.">
<title><?= e($brandName) ?> | Premium Cloud Hosting</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<style>
/* ── Reset & Tokens ─────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg:        #080b14;
    --bg-s:      #0e1221;
    --bg-card:   rgba(255,255,255,0.04);
    --border:    rgba(255,255,255,0.08);
    --border-h:  rgba(255,255,255,0.16);
    --text:      #f1f5f9;
    --muted:     #94a3b8;
    --p:         #6366f1;
    --p-glow:    rgba(99,102,241,0.35);
    --radius:    1rem;
    --radius-lg: 1.5rem;
}
html { scroll-behavior: smooth; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
a { text-decoration: none; color: inherit; }
img { display: block; max-width: 100%; }
h1,h2,h3,h4 { font-family: 'Outfit', sans-serif; line-height: 1.15; }

/* ── Layout ─────────────────────────────────── */
.container { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
.section { padding: 6rem 0; }

/* ── Gradient Text ───────────────────────────── */
.grad { background: linear-gradient(135deg, #818cf8, #c084fc 50%, #38bdf8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }

/* ── Buttons ─────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: .5rem; padding: .75rem 1.5rem; border-radius: 9999px; font-weight: 600; font-size: .875rem; cursor: pointer; transition: all .25s ease; border: none; }
.btn-primary { background: linear-gradient(135deg, var(--p), #7c3aed); color: #fff; box-shadow: 0 0 24px var(--p-glow); }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 0 40px var(--p-glow); }
.btn-ghost { background: var(--bg-card); border: 1px solid var(--border); color: var(--text); }
.btn-ghost:hover { border-color: var(--border-h); transform: translateY(-2px); }
.btn-lg { padding: 1rem 2rem; font-size: 1rem; }

/* ── Cards ───────────────────────────────────── */
.card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); backdrop-filter: blur(12px); transition: border-color .3s, transform .3s, box-shadow .3s; }
.card:hover { border-color: var(--border-h); transform: translateY(-4px); }

/* ── Noise overlay ───────────────────────────── */
body::before { content: ''; position: fixed; inset: 0; background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E"); pointer-events: none; z-index: 9999; opacity: .5; }

/* ── Ambient blobs ───────────────────────────── */
.blobs { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
.blob { position: absolute; border-radius: 50%; filter: blur(120px); opacity: .35; animation: drift 12s ease-in-out infinite; }
.blob-1 { width: 900px; height: 900px; background: radial-gradient(circle, #4f46e5, transparent 70%); top: -20%; left: -15%; }
.blob-2 { width: 700px; height: 700px; background: radial-gradient(circle, #7c3aed, transparent 70%); bottom: -15%; right: -10%; animation-delay: -4s; animation-direction: reverse; }
.blob-3 { width: 500px; height: 500px; background: radial-gradient(circle, #0ea5e9, transparent 70%); top: 40%; left: 50%; animation-delay: -8s; }
@keyframes drift { 0%,100% { transform: translateY(0) scale(1); } 50% { transform: translateY(-30px) scale(1.04); } }

/* ── Navbar ───────────────────────────────────── */
.nav { position: fixed; top: 0; left: 0; right: 0; z-index: 100; transition: background .3s, backdrop-filter .3s, box-shadow .3s; }
.nav.scrolled { background: rgba(8,11,20,.85); backdrop-filter: blur(20px); box-shadow: 0 1px 0 var(--border); }
.nav-inner { display: flex; align-items: center; justify-content: space-between; height: 72px; }
.nav-brand { display: flex; align-items: center; gap: .75rem; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 1.125rem; }
.brand-icon { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #6366f1, #7c3aed); display: flex; align-items: center; justify-content: center; box-shadow: 0 0 16px var(--p-glow); }
.nav-links { display: none; gap: 2.5rem; }
@media(min-width:768px) { .nav-links { display: flex; } }
.nav-link { font-size: .875rem; font-weight: 500; color: var(--muted); transition: color .2s; }
.nav-link:hover { color: var(--text); }
.nav-cta { display: flex; align-items: center; gap: .75rem; }

/* ── Hero ─────────────────────────────────────── */
.hero { min-height: 100vh; display: flex; align-items: center; padding-top: 5rem; position: relative; z-index: 1; }
.hero-inner { text-align: center; padding: 4rem 0; }
.hero-pill { display: inline-flex; align-items: center; gap: .625rem; padding: .4rem 1rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .1em; background: rgba(99,102,241,.12); border: 1px solid rgba(99,102,241,.3); color: #a5b4fc; margin-bottom: 2rem; }
.pulse { width: 7px; height: 7px; background: #4ade80; border-radius: 50%; box-shadow: 0 0 0 0 rgba(74,222,128,.4); animation: pulse 2s infinite; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(74,222,128,.4); } 70% { box-shadow: 0 0 0 8px rgba(74,222,128,0); } 100% { box-shadow: 0 0 0 0 rgba(74,222,128,0); } }
.hero-title { font-size: clamp(3rem,8vw,5.5rem); font-weight: 800; line-height: 1.05; letter-spacing: -.03em; margin-bottom: 1.5rem; }
.hero-sub { font-size: 1.125rem; color: var(--muted); max-width: 680px; margin: 0 auto 2.5rem; line-height: 1.7; }
.hero-cta { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
.hero-trust { display: flex; align-items: center; justify-content: center; gap: 1.5rem; margin-top: 3.5rem; font-size: .8125rem; color: var(--muted); flex-wrap: wrap; }
.trust-item { display: flex; align-items: center; gap: .5rem; }
.trust-item i { width: 14px; height: 14px; }

/* ── Stats ────────────────────────────────────── */
.stats-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; position: relative; z-index: 1; }
@media(min-width:768px) { .stats-row { grid-template-columns: repeat(4, 1fr); } }
.stat-cell { background: var(--bg-s); padding: 2rem; text-align: center; transition: background .3s; }
.stat-cell:hover { background: rgba(99,102,241,.06); }
.stat-num { font-family: 'Outfit', sans-serif; font-size: 2.25rem; font-weight: 700; }
.stat-lbl { font-size: .75rem; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-top: .25rem; }

/* ── Features ─────────────────────────────────── */
.feat-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
@media(min-width:640px) { .feat-grid { grid-template-columns: repeat(2, 1fr); } }
@media(min-width:1024px) { .feat-grid { grid-template-columns: repeat(3, 1fr); } }
.feat-card { padding: 2rem; }
.feat-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; margin-bottom: 1.25rem; }
.feat-card h3 { font-size: 1.0625rem; font-weight: 600; margin-bottom: .5rem; color: var(--text); }
.feat-card p { font-size: .875rem; color: var(--muted); line-height: 1.65; }

/* ── Pricing ──────────────────────────────────── */
.price-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
@media(min-width:640px) { .price-grid { grid-template-columns: repeat(2, 1fr); } }
@media(min-width:1024px) { .price-grid { grid-template-columns: repeat(4, 1fr); } }
.price-card { padding: 2rem; display: flex; flex-direction: column; position: relative; }
.price-card.popular { border-color: rgba(99,102,241,.5); box-shadow: 0 0 40px rgba(99,102,241,.2); }
.pop-badge { position: absolute; top: -13px; left: 50%; transform: translateX(-50%); background: linear-gradient(135deg, var(--p), #7c3aed); color: #fff; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; padding: .3rem 1rem; border-radius: 9999px; white-space: nowrap; }
.price-name { font-size: .9375rem; font-weight: 600; color: var(--muted); margin-bottom: .75rem; }
.price-num { font-family: 'Outfit', sans-serif; font-size: 3rem; font-weight: 800; line-height: 1; margin-bottom: .25rem; }
.price-per { font-size: .8125rem; color: var(--muted); margin-bottom: 1.75rem; }
.perk { display: flex; align-items: center; gap: .625rem; font-size: .875rem; color: var(--muted); margin-bottom: .875rem; }
.perk i { flex-shrink: 0; width: 14px; height: 14px; }
.price-cta { margin-top: auto; padding-top: 1.5rem; }
.btn-plan { display: block; text-align: center; padding: .75rem; border-radius: 9999px; font-weight: 600; font-size: .875rem; transition: all .25s; }
.btn-plan-p { background: linear-gradient(135deg, var(--p), #7c3aed); color: #fff; box-shadow: 0 0 20px var(--p-glow); }
.btn-plan-p:hover { box-shadow: 0 0 40px var(--p-glow); transform: translateY(-1px); }
.btn-plan-s { background: var(--bg-card); border: 1px solid var(--border); color: var(--text); }
.btn-plan-s:hover { border-color: var(--border-h); transform: translateY(-1px); }

/* ── Testimonials ─────────────────────────────── */
.testi-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
@media(min-width:768px) { .testi-grid { grid-template-columns: repeat(3, 1fr); } }
.testi-card { padding: 2rem; display: flex; flex-direction: column; gap: 1.25rem; }
.stars { display: flex; gap: .2rem; color: #fbbf24; }
.stars i { width: 1rem; height: 1rem; }
.testi-text { font-size: .9375rem; color: var(--muted); line-height: 1.7; font-style: italic; flex: 1; }
.testi-author { display: flex; align-items: center; gap: .875rem; }
.avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .8125rem; flex-shrink: 0; }
.author-name { font-weight: 600; font-size: .9375rem; color: var(--text); }
.author-role { font-size: .8125rem; color: var(--muted); }

/* ── FAQ ──────────────────────────────────────── */
.faq-list { max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 1px; border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
.faq-item { background: var(--bg-s); }
.faq-btn { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.5rem; text-align: left; cursor: pointer; background: none; border: none; color: var(--text); font-family: 'Inter', sans-serif; font-size: .9375rem; font-weight: 600; transition: background .2s; }
.faq-btn:hover { background: rgba(99,102,241,.05); }
.faq-icon { width: 20px; height: 20px; flex-shrink: 0; color: var(--muted); transition: transform .3s; }
.faq-body { max-height: 0; overflow: hidden; transition: max-height .4s ease, padding .3s; }
.faq-body p { padding: 0 1.5rem 1.5rem; font-size: .9rem; color: var(--muted); line-height: 1.7; }
.faq-item.open .faq-icon { transform: rotate(45deg); }
.faq-item.open .faq-body { max-height: 300px; }

/* ── CTA Banner ───────────────────────────────── */
.cta-banner { background: linear-gradient(135deg, rgba(99,102,241,.15), rgba(124,58,237,.15)); border: 1px solid rgba(99,102,241,.25); border-radius: var(--radius-lg); padding: 4rem 2rem; text-align: center; position: relative; overflow: hidden; }
.cta-banner::before { content: ''; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(99,102,241,.05), transparent); pointer-events: none; }

/* ── Footer ───────────────────────────────────── */
.footer { border-top: 1px solid var(--border); padding: 4rem 0 2rem; }
.footer-grid { display: grid; grid-template-columns: 1fr; gap: 3rem; margin-bottom: 3rem; }
@media(min-width:768px) { .footer-grid { grid-template-columns: 2fr 1fr 1fr; } }
.footer-label { font-size: .6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 1.25rem; }
.footer-link { display: block; font-size: .875rem; color: var(--muted); margin-bottom: .75rem; transition: color .2s; }
.footer-link:hover { color: var(--text); }
.footer-bottom { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; padding-top: 2rem; border-top: 1px solid var(--border); font-size: .8125rem; color: var(--muted); }

/* ── Divider ──────────────────────────────────── */
.section-title { text-align: center; margin-bottom: 4rem; }
.section-tag { display: inline-block; font-size: .6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; color: #a5b4fc; background: rgba(99,102,241,.12); border: 1px solid rgba(99,102,241,.25); padding: .35rem 1rem; border-radius: 9999px; margin-bottom: 1.25rem; }
.section-h { font-size: clamp(2rem,4vw,3rem); font-weight: 800; letter-spacing: -.03em; margin-bottom: 1rem; }
.section-sub { font-size: 1rem; color: var(--muted); max-width: 600px; margin: 0 auto; line-height: 1.7; }

/* ── Animations ────────────────────────────────── */
.fade-up { opacity: 0; transform: translateY(28px); transition: opacity .6s ease, transform .6s ease; }
.fade-up.visible { opacity: 1; transform: translateY(0); }
</style>
</head>
<body>

<!-- Ambient -->
<div class="blobs" aria-hidden="true">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
</div>

<!-- Navbar -->
<nav class="nav" id="nav">
    <div class="container nav-inner">
        <a href="#" class="nav-brand">
            <div class="brand-icon"><i data-lucide="cloud" style="width:20px;height:20px;color:#fff;"></i></div>
            <span><?= e(strtoupper($brandName)) ?></span>
        </a>
        <div class="nav-links">
            <a href="#features"  class="nav-link">Features</a>
            <a href="#pricing"   class="nav-link">Pricing</a>
            <a href="#reviews"   class="nav-link">Reviews</a>
            <a href="#faq"       class="nav-link">FAQ</a>
        </div>
        <div class="nav-cta">
            <a href="<?= e($cpanelUrl) ?>/login.php" class="btn btn-ghost" style="padding:.625rem 1.25rem;">
                <i data-lucide="log-in" style="width:15px;height:15px;"></i> Login
            </a>
            <a href="#pricing" class="btn btn-primary" style="padding:.625rem 1.25rem;">
                Get Started <i data-lucide="arrow-right" style="width:15px;height:15px;"></i>
            </a>
        </div>
    </div>
</nav>

<!-- Hero -->
<section class="hero">
    <div class="container hero-inner">
        <div class="hero-pill fade-up">
            <span class="pulse"></span>
            All Systems Operational &middot; 99.97% Uptime This Month
        </div>
        <h1 class="hero-title fade-up" style="transition-delay:.1s;">
            Cloud Hosting Built<br>
            <span class="grad">For Builders.</span>
        </h1>
        <p class="hero-sub fade-up" style="transition-delay:.2s;">
            Deploy on <strong style="color:var(--text);">NVMe-powered cloud</strong> in seconds.
            Auto SSL, DDoS protection, and a control panel that actually makes sense.
        </p>
        <div class="hero-cta fade-up" style="transition-delay:.3s;">
            <a href="#pricing" class="btn btn-primary btn-lg">
                See Plans <i data-lucide="arrow-right"></i>
            </a>
            <a href="<?= e($cpanelUrl) ?>/login.php" class="btn btn-ghost btn-lg">
                <i data-lucide="layout-dashboard"></i> Client Portal
            </a>
        </div>
        <div class="hero-trust fade-up" style="transition-delay:.4s;">
            <div class="trust-item"><i data-lucide="check-circle" style="color:#4ade80;"></i> No credit card needed</div>
            <div class="trust-item"><i data-lucide="check-circle" style="color:#4ade80;"></i> 7-day money-back</div>
            <div class="trust-item"><i data-lucide="check-circle" style="color:#4ade80;"></i> Instant setup</div>
        </div>
    </div>
</section>

<!-- Stats -->
<div class="container" style="position:relative;z-index:1;margin-bottom:2rem;">
    <div class="stats-row fade-up">
        <?php foreach ([
            ['99.97%', 'Uptime SLA',       '#4ade80'],
            ['<10ms',  'Avg Response Time', '#38bdf8'],
            ['3',      'Data Centres',      '#a78bfa'],
            ['24/7',   'Expert Support',    '#fb923c'],
        ] as $s): ?>
        <div class="stat-cell">
            <div class="stat-num" style="color:<?= $s[2] ?>;"><?= $s[0] ?></div>
            <div class="stat-lbl"><?= $s[1] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Features -->
<section class="section" id="features" style="position:relative;z-index:1;">
    <div class="container">
        <div class="section-title">
            <span class="section-tag">Why <?= e($brandName) ?></span>
            <h2 class="section-h fade-up">Enterprise-Grade,<br>Developer-Friendly</h2>
            <p class="section-sub fade-up" style="transition-delay:.1s;">All the power of a dedicated server, with the simplicity of a managed platform.</p>
        </div>
        <div class="feat-grid">
            <?php foreach ($features as $i => $f): ?>
            <div class="card feat-card fade-up" style="transition-delay:<?= $i * 0.07 ?>s;">
                <div class="feat-icon" style="background:<?= $f[1] ?>18; color:<?= $f[1] ?>;">
                    <i data-lucide="<?= $f[0] ?>" style="width:24px;height:24px;"></i>
                </div>
                <h3><?= $f[2] ?></h3>
                <p><?= $f[3] ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Pricing -->
<section class="section" id="pricing" style="background:var(--bg-s);position:relative;z-index:1;border-top:1px solid var(--border);border-bottom:1px solid var(--border);">
    <div class="container">
        <div class="section-title">
            <span class="section-tag">Simple Pricing</span>
            <h2 class="section-h fade-up">Plans That Scale With You</h2>
            <p class="section-sub fade-up" style="transition-delay:.1s;">No hidden fees. Upgrade or downgrade any time from the portal.</p>
        </div>
        <div class="price-grid">
            <?php foreach ($plans as $i => $plan): ?>
            <div class="card price-card <?= $plan['popular'] ? 'popular' : '' ?> fade-up" style="transition-delay:<?= $i * 0.08 ?>s;">
                <?php if ($plan['popular']): ?><div class="pop-badge">⭐ Most Popular</div><?php endif; ?>
                <div class="price-name"><?= $plan['name'] ?></div>
                <div class="price-num" style="color:<?= $plan['color'] ?>;"><?= $plan['price'] ?></div>
                <div class="price-per">per month · billed monthly</div>
                <div>
                    <?php foreach ($plan['perks'] as $perk): ?>
                    <div class="perk">
                        <i data-lucide="check" style="color:<?= $plan['color'] ?>;"></i>
                        <span><?= e($perk) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="price-cta">
                    <a href="<?= e($landingUrl) ?>/checkout.php?plan=<?= urlencode($plan['name']) ?>"
                       class="btn-plan <?= $plan['popular'] ? 'btn-plan-p' : 'btn-plan-s' ?>">
                        Get Started
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Testimonials -->
<section class="section" id="reviews" style="position:relative;z-index:1;">
    <div class="container">
        <div class="section-title">
            <span class="section-tag">Customer Reviews</span>
            <h2 class="section-h fade-up">Trusted by Thousands</h2>
            <p class="section-sub fade-up" style="transition-delay:.1s;">Real customers, real results — no cherry-picking.</p>
        </div>
        <div class="testi-grid">
            <?php foreach ([
                ['AR', '#6366f1', "The NVMe speeds are unbelievable. Our WooCommerce store load times dropped by 60% after migrating here. Best decision we've made.", 'Alex Rivera', 'E-commerce Owner'],
                ['SK', '#7c3aed', "We host 50+ client sites on the Agency Plan. Built-in backups and free SSL automation alone save us hours every week.", 'Sarah Khan', 'Digital Agency Founder'],
                ['MJ', '#10b981', "Support is genuinely 24/7. DNS issue at 2 AM on a Sunday — fixed in under 5 minutes. Incredible service.", 'Mark Johnson', 'Software Developer'],
            ] as $i => $t): ?>
            <div class="card testi-card fade-up" style="transition-delay:<?= $i * 0.1 ?>s;">
                <div class="stars">
                    <?php for ($s=0;$s<5;$s++): ?><i data-lucide="star" style="fill:currentColor;"></i><?php endfor; ?>
                </div>
                <p class="testi-text">"<?= $t[2] ?>"</p>
                <div class="testi-author">
                    <div class="avatar" style="background:<?= $t[1] ?>22;color:<?= $t[1] ?>;"><?= $t[0] ?></div>
                    <div>
                        <div class="author-name"><?= $t[3] ?></div>
                        <div class="author-role"><?= $t[4] ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="section" id="faq" style="background:var(--bg-s);border-top:1px solid var(--border);position:relative;z-index:1;">
    <div class="container">
        <div class="section-title">
            <span class="section-tag">FAQ</span>
            <h2 class="section-h fade-up">Got Questions?</h2>
        </div>
        <div class="faq-list fade-up" style="transition-delay:.1s;">
            <?php foreach ($faqs as $faq): ?>
            <div class="faq-item">
                <button class="faq-btn" onclick="toggleFaq(this)">
                    <span><?= e($faq[0]) ?></span>
                    <i data-lucide="plus" class="faq-icon"></i>
                </button>
                <div class="faq-body"><p><?= e($faq[1]) ?></p></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA Banner -->
<section class="section" style="position:relative;z-index:1;">
    <div class="container">
        <div class="cta-banner fade-up">
            <span class="section-tag">Ready to Launch?</span>
            <h2 style="font-family:'Outfit',sans-serif;font-size:clamp(2rem,4vw,2.75rem);font-weight:800;letter-spacing:-.03em;margin-bottom:1rem;">
                Start Hosting in <span class="grad">60 Seconds</span>
            </h2>
            <p style="color:var(--muted);margin-bottom:2rem;max-width:500px;margin-left:auto;margin-right:auto;line-height:1.7;">
                No credit card required. Instant provisioning. Cancel any time.
            </p>
            <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;">
                <a href="#pricing" class="btn btn-primary btn-lg">
                    Choose a Plan <i data-lucide="arrow-right"></i>
                </a>
                <a href="<?= e($cpanelUrl) ?>/login.php" class="btn btn-ghost btn-lg">
                    <i data-lucide="log-in"></i> Sign In
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="footer" style="position:relative;z-index:1;">
    <div class="container">
        <div class="footer-grid">
            <div>
                <div class="nav-brand" style="margin-bottom:1rem;">
                    <div class="brand-icon" style="width:32px;height:32px;border-radius:8px;"><i data-lucide="cloud" style="width:16px;height:16px;color:#fff;"></i></div>
                    <span style="font-size:1rem;"><?= e(strtoupper($brandName)) ?></span>
                </div>
                <p style="font-size:.875rem;color:var(--muted);line-height:1.7;max-width:280px;">
                    Enterprise NVMe cloud hosting built for developers, designers, and growing businesses.
                </p>
            </div>
            <div>
                <div class="footer-label">Product</div>
                <a href="#features" class="footer-link">Features</a>
                <a href="#pricing"  class="footer-link">Pricing</a>
                <a href="#faq"      class="footer-link">FAQ</a>
                <a href="<?= e($cpanelUrl) ?>/login.php" class="footer-link">Client Portal</a>
            </div>
            <div>
                <div class="footer-label">Legal</div>
                <a href="terms.php"   class="footer-link">Terms of Service</a>
                <a href="privacy.php" class="footer-link">Privacy Policy</a>
                <a href="refund.php"  class="footer-link">Refund Policy</a>
            </div>
        </div>
        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> <?= e($brandName) ?>. All rights reserved.</span>
            <span style="display:flex;align-items:center;gap:.375rem;"><i data-lucide="heart" style="width:13px;height:13px;color:#f43f5e;fill:#f43f5e;"></i> Made with love</span>
        </div>
    </div>
</footer>

<script>
    lucide.createIcons();

    // Navbar scroll
    const nav = document.getElementById('nav');
    window.addEventListener('scroll', () => {
        nav.classList.toggle('scrolled', window.scrollY > 20);
    }, { passive: true });

    // Scroll animations
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
    }, { threshold: 0.12 });
    document.querySelectorAll('.fade-up').forEach(el => obs.observe(el));

    // FAQ accordion
    function toggleFaq(btn) {
        const item = btn.closest('.faq-item');
        const isOpen = item.classList.contains('open');
        document.querySelectorAll('.faq-item.open').forEach(i => i.classList.remove('open'));
        if (!isOpen) item.classList.add('open');
    }

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', e => {
            const target = document.querySelector(a.getAttribute('href'));
            if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    });
</script>
</body>
</html>