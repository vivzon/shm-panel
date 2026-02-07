<?php
require_once __DIR__ . '/../../shared/config.php';

class AdminDashboardController
{

    public function index()
    {
        // --- A. DETECT STATIC SERVER INFO ---
        // 1. Operating System
        $os_name = php_uname('s') . ' ' . php_uname('r');
        if (file_exists('/etc/os-release')) {
            $os_info = parse_ini_file('/etc/os-release');
            if (isset($os_info['PRETTY_NAME'])) {
                $os_name = $os_info['PRETTY_NAME'];
            }
        }

        // 2. Server Software (Web Server)
        $web_server = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
        // Clean up string (e.g. "Apache/2.4.52 (Ubuntu)" -> "Apache/2.4.52")
        $web_server_parts = explode(' ', $web_server);
        $web_server_display = $web_server_parts[0];

        // 3. PHP Version
        $php_version = phpversion();

        // 4. Architecture
        $arch = php_uname('m'); // x86_64, aarch64, etc.

        // 5. Hostname
        $system_hostname = gethostname();


        // --- B. NETWORK & DNS LOGIC ---
        $full_host = $_SERVER['SERVER_NAME'];
        $server_ip = $_SERVER['SERVER_ADDR'] ?? gethostbyname($system_hostname);
        $main_domain = $this->getMainDomain($full_host);

        // Fetch NS Records (Real Lookup)
        $ns_display = "ns1." . $main_domain;
        $ns2_display = "ns2." . $main_domain;
        $dns_ns = @dns_get_record($main_domain, DNS_NS);
        //if ($dns_ns && !empty($dns_ns)) $ns_display = $dns_ns[0]['target'];

        // Fetch MX Records (Real Lookup & Sort)
        $mx_display = "mail." . $main_domain;
        $dns_mx = @dns_get_record($main_domain, DNS_MX);
        if ($dns_mx && !empty($dns_mx)) {
            usort($dns_mx, function ($a, $b) {
                return $a['pri'] <=> $b['pri'];
            });
            $mx_display = $dns_mx[0]['target'];
        }

        return [
            'os_name' => $os_name,
            'web_server' => $web_server,
            'web_server_display' => $web_server_display,
            'php_version' => $php_version,
            'arch' => $arch,
            'server_ip' => $server_ip,
            'main_domain' => $main_domain,
            'ns_display' => $ns_display,
            'ns2_display' => $ns2_display,
            'mx_display' => $mx_display
        ];
    }

    // Function: Smart Domain Extraction
    private function getMainDomain($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP))
            return $host;
        $parts = explode('.', $host);
        if (count($parts) <= 2)
            return $host;
        $lastPart = $parts[count($parts) - 1];
        $secondLast = $parts[count($parts) - 2];
        if (strlen($lastPart) == 2 && strlen($secondLast) <= 3) {
            return implode('.', array_slice($parts, -3));
        }
        return implode('.', array_slice($parts, -2));
    }
}
