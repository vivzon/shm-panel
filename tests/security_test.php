<?php
/**
 * Security Test Suite
 * 
 * Run this file to test all security implementations
 * Access via: https://client.vivzon.cloud/tests/security_test.php
 * 
 * WARNING: Remove this file from production servers!
 */

// Only allow access from localhost or specific IPs
$allowedIPs = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
    die('Access denied. This test suite can only be accessed from localhost.');
}

require_once '../shared/Database.php';
require_once '../shared/security.php';

$results = [];

// Test 1: Database Connection
try {
    $db = Database::getInstance();
    $results['database_connection'] = [
        'status' => 'PASS',
        'message' => 'Database connection successful'
    ];
} catch (Exception $e) {
    $results['database_connection'] = [
        'status' => 'FAIL',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ];
}

// Test 2: Prepared Statements (SQL Injection Prevention)
try {
    $maliciousInput = "'; DROP TABLE clients; --";
    $stmt = $db->query("SELECT * FROM clients WHERE username = ?", [$maliciousInput]);
    $result = $stmt->fetchAll();

    $results['sql_injection_prevention'] = [
        'status' => 'PASS',
        'message' => 'Prepared statements working correctly. Malicious input safely handled.'
    ];
} catch (Exception $e) {
    $results['sql_injection_prevention'] = [
        'status' => 'FAIL',
        'message' => 'Prepared statement test failed: ' . $e->getMessage()
    ];
}

// Test 3: CSRF Token Generation
try {
    session_start();
    $token1 = csrf_token();
    $token2 = csrf_token();

    if ($token1 === $token2 && strlen($token1) === 64) {
        $results['csrf_token_generation'] = [
            'status' => 'PASS',
            'message' => 'CSRF tokens generated correctly (64 chars, consistent)'
        ];
    } else {
        $results['csrf_token_generation'] = [
            'status' => 'FAIL',
            'message' => 'CSRF token generation issue'
        ];
    }
} catch (Exception $e) {
    $results['csrf_token_generation'] = [
        'status' => 'FAIL',
        'message' => 'CSRF test failed: ' . $e->getMessage()
    ];
}

// Test 4: Input Validation
$validationTests = [
    ['test@example.com', 'email', true],
    ['invalid-email', 'email', false],
    ['validuser123', 'username', true],
    ['invalid user!', 'username', false],
    ['example.com', 'domain', true],
    ['invalid..domain', 'domain', false],
];

$validationPassed = true;
foreach ($validationTests as $test) {
    $result = validate_input($test[0], $test[1]);
    if ($result !== $test[2]) {
        $validationPassed = false;
        break;
    }
}

$results['input_validation'] = [
    'status' => $validationPassed ? 'PASS' : 'FAIL',
    'message' => $validationPassed ? 'All validation tests passed' : 'Some validation tests failed'
];

// Test 5: Password Hashing
try {
    $password = 'TestPassword123!';
    $hash = hash_password($password);

    if (verify_password($password, $hash) && !verify_password('WrongPassword', $hash)) {
        $results['password_hashing'] = [
            'status' => 'PASS',
            'message' => 'Password hashing and verification working correctly'
        ];
    } else {
        $results['password_hashing'] = [
            'status' => 'FAIL',
            'message' => 'Password verification issue'
        ];
    }
} catch (Exception $e) {
    $results['password_hashing'] = [
        'status' => 'FAIL',
        'message' => 'Password hashing test failed: ' . $e->getMessage()
    ];
}

// Test 6: Sanitization
$sanitizationTests = [
    ['<script>alert("xss")</script>', 'html', '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'],
    ['test@example.com', 'email', 'test@example.com'],
    ['  test  ', 'string', 'test'],
];

$sanitizationPassed = true;
foreach ($sanitizationTests as $test) {
    $result = sanitize_input($test[0], $test[1]);
    if ($result !== $test[2]) {
        $sanitizationPassed = false;
        break;
    }
}

$results['input_sanitization'] = [
    'status' => $sanitizationPassed ? 'PASS' : 'FAIL',
    'message' => $sanitizationPassed ? 'All sanitization tests passed' : 'Some sanitization tests failed'
];

