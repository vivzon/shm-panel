<?php
require_once 'c:/Users/Vivek/OneDrive/Desktop/research-n-development/shm-panel/shared/config.php';

echo "PHP_OS: " . PHP_OS . "\n";
echo "PHP_OS (3): " . strtoupper(substr(PHP_OS, 0, 3)) . "\n";

try {
    $out = cmd("service-status nginx");
    echo "cmd output: " . $out . "\n";
} catch (Exception $e) {
    echo "cmd error: " . $e->getMessage() . "\n";
}
