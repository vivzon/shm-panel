<?php
/**
 * SHM Panel - Diagnostic Script
 * Tests environment configuration and identifies potential issues
 */

// Prevent caching
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHM Panel Diagnostics</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            color: #60a5fa;
            border-bottom: 2px solid #1e293b;
            padding-bottom: 10px;
        }
        .test-section {
            background: #1e293b;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #475569;
        }
        .test-section h2 {
            margin-top: 0;
            color: #94a3b8;
            font-size: 18px;
        }
        .pass {
            color: #10b981;
            font-weight: 500;
        }
        .fail {
            color: #ef4444;
            font-weight: 500;
        }
        .warning {
            color: #f59e0b;
            font-weight: 500;
        }
        .info {
            color: #60a5fa;
        }
        .test-item {
            padding: 8px 0;
            border-bottom: 1px solid #334155;
        }
        .test-item:last-child {
            border-bottom: none;
        }
        code {
            background: #0f172a;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 13px;
        }
        pre {
            background: #0f172a;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 12px;
        }
        .summary {
            background: #1e3a8a;
            padding: 15px;
            border-radius: 8px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 SHM Panel System Diagnostics</h1>
        <p>This script tests your environment to identify potential issues with the client login system.</p>

        <?php
        $errors = [];
        $warnings = [];
        $passes = 0;
        $total = 0;

        // Test 1: PHP Version
        echo '<div class="test-section">';
        echo '<h2>1. PHP Environment</h2>';
        
        $total++;
        echo '<div class="test-item">';
        echo '<strong>PHP Version:</strong> ' . phpversion();
        if (version_compare(phpversion(), '7.4.0', '>=')) {
            echo ' <span class="pass">✓ PASS</span>';
            $passes++;
        } else {
            echo ' <span class="fail">✗ FAIL</span> (Requires PHP 7.4+)';
            $errors[] = 'PHP version is too old. Upgrade to PHP 7.4 or higher.';
        }
        echo '</div>';

        // Test 2: Required Extensions
        $required_extensions = ['pdo', 'pdo_mysql', 'session', 'json'];
        foreach ($required_extensions as $ext) {
            $total++;
            echo '<div class="test-item">';
            echo '<strong>Extension ' . $ext . ':</strong> ';
            if (extension_loaded($ext)) {
                echo '<span class="pass">✓ Loaded</span>';
                $passes++;
            } else {
                echo '<span class="fail">✗ Missing</span>';
                $errors[] = "Required PHP extension '$ext' is not loaded.";
            }
            echo '</div>';
        }
        echo '</div>';

        // Test 3: Database Connection
        echo '<div class="test-section">';
        echo '<h2>2. Database Connection</h2>';
        
        $total++;
        echo '<div class="test-item">';
        echo '<strong>Config File:</strong> ';
        
        $config_path = __DIR__ . '/../shared/config.php';
        if (file_exists($config_path)) {
            echo '<span class="pass">✓ Found</span> <code>' . $config_path . '</code>';
            $passes++;
        } else {
            echo '<span class="fail">✗ Not Found</span>';
            $errors[] = 'Config file not found at: ' . $config_path;
        }
        echo '</div>';

        // Try to connect to database
        $total++;
        echo '<div class="test-item">';
        echo '<strong>Database Connection:</strong> ';
        
        try {
            require_once $config_path;
            
            if (isset($pdo) && $pdo instanceof PDO) {
                echo '<span class="pass">✓ Connected</span>';
                $passes++;
                
                // Test clients table
                $total++;
                echo '</div><div class="test-item">';
                echo '<strong>Clients Table:</strong> ';
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM clients");
                    $count = $stmt->fetchColumn();
                    echo '<span class="pass">✓ Exists</span> (' . $count . ' clients)';
                    $passes++;
                } catch (PDOException $e) {
                    echo '<span class="fail">✗ Error</span> - ' . htmlspecialchars($e->getMessage());
                    $errors[] = 'Clients table error: ' . $e->getMessage();
                }
                
                // Test admins table
                $total++;
                echo '</div><div class="test-item">';
                echo '<strong>Admins Table:</strong> ';
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM admins");
                    $count = $stmt->fetchColumn();
                    echo '<span class="pass">✓ Exists</span> (' . $count . ' admins)';
                    $passes++;
                } catch (PDOException $e) {
                    echo '<span class="fail">✗ Error</span> - ' . htmlspecialchars($e->getMessage());
                    $errors[] = 'Admins table error: ' . $e->getMessage();
                }
            } else {
                echo '<span class="fail">✗ Failed</span>';
                $errors[] = 'Database connection failed - PDO object not created.';
            }
        } catch (Exception $e) {
            echo '<span class="fail">✗ Failed</span> - ' . htmlspecialchars($e->getMessage());
            $errors[] = 'Database connection error: ' . $e->getMessage();
        }
        echo '</div>';
        echo '</div>';

        // Test 4: Session Handling
        echo '<div class="test-section">';
        echo '<h2>3. Session Configuration</h2>';
        
        $total++;
        echo '<div class="test-item">';
        echo '<strong>Session Status:</strong> ';
        if (session_status() === PHP_SESSION_ACTIVE) {
            echo '<span class="pass">✓ Active</span>';
            $passes++;
        } else {
            echo '<span class="warning">⚠ Not Started</span>';
            $warnings[] = 'Session not started (this is normal for diagnostic script).';
        }
        echo '</div>';

        $total++;
        echo '<div class="test-item">';
        echo '<strong>Session Save Path:</strong> ';
        $save_path = session_save_path();
        if (empty($save_path)) {
            $save_path = sys_get_temp_dir();
        }
        echo '<code>' . htmlspecialchars($save_path) . '</code> ';
        if (is_writable($save_path)) {
            echo '<span class="pass">✓ Writable</span>';
            $passes++;
        } else {
            echo '<span class="fail">✗ Not Writable</span>';
            $errors[] = 'Session save path is not writable: ' . $save_path;
        }
        echo '</div>';
        echo '</div>';

        // Test 5: File Permissions
        echo '<div class="test-section">';
        echo '<h2>4. File Permissions</h2>';
        
        $files_to_check = [
            '../cpanel/login.php' => 'Client Login',
            '../whm/login.php' => 'Admin Login',
            '../shared/config.php' => 'Config File',
        ];
        
        foreach ($files_to_check as $file => $label) {
            $total++;
            echo '<div class="test-item">';
            echo '<strong>' . $label . ':</strong> ';
            $full_path = __DIR__ . '/' . $file;
            if (file_exists($full_path)) {
                if (is_readable($full_path)) {
                    echo '<span class="pass">✓ Readable</span>';
                    $passes++;
                } else {
                    echo '<span class="fail">✗ Not Readable</span>';
                    $errors[] = "$label is not readable: $full_path";
                }
            } else {
                echo '<span class="fail">✗ Not Found</span>';
                $errors[] = "$label not found: $full_path";
            }
            echo '</div>';
        }
        echo '</div>';

        // Test 6: Server Info
        echo '<div class="test-section">';
        echo '<h2>5. Server Information</h2>';
        echo '<div class="test-item"><strong>Server Software:</strong> ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . '</div>';
        echo '<div class="test-item"><strong>Document Root:</strong> <code>' . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . '</code></div>';
        echo '<div class="test-item"><strong>Script Filename:</strong> <code>' . __FILE__ . '</code></div>';
        echo '<div class="test-item"><strong>Current User:</strong> ' . get_current_user() . '</div>';
        echo '</div>';

        // Summary
        $pass_rate = $total > 0 ? round(($passes / $total) * 100) : 0;
        echo '<div class="summary">';
        echo '<h2>📊 Summary</h2>';
        echo '<p><strong>Tests Passed:</strong> ' . $passes . ' / ' . $total . ' (' . $pass_rate . '%)</p>';
        
        if (count($errors) > 0) {
            echo '<h3 style="color: #ef4444;">❌ Errors Found:</h3>';
            echo '<ul>';
            foreach ($errors as $error) {
                echo '<li>' . htmlspecialchars($error) . '</li>';
            }
            echo '</ul>';
        }
        
        if (count($warnings) > 0) {
            echo '<h3 style="color: #f59e0b;">⚠️ Warnings:</h3>';
            echo '<ul>';
            foreach ($warnings as $warning) {
                echo '<li>' . htmlspecialchars($warning) . '</li>';
            }
            echo '</ul>';
        }
        
        if (count($errors) === 0 && count($warnings) === 0) {
            echo '<p style="color: #10b981; font-size: 18px;">✅ All tests passed! Your environment looks good.</p>';
            echo '<p>If the client login is still not loading, please check:</p>';
            echo '<ul>';
            echo '<li>Browser console for JavaScript errors (F12 → Console tab)</li>';
            echo '<li>Network tab for failed HTTP requests (F12 → Network tab)</li>';
            echo '<li>Web server error logs (Apache/Nginx)</li>';
            echo '</ul>';
        }
        echo '</div>';
        ?>

        <div style="margin-top: 30px; padding: 15px; background: #334155; border-radius: 8px;">
            <h3>🔗 Quick Links</h3>
            <p>
                <a href="../cpanel/login.php" style="color: #60a5fa;">Client Login</a> | 
                <a href="../whm/login.php" style="color: #60a5fa;">Admin Login</a> | 
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" style="color: #60a5fa;">Refresh Diagnostics</a>
            </p>
        </div>
    </div>
</body>
</html>
