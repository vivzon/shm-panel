<?php
/**
 * VIVZON CLOUD - PAYMENT PROCESSOR
 * Handles order finalization and account provisioning.
 */
require_once __DIR__ . '/../shared/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid Request']);
    exit;
}

// 1. Sanitize Input
$username = preg_replace('/[^a-z0-9]/', '', $_POST['username'] ?? '');
$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = $_POST['password'] ?? '';
$domain = preg_replace('/[^a-z0-9\-\.]/', '', $_POST['domain'] ?? ''); // Simple sanitation
$pkg_id = (int) ($_POST['package_id'] ?? 0);
$tx_id = $_POST['transaction_id'] ?? 'MANUAL_TEST';
$gateway = $_POST['gateway'] ?? 'manual';

if (!$username || !$email || !$password || !$domain || !$pkg_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Missing required fields.']);
    exit;
}

// 2. Check availability
try {
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'error', 'msg' => 'Username or Email already exists.']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Database Error']);
    exit;
}

// 3. Fetch Package logic (optional verify price matches payment)

// 4. Provision Account (Execute System Command)
// We need to append the fake TLD if user didn't provide one, or just assume input is name.
// Checkout form had .com hardcoded in visual, so let's append .com for now or use what was entered.
$full_domain = $domain . ".com";

// Create Hash for DB
$hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $pdo->beginTransaction();

    // A. Create DB Entry
    $stmt = $pdo->prepare("INSERT INTO clients (username, email, password, package_id, status) VALUES (?, ?, ?, ?, 'active')");
    $stmt->execute([$username, $email, $hash, $pkg_id]);
    $client_id = $pdo->lastInsertId();

    // B. Create Domain Entry
    $stmt = $pdo->prepare("INSERT INTO domains (client_id, domain, document_root) VALUES (?, ?, ?)");
    $doc_root = "/var/www/clients/$username/public_html"; // Logic matches shm-manage
    $stmt->execute([$client_id, $full_domain, $doc_root]);

    // C. Log Transaction
    $stmt = $pdo->prepare("INSERT INTO transactions (client_id, amount, payment_gateway, transaction_id, status) VALUES (?, ?, ?, ?, 'paid')");
    // amount would fetched from pkg, defaulting 0 for demo
    $stmt->execute([$client_id, 0.00, $gateway, $tx_id]);

    $pdo->commit();

    // D. System Provisioning
    // Calling shm-manage via sudo wrapper
    // format: create-account user domain email pass
    // IMPORTANT: Escape arguments
    $cmd = sprintf(
        "create-account %s %s %s %s",
        escapeshellarg($username),
        escapeshellarg($full_domain),
        escapeshellarg($email),
        escapeshellarg($password) // Raw password needed for FTP/System user setup? shm-manage takes raw pass
    );

    // Run command in background or wait? shm-manage is fast enough usually.
    $res = cmd($cmd);

    // Log output?
    // file_put_contents('/tmp/provision.log', $res);

    echo json_encode(['status' => 'success', 'msg' => 'Account Provisioned', 'debug' => $res]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'msg' => 'Provisioning Failed: ' . $e->getMessage()]);
}
