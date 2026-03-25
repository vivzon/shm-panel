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
                style="font-size: 2rem; font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); letter-spacing: -0.02em; margin-bottom: 0.5rem;">
                Billing History</h2>
            <p style="color: var(--slate-600); font-size: 1rem;">View your past payments and invoices.</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="https://vivzoncloud.com/index.php#pricing" class="btn btn-primary"
                style="display: flex; align-items: center; gap: 0.5rem; text-decoration: none; box-shadow: var(--shadow-md); transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1); border-radius: var(--radius-lg);"
                onmouseover="this.style.transform='translateY(-2px)';"
                onmouseout="this.style.transform='translateY(0)';">
                <i data-lucide="arrow-up-circle" style="width: 1.25rem; height: 1.25rem;"></i> Upgrade Plan
            </a>
        </div>
    </div>

    <div class="glass-card table-card" style="padding: 0; overflow: hidden;">
        <div class="table-container custom-scrollbar">
            <table class="modern-table w-full text-left border-collapse" style="width: 100%;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--slate-200); background-color: var(--slate-50);">
                        <th
                            style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase;">
                            Date</th>
                        <th
                            style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase;">
                            Transaction ID</th>
                        <th
                            style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase;">
                            Amount</th>
                        <th
                            style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase;">
                            Gateway</th>
                        <th
                            style="padding: 1rem 1.5rem; font-weight: 500; color: var(--slate-700); font-size: 0.875rem; letter-spacing: 0.05em; text-transform: uppercase;">
                            Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 3rem 1.5rem; color: var(--slate-500);">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                    <i data-lucide="receipt" style="width: 48px; height: 48px; opacity: 0.5;"></i>
                                    <span>No transactions found.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <tr style="border-bottom: 1px solid var(--slate-100); transition: background-color 0.2s;"
                                onmouseover="this.style.backgroundColor='var(--slate-50)'"
                                onmouseout="this.style.backgroundColor='transparent'">
                                <td style="padding: 1rem 1.5rem;">
                                    <div style="font-weight: 500; color: var(--slate-800);">
                                        <?= date('M d, Y', strtotime($tx['created_at'])) ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--slate-500);">
                                        <?= date('h:i A', strtotime($tx['created_at'])) ?>
                                    </div>
                                </td>
                                <td
                                    style="padding: 1rem 1.5rem; font-family: monospace; font-size: 0.875rem; color: var(--slate-600);">
                                    <?= htmlspecialchars($tx['transaction_id']) ?>
                                </td>
                                <td style="padding: 1rem 1.5rem; font-weight: 800; color: var(--slate-900);">₹
                                    <?= number_format($tx['amount'], 2) ?>
                                </td>
                                <td
                                    style="padding: 1rem 1.5rem; text-transform: capitalize; color: var(--slate-600); font-weight: 500;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <i data-lucide="credit-card"
                                            style="width: 16px; height: 16px; color: var(--slate-400);"></i>
                                        <?= htmlspecialchars($tx['payment_gateway']) ?>
                                    </div>
                                </td>
                                <td style="padding: 1rem 1.5rem;">
                                    <?php if ($tx['status'] === 'paid'): ?>
                                        <span class="badge badge-success" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"><i
                                                data-lucide="check-circle-2"
                                                style="width: 12px; height: 12px; margin-right: 4px; display: inline-block;"></i>Paid</span>
                                    <?php elseif ($tx['status'] === 'pending'): ?>
                                        <span class="badge badge-warning" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"><i
                                                data-lucide="clock"
                                                style="width: 12px; height: 12px; margin-right: 4px; display: inline-block;"></i>Pending</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"><i
                                                data-lucide="alert-circle"
                                                style="width: 12px; height: 12px; margin-right: 4px; display: inline-block;"></i>Failed</span>
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
<script>
    lucide.createIcons();
</script>

<?php include 'layout/footer.php'; ?>