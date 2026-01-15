<?php
/**
 * VIVZON CLOUD - CHECKOUT PAGE (v5.0)
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
    <title>Checkout -
        <?= htmlspecialchars($package['name']) ?> | Vivzon Cloud
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- PayPal SDK -->
    <script src="https://www.paypal.com/sdk/js?client-id=<?= $PAYPAL_CLIENT_ID ?>&currency=USD"></script>
    <!-- Razorpay SDK -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: white;
        }

        .glass-panel {
            background: rgba(30, 41, 59, 0.4);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
    </style>
</head>

<body class="min-h-screen py-10 px-4 flex items-center justify-center">

    <div class="max-w-5xl w-full grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Left: Form -->
        <div class="lg:col-span-2 glass-panel p-8 rounded-3xl">
            <div class="flex items-center gap-3 mb-8">
                <a href="index.php" class="p-2 rounded-lg hover:bg-white/5 transition"><i data-lucide="arrow-left"
                        class="w-5 h-5"></i></a>
                <h1 class="text-2xl font-bold font-heading">Configure Your Server</h1>
            </div>

            <form id="checkoutForm" class="space-y-6">
                <input type="hidden" name="package_id" value="<?= $package['id'] ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Username</label>
                        <input type="text" name="username" required pattern="[a-z0-9]{3,16}"
                            title="Lowercase, numbers, 3-16 chars"
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:border-blue-500 outline-none transition"
                            placeholder="jdoe">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Email Address</label>
                        <input type="email" name="email" required
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:border-blue-500 outline-none transition"
                            placeholder="john@example.com">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Primary Domain</label>
                    <div class="flex">
                        <input type="text" name="domain" required
                            class="w-full bg-slate-900 border border-slate-700 rounded-l-xl p-3 focus:border-blue-500 outline-none transition"
                            placeholder="example">
                        <span
                            class="bg-slate-800 border border-l-0 border-slate-700 rounded-r-xl px-4 flex items-center text-slate-400 text-sm">.com</span>
                    </div>
                    <p class="text-xs text-slate-500 mt-2">Enter domain without extension (extension demo only)</p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Password</label>
                    <input type="password" name="password" required minlength="8"
                        class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:border-blue-500 outline-none transition"
                        placeholder="••••••••">
                </div>

                <div class="pt-6 border-t border-white/5">
                    <h3 class="text-lg font-bold mb-4">Payment Method</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="cursor-pointer">
                            <input type="radio" name="gateway" value="razorpay" class="peer hidden" checked>
                            <div
                                class="p-4 rounded-xl border border-slate-700 bg-slate-800/50 peer-checked:border-blue-500 peer-checked:bg-blue-600/10 transition flex flex-col items-center gap-2">
                                <span class="font-bold">Razorpay</span>
                                <span class="text-xs text-slate-400">Cards, UPI, Netbanking</span>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="gateway" value="paypal" class="peer hidden">
                            <div
                                class="p-4 rounded-xl border border-slate-700 bg-slate-800/50 peer-checked:border-blue-500 peer-checked:bg-blue-600/10 transition flex flex-col items-center gap-2">
                                <span class="font-bold">PayPal</span>
                                <span class="text-xs text-slate-400">International Cards</span>
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit" id="payBtn"
                    class="w-full py-4 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-xl shadow-lg shadow-blue-600/20 transition mt-6">
                    Secure Checkout ($
                    <?= number_format($package['price'], 2) ?>/mo)
                </button>
            </form>

            <div id="paypal-button-container" class="mt-6 hidden"></div>
        </div>

        <!-- Right: Summary -->
        <div class="glass-panel p-8 rounded-3xl h-fit">
            <h3 class="text-lg font-bold mb-6 text-slate-300">Order Summary</h3>

            <div class="flex justify-between items-center mb-4">
                <span class="font-bold text-xl">
                    <?= htmlspecialchars($package['name']) ?>
                </span>
                <span class="font-bold text-xl text-blue-400">$
                    <?= number_format($package['price'], 2) ?>
                </span>
            </div>

            <ul class="space-y-3 mb-8 text-sm text-slate-400">
                <li class="flex justify-between"><span>Disk Space</span> <span class="text-white">
                        <?= $package['disk_mb'] ?> MB
                    </span></li>
                <li class="flex justify-between"><span>Domains</span> <span class="text-white">
                        <?= $package['max_domains'] ?>
                    </span></li>
                <li class="flex justify-between"><span>Databases</span> <span class="text-white">
                        <?= $package['max_databases'] ?>
                    </span></li>
                <li class="flex justify-between"><span>Setup Fee</span> <span class="text-emerald-400">FREE</span></li>
            </ul>

            <div class="border-t border-white/10 pt-4 flex justify-between items-center">
                <span class="font-bold">Total Due Today</span>
                <span class="font-bold text-2xl text-white">$
                    <?= number_format($package['price'], 2) ?>
                </span>
            </div>

            <div class="mt-8 text-xs text-slate-500 text-center">
                <p>30-Day Money Back Guarantee</p>
                <p class="mt-2">By continuing, you agree to our Terms of Service.</p>
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
                    "currency": "USD",
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
            document.body.innerHTML = '<div class="text-white text-center"><h1 class="text-2xl font-bold animate-pulse">Provisioning Server...</h1><p>Please do not close this window.</p></div>';

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