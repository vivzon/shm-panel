<?php
require_once __DIR__ . '/../../shared/config.php';

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json');
error_reporting(0); // Prevent PHP warnings from breaking JSON

// Function: Get CPU Usage
function getCpuUsage()
{
    if (is_readable('/proc/stat')) {
        $stat1 = file('/proc/stat');
        sleep(1);
        $stat2 = file('/proc/stat');
        $info1 = explode(" ", preg_replace("!cpu +!", "", $stat1[0]));
        $info2 = explode(" ", preg_replace("!cpu +!", "", $stat2[0]));
        $dif = [];
        $dif['user'] = $info2[0] - $info1[0];
        $dif['nice'] = $info2[1] - $info1[1];
        $dif['sys'] = $info2[2] - $info1[2];
        $dif['idle'] = $info2[3] - $info1[3];
        $total = array_sum($dif);
        $cpu = array_sum($dif) - $dif['idle'];
        return $total > 0 ? round(($cpu / $total) * 100, 1) : 0;
    }
    // Fallback for non-Linux
    $load = sys_getloadavg();
    return isset($load[0]) ? round($load[0] * 100 / 4, 1) : 0;
}

// Function: Get RAM Usage
function getRamUsage()
{
    if (is_readable('/proc/meminfo')) {
        $data = explode("\n", file_get_contents("/proc/meminfo"));
        $memInfo = [];
        foreach ($data as $line) {
            $parts = explode(":", $line);
            if (count($parts) == 2)
                $memInfo[$parts[0]] = trim($parts[1]);
        }
        $total = intval(preg_replace('/\D/', '', $memInfo['MemTotal'] ?? '0'));
        $avail = intval(preg_replace('/\D/', '', $memInfo['MemAvailable'] ?? '0'));
        if ($total == 0)
            return 0;
        return round((($total - $avail) / $total) * 100, 1);
    }
    return 0;
}

// Function: Get Uptime
function getUptime()
{
    if (is_readable('/proc/uptime')) {
        $str = file_get_contents('/proc/uptime');
        $num = floatval($str);
        $days = floor($num / 86400);
        $hours = floor(($num % 86400) / 3600);
        return "$days d, $hours h";
    }
    return "N/A";
}

echo json_encode([
    'cpu' => getCpuUsage(),
    'ram' => getRamUsage(),
    'disk' => round((1 - (disk_free_space(".") / disk_total_space("."))) * 100, 1),
    'uptime' => getUptime()
]);
