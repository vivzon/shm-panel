<?php
/**
 * Example: Secure Domain Management
 * 
 * This demonstrates how to update cpanel/domains.php with security fixes
 * Shows CSRF protection, prepared statements, and input validation
 */

require_once '../shared/session.php';
require_once '../shared/security.php';
require_once '../shared/Database.php';

// Require client login
require_login('client');

$db = Database::getInstance();
$clientId = get_user_id('client');
$username = get_username('client');

$error = '';
$success = '';

// Handle domain creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    try {
        // CSRF Protection
        verify_csrf_token($_POST['csrf_token'] ?? '');

        // Input validation
        $domain = sanitize_input($_POST['domain'] ?? '', 'string');

        if (!validate_input($domain, 'domain')) {
            throw new Exception('Invalid domain format');
        }

        // Check if domain already exists
        $existing = $db->fetchOne("SELECT id FROM domains WHERE domain = ?", [$domain]);
        if ($existing) {
            throw new Exception('Domain already exists');
        }

        // Check package limits
        $client = $db->fetchOne("SELECT package_id FROM clients WHERE id = ?", [$clientId]);
        $package = $db->fetchOne("SELECT max_domains FROM packages WHERE id = ?", [$client['package_id']]);
        $currentDomains = $db->fetchOne("SELECT COUNT(*) as count FROM domains WHERE client_id = ?", [$clientId]);

        if ($currentDomains['count'] >= $package['max_domains']) {
            throw new Exception('Domain limit reached for your package');
        }

        // Begin transaction
        $db->beginTransaction();

        try {
            // Insert domain into database
            $db->execute(
                "INSERT INTO domains (client_id, domain, document_root, php_version, created_at) VALUES (?, ?, ?, '8.2', NOW())",
                [$clientId, $domain, "/var/www/clients/$username/domains/$domain/public_html"]
            );

            $domainId = $db->lastInsertId();

            // Execute system command to create domain
            $escapedUsername = escape_shell_arg_safe($username);
            $escapedDomain = escape_shell_arg_safe($domain);

            $output = shell_exec("sudo /usr/local/bin/shm-manage add-domain $escapedUsername $escapedDomain 2>&1");

            if (strpos($output, 'successfully') === false) {
                throw new Exception('Failed to create domain on system: ' . $output);
            }

            // Commit transaction
            $db->commit();

            // Log success
            log_security_event('Domain created', 'info', [
                'domain' => $domain,
                'client_id' => $clientId
            ]);

            set_flash_message("Domain $domain created successfully!", 'success');
            header('Location: domains.php');
            exit;

        } catch (Exception $e) {
            // Rollback on error
            $db->rollback();
            throw $e;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
        log_security_event('Domain creation failed', 'warning', [
            'domain' => $domain ?? 'unknown',
            'error' => $e->getMessage()
        ]);
    }
}

// Handle domain deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $domainId = filter_var($_POST['domain_id'] ?? 0, FILTER_VALIDATE_INT);

        if (!$domainId) {
            throw new Exception('Invalid domain ID');
        }

        // Verify domain belongs to this client
        $domain = $db->fetchOne(
            "SELECT * FROM domains WHERE id = ? AND client_id = ?",
            [$domainId, $clientId]
        );

        if (!$domain) {
            throw new Exception('Domain not found or access denied');
        }

        // Begin transaction
        $db->beginTransaction();

        try {
            // Delete from database
            $db->execute("DELETE FROM domains WHERE id = ?", [$domainId]);

            // Execute system command
            $escapedUsername = escape_shell_arg_safe($username);
            $escapedDomain = escape_shell_arg_safe($domain['domain']);

            shell_exec("sudo /usr/local/bin/shm-manage delete-domain $escapedUsername $escapedDomain 2>&1");

            $db->commit();

            log_security_event('Domain deleted', 'info', [
                'domain' => $domain['domain'],
                'client_id' => $clientId
            ]);

            set_flash_message("Domain {$domain['domain']} deleted successfully!", 'success');
            header('Location: domains.php');
            exit;

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch all domains for this client (using prepared statement)
$domains = $db->fetchAll(
    "SELECT d.*, 
            (SELECT COUNT(*) FROM dns_records WHERE domain_id = d.id) as dns_count
     FROM domains d 
     WHERE d.client_id = ? 
     ORDER BY d.created_at DESC",
    [$clientId]
);

// Get flash message
$flash = get_flash_message();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domains - SHM Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50">

    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Domain Management</h1>
            <p class="text-gray-600 mt-2">Manage your domains and DNS settings</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div
                class="bg-<?= $flash['type'] === 'success' ? 'green' : 'blue' ?>-50 border border-<?= $flash['type'] === 'success' ? 'green' : 'blue' ?>-200 text-<?= $flash['type'] === 'success' ? 'green' : 'blue' ?>-800 px-4 py-3 rounded-lg mb-6">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Add Domain Form -->
        <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">Add New Domain</h2>
            <form method="POST" class="flex gap-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create">

                <input type="text" name="domain" placeholder="example.com" required
                    pattern="[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]?(\.[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]?)*"
                    class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">

                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    Add Domain
                </button>
            </form>
        </div>

        <!-- Domains List -->
        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Domain</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">SSL</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">DNS Records</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($domains)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                No domains yet. Add your first domain above.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($domains as $domain): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900">
                                        <?= htmlspecialchars($domain['domain']) ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        PHP
                                        <?= htmlspecialchars($domain['php_version']) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($domain['ssl_active']): ?>
                                        <span
                                            class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">Active</span>
                                    <?php else: ?>
                                        <span
                                            class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?= $domain['dns_count'] ?> records
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?= date('M d, Y', strtotime($domain['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <form method="POST" class="inline"
                                        onsubmit="return confirm('Are you sure you want to delete this domain?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="domain_id" value="<?= $domain['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 font-medium text-sm">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</body>

</html>