// Test 7: Rate Limiting
try {
    $_SESSION['rate_limit'] = []; // Reset

    $key = 'test_limit';
    $passed = true;

    // Should allow first 5 attempts
    for ($i = 0; $i < 5; $i++) {
        if (!check_rate_limit($key, 5, 300)) {
            $passed = false;
            break;
        }
    }

    // Should block 6th attempt
    if (check_rate_limit($key, 5, 300)) {
        $passed = false;
    }

    $results['rate_limiting'] = [
        'status' => $passed ? 'PASS' : 'FAIL',
        'message' => $passed ? 'Rate limiting working correctly' : 'Rate limiting not working as expected'
    ];
} catch (Exception $e) {
    $results['rate_limiting'] = [
        'status' => 'FAIL',
        'message' => 'Rate limiting test failed: ' . $e->getMessage()
    ];
}

// Test 8: Database Tables Existence
try {
    $requiredTables = ['security_logs', 'error_logs', 'login_attempts', 'active_sessions'];
    $missingTables = [];

    foreach ($requiredTables as $table) {
        $result = $db->fetchOne("SHOW TABLES LIKE ?", [$table]);
        if (!$result) {
            $missingTables[] = $table;
        }
    }

    if (empty($missingTables)) {
        $results['security_tables'] = [
            'status' => 'PASS',
            'message' => 'All security tables exist'
        ];
    } else {
        $results['security_tables'] = [
            'status' => 'FAIL',
            'message' => 'Missing tables: ' . implode(', ', $missingTables)
        ];
    }
} catch (Exception $e) {
    $results['security_tables'] = [
        'status' => 'FAIL',
        'message' => 'Table check failed: ' . $e->getMessage()
    ];
}

// Test 9: Security Logging
try {
    log_security_event('Test security event', 'info', ['test' => true]);

    // Check if logged to database
    $logEntry = $db->fetchOne(
        "SELECT * FROM security_logs WHERE event = ? ORDER BY created_at DESC LIMIT 1",
        ['Test security event']
    );

    if ($logEntry) {
        $results['security_logging'] = [
            'status' => 'PASS',
            'message' => 'Security logging working correctly'
        ];
    } else {
        $results['security_logging'] = [
            'status' => 'FAIL',
            'message' => 'Security log entry not found in database'
        ];
    }
} catch (Exception $e) {
    $results['security_logging'] = [
        'status' => 'FAIL',
        'message' => 'Security logging test failed: ' . $e->getMessage()
    ];
}

// Calculate overall status
$totalTests = count($results);
$passedTests = count(array_filter($results, function ($r) {
    return $r['status'] === 'PASS'; }));
$overallStatus = $passedTests === $totalTests ? 'ALL TESTS PASSED' : 'SOME TESTS FAILED';
$overallColor = $passedTests === $totalTests ? 'green' : 'red';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Test Suite - SHM Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-8">

    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-lg shadow-lg p-8 mb-6">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Security Test Suite</h1>
            <p class="text-gray-600">Testing all security implementations</p>
        </div>

        <div class="bg-<?= $overallColor ?>-50 border-2 border-<?= $overallColor ?>-500 rounded-lg p-6 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-<?= $overallColor ?>-900">
                        <?= $overallStatus ?>
                    </h2>
                    <p class="text-<?= $overallColor ?>-700 mt-1">
                        <?= $passedTests ?> /
                        <?= $totalTests ?> tests passed
                    </p>
                </div>
                <div class="text-6xl">
                    <?= $passedTests === $totalTests ? '✅' : '⚠️' ?>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <?php foreach ($results as $testName => $result): ?>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">
                                <?= ucwords(str_replace('_', ' ', $testName)) ?>
                            </h3>
                            <p class="text-gray-600">
                                <?= htmlspecialchars($result['message']) ?>
                            </p>
                        </div>
                        <div class="ml-4">
                            <?php if ($result['status'] === 'PASS'): ?>
                                <span class="px-4 py-2 bg-green-100 text-green-800 font-bold rounded-lg">
                                    ✓ PASS
                                </span>
                            <?php else: ?>
                                <span class="px-4 py-2 bg-red-100 text-red-800 font-bold rounded-lg">
                                    ✗ FAIL
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-8 bg-yellow-50 border border-yellow-200 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-yellow-900 mb-2">⚠️ Important Security Notice</h3>
            <p class="text-yellow-800">
                This test suite should be <strong>removed from production servers</strong> immediately after testing.
                It exposes internal security mechanisms and should only be used in development environments.
            </p>
        </div>

        <div class="mt-6 text-center">
            <a href="?" class="text-blue-600 hover:text-blue-800 font-medium">
                Refresh Tests
            </a>
        </div>
    </div>

</body>

</html>