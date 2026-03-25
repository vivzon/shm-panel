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
    <title>Refund Policy -
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
            font-weight: 500;
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
            <h1 class="page-title">Refund Policy</h1>
            <p style="color:var(--slate-600);">Last updated:
                <?= date('F d, Y') ?>
            </p>
        </div>
    </header>

    <!-- Content -->
    <section class="content-section">
        <div class="container">
            <div class="content-card">
                <h2>1. 7-Day Money-Back Guarantee</h2>
                <p>We are confident in the quality of our hosting. If you are not completely satisfied with our shared
                    or cloud hosting services, you can request a full refund within the first 7 days of your initial
                    purchase.</p>
                <p>To request a refund, please open a support ticket from your Client Portal or email us directly.
                    Refunds will be processed to the original payment method within 5-7 business days.</p>

                <h2>2. Non-Refundable Services</h2>
                <p>Please note that the following products and services are strictly non-refundable:</p>
                <ul>
                    <li>Domain Name Registrations, Transfers, and Renewals</li>
                    <li>SSL Certificates</li>
                    <li>Dedicated Server Hosting</li>
                    <li>Any customized configuration or administrative services</li>
                    <li>Service renewals</li>
                </ul>

                <h2>3. Terminations and Suspensions</h2>
                <p>Accounts that are terminated or suspended due to violations of our Terms & Conditions (such as
                    spamming, hosting malicious content, or engaging in abuse) are entirely excluded from any refund
                    guarantees.</p>

                <h2>4. Upgrade and Downgrade Rules</h2>
                <p>If you choose to downgrade your hosting plan, no partial refunds or credits will be issued for the
                    difference in plan pricing.</p>

                <h2>5. Disputed Charges and Chargebacks</h2>
                <p>Initiating a chargeback or dispute with your bank or credit card provider will result in immediate
                    suspension of your account pending resolution. We highly recommend contacting our support team to
                    resolve billing issues first.</p>

                <h2>6. Process for Requesting a Refund</h2>
                <p>If you meet the criteria and are within the initial 7-day period, please reach out to our billing
                    team at support@
                    <?= e($base) ?>. Include your account details, invoice number, and reason for cancellation so we can
                    process it promptly.
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