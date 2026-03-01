<?php
/**
 * VIVZON CLOUD - BILLING HISTORY (Modern Vanilla CSS)
 */
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_funds') {
    $amount = (float) ($_POST['amount'] ?? 0);
    $tx_id = $_POST['transaction_id'] ?? 'RZP_' . uniqid();

    if ($amount > 0) {
        $stmt = $pdo->prepare("INSERT INTO transactions (client_id, invoice_id, amount, status, payment_gateway, transaction_id) VALUES (?, NULL, ?, 'paid', 'razorpay', ?)");
        $stmt->execute([$cid, $amount, $tx_id]);

        header("Content-Type: application/json");
        echo json_encode(['status' => 'success', 'msg' => 'Funds added successfully']);
        exit;
    }
}

// Fetch transactions
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$cid]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'layout/header.php';
?>

<div style="display: flex; flex-direction: column; gap: 2rem;">
    <div
        style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 1rem; border-bottom: 1px solid var(--slate-300); padding-bottom: 1.5rem;">
        <div>
            <h2
                style="font-size: 1.875rem; line-height: 2.25rem; font-weight: 700; color: var(--slate-900); font-family: 'Lexend', sans-serif; letter-spacing: -0.025em; margin-bottom: 0.5rem;">
                Billing History</h2>
            <p style="color: var(--slate-700);">View your past payments and invoices.</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <button id="addFundsBtn" class="btn btn-secondary"
                style="display: flex; align-items: center; gap: 0.5rem; border: none; cursor: pointer;">
                <i data-lucide="plus-circle" style="width: 1.25rem; height: 1.25rem;"></i> Add Funds
            </button>
            <a href="../landing/index.php#pricing" class="btn btn-primary"
                style="display: flex; align-items: center; gap: 0.5rem; text-decoration: none;">
                <i data-lucide="arrow-up-circle" style="width: 1.25rem; height: 1.25rem;"></i> Upgrade Plan
            </a>
        </div>
    </div>

    <div class="glass-panel p-0 overflow-hidden">
        <div class="table-container">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transaction ID</th>
                        <th>Amount</th>
                        <th>Gateway</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="5"
                                style="text-align: center; padding-top: 2rem; padding-bottom: 2rem; color: var(--slate-500);">
                                No transactions found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500; color: var(--slate-800);">
                                        <?= date('M d, Y', strtotime($tx['created_at'])) ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--slate-500);">
                                        <?= date('h:i A', strtotime($tx['created_at'])) ?>
                                    </div>
                                </td>
                                <td style="font-family: monospace; font-size: 0.75rem;">
                                    <?= htmlspecialchars($tx['transaction_id']) ?>
                                </td>
                                <td style="font-weight: 700; color: var(--slate-900);">₹
                                    <?= number_format($tx['amount'], 2) ?>
                                </td>
                                <td style="text-transform: capitalize; color: var(--slate-600);">
                                    <?= htmlspecialchars($tx['payment_gateway']) ?>
                                </td>
                                <td>
                                    <?php if ($tx['status'] === 'paid'): ?>
                                        <span class="badge badge-success">Paid</span>
                                    <?php elseif ($tx['status'] === 'pending'): ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Failed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const addFundsBtn = document.getElementById('addFundsBtn');
        if (addFundsBtn) {
            addFundsBtn.addEventListener('click', () => {
                const amountStr = prompt("Enter amount to add (in ₹):", "500");
                if (!amountStr) return;
                const amount = parseFloat(amountStr);
                if (isNaN(amount) || amount <= 0) {
                    alert("Please enter a valid amount.");
                    return;
                }

                const options = {
                    "key": "rzp_test_YOUR_KEY_HERE", // Replace with real key
                    "amount": amount * 100, // Amount in paisa
                    "currency": "INR",
                    "name": "Vivzon Cloud",
                    "description": "Add Funds to Wallet",
                    "handler": async function (response) {
                        const fd = new FormData();
                        fd.append('ajax_action', 'add_funds');
                        fd.append('amount', amount);
                        fd.append('transaction_id', response.razorpay_payment_id);

                        try {
                            const res = await fetch('billing.php', { method: 'POST', body: fd }).then(r => r.json());
                            if (res.status === 'success') {
                                alert('Funds added successfully!');
                                window.location.reload();
                            } else {
                                alert('Failed to add funds.');
                            }
                        } catch (e) {
                            alert('System error.');
                            console.error(e);
                        }
                    },
                    "theme": { "color": "#2563eb" }
                };
                const rzp = new Razorpay(options);
                rzp.open();
            });
        }
    });
</script>

<?php include 'layout/footer.php'; ?>