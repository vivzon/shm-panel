<?php
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

$clientUrl = $scheme . '' . $base;
$brandName = get_branding();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms & Conditions -
        <?= e($brandName) ?>
    </title>
    <link rel="stylesheet" href="/assets/css/modern-design.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .page-header {
            padding: 8rem 0 4rem;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(99, 102, 241, 0.05));
            border-bottom: 1px solid var(--slate-200);
            text-align: center;
        }

        .page-title {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .content-section {
            padding: 4rem 0;
            max-width: 800px;
            margin: 0 auto;
        }

        .content-card {
            background: var(--bg-surface);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-xl);
            padding: 3rem;
            box-shadow: var(--shadow-sm);
        }

        .content-card h2 {
            font-size: 1.5rem;
            margin: 2rem 0 1rem;
            color: var(--slate-900);
        }

        .content-card h2:first-child {
            margin-top: 0;
        }

        .content-card p {
            color: var(--slate-600);
            line-height: 1.7;
            margin-bottom: 1rem;
        }

        .content-card ul {
            color: var(--slate-600);
            line-height: 1.7;
            margin-bottom: 1rem;
            padding-left: 1.5rem;
        }

        .content-card li {
            margin-bottom: 0.5rem;
        }

        /* Navbar Layout */
        .navbar {
            position: fixed;
            width: 100%;
            z-index: 50;
            background: rgba(255, 255, 255, 0.97);
            box-shadow: var(--shadow-sm);
            border-bottom: 1px solid var(--slate-200);
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
    </style>
</head>

<body>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="container navbar-content">
            <a href="index.php" class="nav-brand">
                <div class="nav-brand-icon"><i data-lucide="cloud" style="width:20px;height:20px;"></i></div>
                <span>
                    <?= e(strtoupper($brandName)) ?>
                </span>
            </a>
            <div class="flex items-center gap-3">
                <a href="index.php" class="btn btn-secondary">
                    <i data-lucide="arrow-left" style="width:16px;height:16px;"></i> Back to Home
                </a>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <header class="page-header">
        <div class="container">
            <h1 class="page-title">Terms & Conditions</h1>
            <p style="color:var(--slate-600);">Last updated:
                <?= date('F d, Y') ?>
            </p>
        </div>
    </header>

    <!-- Content -->
    <section class="content-section">
        <div class="container">
            <div class="content-card">
                <h2>1. Acceptance of Terms</h2>
                <p>Welcome to
                    <?= e($brandName) ?>. By accessing or using our services, you agree to comply with and be bound by
                    these Terms & Conditions. If you do not agree, please do not use our services.
                </p>

                <h2>2. Service Provision</h2>
                <p>We provide cloud hosting services, including but not limited to shared hosting, cloud instances,
                    domain registration, and related tools. We reserve the right to modify, suspend, or discontinue any
                    part of our service at any time with or without notice.</p>

                <h2>3. Acceptable Use Policy</h2>
                <p>You agree not to use our services for any illegal or unauthorized purpose. Prohibited activities
                    include, but are not limited to:</p>
                <ul>
                    <li>Hosting malicious software, phishing sites, or botnets.</li>
                    <li>Sending unsolicited bulk email (spam).</li>
                    <li>Engaging in activities that overload our network infrastructure (e.g., DDoS attacks).</li>
                    <li>Hosting pirated content or engaging in copyright infringement.</li>
                </ul>
                <p>Violation of these policies will result in immediate termination of your account without a refund.
                </p>

                <h2>4. Account Responsibilities</h2>
                <p>You are responsible for maintaining the security of your account, passwords, and any action that
                    occurs under your account. We strongly recommend using strong passwords and implementing Two-Factor
                    Authentication (2FA).</p>

                <h2>5. Billing and Payments</h2>
                <p>All services are billed on a recurring basis unless designated as a one-time payment. You are
                    responsible for ensuring that your payment method on file remains active and valid. Failure to pay
                    for services may result in suspension or termination.</p>

                <h2>6. Backups and Data Loss</h2>
                <p>While we may perform regular automated backups as part of certain plans, you agree to maintain your
                    own local backup of all data hosted on our servers. We are not liable for any data loss, corruption,
                    or hardware failure.</p>

                <h2>7. Limitation of Liability</h2>
                <p>In no event shall
                    <?= e($brandName) ?> be liable for any indirect, incidental, special, consequential, or punitive
                    damages arising from the use or inability to use our services.
                </p>

                <h2>8. Contact Information</h2>
                <p>If you have any questions about these Terms, please contact us at support@
                    <?= e($base) ?>.
                </p>
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
                        <span>
                            <?= e(strtoupper($brandName)) ?>
                        </span>
                    </div>
                    <p style="color:var(--slate-500);font-size:0.875rem;line-height:1.6;max-width:300px;">
                        Enterprise-grade cloud hosting trusted by developers and agencies.
                    </p>
                </div>
                <div class="footer-col">
                    <h4>Platform</h4>
                    <a href="index.php#features" class="footer-link">Features</a>
                    <a href="index.php#pricing" class="footer-link">Pricing</a>
                    <a href="<?= e($clientUrl) ?>" class="footer-link">Client Portal</a>
                </div>
                <div class="footer-col">
                    <h4>Legal & Support</h4>
                    <a href="mailto:support@<?= e($base) ?>" class="footer-link">Email Support</a>
                    <a href="privacy.php" class="footer-link">Privacy Policy</a>
                    <a href="terms.php" class="footer-link">Terms & Conditions</a>
                    <a href="refund.php" class="footer-link">Refund Policy</a>
                </div>
            </div>
            <div class="footer-bottom">
                <div>&copy;
                    <?= date('Y') ?>
                    <?= e($brandName) ?> - Premium Cloud Hosting. All rights reserved.
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    Prices are listed without GST
                </div>
            </div>
        </div>
    </footer>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>