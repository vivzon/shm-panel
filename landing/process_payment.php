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

// 3. Fetch Package logic and Verify Price
try {
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$pkg_id]);
    $package = $stmt->fetch();

    if (!$package) {
        echo json_encode(['status' => 'error', 'msg' => 'Invalid Package Selected']);
        exit;
    }
    $pkg_price = $package['price'];
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Database Error: Fetching Package']);
    exit;
}

// 4. Provision Account (Execute System Command)
// We need to append the .com TLD if user didn't provide one, or just assume input is name.
if (strpos($domain, '.') === false) {
    $full_domain = $domain . ".com";
} else {
    $full_domain = $domain;
}

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
    $stmt->execute([$client_id, $pkg_price, $gateway, $tx_id]);

    // Commit changes immediately so the user account exists regardless of script success, 
    // avoiding partial states where the payment is lost.
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
        escapeshellarg($password) // Raw password needed for FTP/System user setup
    );

    // Call shm-manage and capture output
    try {
        $res = cmd($cmd);
    } catch (Exception $execEx) {
        // If provisioning fails, we do not want to fail the whole payment HTTP request.
        // It should flag for manual review, but we'll return a partial success response 
        // to not scare the user and just inform them it's pending.
        error_log("Provisioning failed for $username: " . $execEx->getMessage());

        echo json_encode([
            'status' => 'success',
            'msg' => 'Payment successful, but server setup requires manual review. We will email you shortly.'
        ]);
        exit;
    }

    // E. Send Welcome Email
    $subject = "Welcome to " . get_branding() . " - Your Account is Ready!";
    $message = "Hello $username,\n\n";
    $message .= "Your payment of ₹" . number_format($pkg_price, 2) . " ($gateway) was successful.\n\n";
    $message .= "Here are your hosting details:\n";
    $message .= "Domain: $full_domain\n";
    $message .= "Username: $username\n";
    $message .= "Control Panel URL: " . $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . "/cpanel\n\n";
    $message .= "Thank you for choosing " . get_branding() . "!\n";
    $headers = "From: no-reply@" . $_SERVER['HTTP_HOST'];

    @mail($email, $subject, $message, $headers);

    echo json_encode(['status' => 'success', 'msg' => 'Account Provisioned successfully', 'debug' => $res]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'msg' => 'System Error: ' . $e->getMessage()]);
}
