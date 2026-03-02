<?php
/**
 * VIVZON CLOUD - CHECKOUT PAGE (Modern Vanilla CSS)
 * Handles account registration and payment selection.
 */
require_once __DIR__ . '/../shared/config.php';

$pkg_id = $_GET['pkg'] ?? 0;

// Fetch Selected Package
try {
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$pkg_id]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $package = null;
}

if (!$package) {
    header("Location: index.php");
    exit;
}

// Config (Replace with real keys or load from DB)
$RAZORPAY_KEY = "rzp_test_YOUR_KEY_HERE";
$PAYPAL_CLIENT_ID = "sb"; // Sandbox Client ID
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?= htmlspecialchars($package['name']) ?> | Vivzon Cloud</title>

    <link rel="stylesheet" href="/assets/css/modern-design.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- PayPal SDK -->
    <script src="https://www.paypal.com/sdk/js?client-id=<?= $PAYPAL_CLIENT_ID ?>&currency=INR"></script>
    <!-- Razorpay SDK -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>

    <style>
        body {
            background-color: var(--slate-900);
            color: var(--slate-50);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1rem;
        }

        .checkout-container {
            width: 100%;
            max-width: 1024px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        @media(min-width: 1024px) {
            .checkout-container {
                grid-template-columns: 2fr 1fr;
            }
        }

        .glass-panel-dark {
            background: rgba(30, 41, 59, 0.4);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-xl);
            padding: 2rem;
            color: white;
        }

        .dark-input {
            width: 100%;
            background: var(--slate-900);
            border: 1px solid var(--slate-700);
            border-radius: var(--radius-md);
            padding: 0.75rem;
            color: white;
            outline: none;
            transition: all 0.3s;
            font-family: inherit;
        }

        .dark-input:focus {
            border-color: var(--primary);
        }

        .domain-input-group {
            display: flex;
        }

        .domain-input-group .dark-input {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }

        .domain-addon {
            background: var(--slate-800);
            border: 1px solid var(--slate-700);
            border-left: none;
            border-top-right-radius: var(--radius-md);
            border-bottom-right-radius: var(--radius-md);
            padding: 0 1rem;
            display: flex;
            align-items: center;
            color: var(--slate-500);
            font-size: 0.875rem;
        }

        .payment-radio {
            display: none;
        }

        .payment-option {
            cursor: pointer;
            padding: 1rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--slate-700);
            background: rgba(30, 41, 59, 0.5);
            text-align: center;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .payment-radio:checked+.payment-option {
            border-color: var(--primary);
            background: rgba(37, 99, 235, 0.15);
        }

        /* Utility */
        .grid-2 {
            display: grid;
            gap: 1.5rem;
        }

        @media(min-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr 1fr;
            }
        }

        .label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--slate-400);
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .back-btn {
            display: inline-flex;
            padding: 0.5rem;
            border-radius: var(--radius-md);
            color: var(--slate-300);
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .summary-list {
            list-style: none;
            padding: 0;
            margin: 0 0 2rem 0;
        }

        .summary-list li {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            color: var(--slate-400);
            font-size: 0.875rem;
        }

        .summary-list li span:last-child {
            color: white;
            font-weight: 500;
        }

        .btn-checkout {
            width: 100%;
            padding: 1rem;
            background: var(--primary);
            color: white;
            font-weight: 700;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 1.5rem;
            font-size: 1rem;
        }

        .btn-checkout:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
        }

        .hidden {
            display: none !important;
        }

        .text-emerald-400 {
            color: var(--accent-emerald) !important;
        }
    </style>
</head>

<body>

    <div class="checkout-container">

        <!-- Left: Form -->
        <div class="glass-panel-dark">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:2rem;">
                <a href="index.php" class="back-btn"><i data-lucide="arrow-left"></i></a>
                <h1 style="font-size:1.5rem;font-family:'Outfit',sans-serif;font-weight:700;">Configure Your Server</h1>
            </div>

            <form id="checkoutForm">
                <input type="hidden" name="package_id" value="<?= $package['id'] ?>">

                <div class="grid-2" style="margin-bottom:1.5rem;">
                    <div>
                        <label class="label">Username</label>
                        <input type="text" name="username" required pattern="[a-z0-9]{3,16}"
                            title="Lowercase, numbers, 3-16 chars" class="dark-input" placeholder="jdoe">
                    </div>
                    <div>
                        <label class="label">Email Address</label>
                        <input type="email" name="email" required class="dark-input" placeholder="john@example.com">
                    </div>
                </div>

                <div style="margin-bottom:1.5rem;">
                    <label class="label">Primary Domain</label>
                    <div class="domain-input-group">
                        <input type="text" name="domain" required class="dark-input" placeholder="example">
                        <span class="domain-addon">.com</span>
                    </div>
                    <p style="font-size:0.75rem;color:var(--slate-500);margin-top:0.5rem;">Enter domain without
                        extension (extension demo only)</p>
                </div>

                <div style="margin-bottom:2rem;">
                    <label class="label">Password</label>
                    <input type="password" name="password" required minlength="8" class="dark-input"
                        placeholder="••••••••">
                </div>

                <div style="border-top:1px solid rgba(255,255,255,0.05);padding-top:1.5rem;">
                    <h3 style="font-size:1.125rem;font-weight:700;margin-bottom:1rem;">Payment Method</h3>
                    <div class="grid-2">
                        <label>
                            <input type="radio" name="gateway" value="razorpay" class="payment-radio" checked>
                            <div class="payment-option">
                                <span style="font-weight:700;">Razorpay</span>
                                <span style="font-size:0.75rem;color:var(--slate-400);">Cards, UPI, Netbanking</span>
                            </div>
                        </label>
                        <label>
                            <input type="radio" name="gateway" value="paypal" class="payment-radio">
                            <div class="payment-option">
                                <span style="font-weight:700;">PayPal</span>
                                <span style="font-size:0.75rem;color:var(--slate-400);">International Cards</span>
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit" id="payBtn" class="btn-checkout">
                    Secure Checkout (₹<?= number_format($package['price'], 2) ?>/mo)
                </button>
            </form>

            <div id="paypal-button-container" class="mt-4 hidden"></div>
        </div>

        <!-- Right: Summary -->
        <div class="glass-panel-dark" style="height:fit-content;">
            <h3 style="font-size:1.125rem;font-weight:700;margin-bottom:1.5rem;color:var(--slate-300);">Order Summary
            </h3>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <span style="font-weight:700;font-size:1.25rem;"><?= htmlspecialchars($package['name']) ?></span>
                <span
                    style="font-weight:700;font-size:1.25rem;color:var(--primary);">₹<?= number_format($package['price'], 2) ?></span>
            </div>

            <ul class="summary-list">
                <li><span>Disk Space</span> <span><?= $package['disk_mb'] ?> MB</span></li>
                <li><span>Domains</span> <span><?= $package['max_domains'] ?></span></li>
                <li><span>Databases</span> <span><?= $package['max_databases'] ?></span></li>
                <li><span>Setup Fee</span> <span class="text-emerald-400">FREE</span></li>
            </ul>

            <div
                style="border-top:1px solid rgba(255,255,255,0.1);padding-top:1rem;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-weight:700;">Total Due Today</span>
                <span
                    style="font-weight:700;font-size:1.5rem;color:white;">₹<?= number_format($package['price'], 2) ?></span>
            </div>

            <div style="margin-top:2rem;font-size:0.75rem;color:var(--slate-500);text-align:center;">
                <p>30-Day Money Back Guarantee</p>
                <p style="margin-top:0.5rem;">By continuing, you agree to our Terms of Service.</p>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const form = document.getElementById('checkoutForm');
        const payBtn = document.getElementById('payBtn');
        const ppContainer = document.getElementById('paypal-button-container');
        const radios = document.getElementsByName('gateway');

        // Toggle PayPal Button
        radios.forEach(r => {
            r.addEventListener('change', (e) => {
                if (e.target.value === 'paypal') {
                    payBtn.classList.add('hidden');
                    ppContainer.classList.remove('hidden');
                } else {
                    payBtn.classList.remove('hidden');
                    ppContainer.classList.add('hidden');
                }
            });
        });

        // Form Submission (Razorpay / Manual)
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(form);
            const gateway = fd.get('gateway');

            if (gateway === 'razorpay') {
                // Initialize Razorpay
                const options = {
                    "key": "<?= $RAZORPAY_KEY ?>",
                    "amount": <?= $package['price'] * 100 ?>, // Amount in paisa/cents
                    "currency": "INR",
                    "name": "Vivzon Cloud",
                    "description": "Hosting Plan: <?= $package['name'] ?>",
                    "handler": function (response) {
                        // Send payment ID to backend to finalize
                        finalizeOrder(fd, response.razorpay_payment_id);
                    },
                    "prefill": {
                        "name": fd.get('username'),
                        "email": fd.get('email')
                    },
                    "theme": { "color": "#2563eb" }
                };
                const rzp1 = new Razorpay(options);
                rzp1.open();
            }
        });

        // PayPal Buttons
        paypal.Buttons({
            createOrder: function (data, actions) {
                return actions.order.create({
                    purchase_units: [{
                        amount: { value: '<?= $package['price'] ?>' }
                    }]
                });
            },
            onApprove: function (data, actions) {
                return actions.order.capture().then(function (details) {
                    const fd = new FormData(form);
                    finalizeOrder(fd, details.id);
                });
            }
        }).render('#paypal-button-container');

        async function finalizeOrder(formData, txId) {
            formData.append('transaction_id', txId);

            // Show loading state
            document.body.innerHTML = '<div style="color:white;text-align:center;"><h1 style="font-size:1.5rem;font-weight:700;">Provisioning Server...</h1><p>Please do not close this window.</p></div>';

            try {
                const res = await fetch('process_payment.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.status === 'success') {
                    window.location.href = '../cpanel/login.php?msg=welcome';
                } else {
                    alert('Error: ' + data.msg);
                    location.reload();
                }
            } catch (e) {
                alert('Server Connection Failed');
                location.reload();
            }
        }
    </script>
</body>

</html>