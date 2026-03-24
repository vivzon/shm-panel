<?php
// ==========================================
// 1. API HANDLER (Runs only when JavaScript requests data)
// ==========================================
if (isset($_GET['ajax_stats'])) {
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
    exit; // Stop execution here for API requests
}

// ==========================================
// 2. MAIN PAGE LOGIC (Authentication & Static Info)
// ==========================================
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

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

// Function: Smart Domain Extraction
function getMainDomain($host)
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

$main_domain = getMainDomain($full_host);

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

include 'layout/header.php';
?>

<!-- ==========================================
     3. FRONTEND DISPLAY
   ========================================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php
// Fetch account summary for strip
$total_clients = 0;
$active_clients = 0;
$suspended_clients = 0;
try {
    $r = $pdo->query("SELECT COUNT(*) FROM clients");
    $total_clients = (int)$r->fetchColumn();
    $r2 = $pdo->query("SELECT COUNT(*) FROM clients WHERE status='active'");
    $active_clients = (int)$r2->fetchColumn();
    $r3 = $pdo->query("SELECT COUNT(*) FROM clients WHERE status='suspended'");
    $suspended_clients = (int)$r3->fetchColumn();
} catch (Exception $e) {}
?>
<h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; color: var(--text-primary); font-family: var(--font-heading);">
    System Overview</h2>

<!-- Account Summary Strip -->
<div class="pkg-strip" style="margin-bottom: 1.5rem;">
    <div class="pkg-strip-item">
        <i data-lucide="users" style="width: 1rem; height: 1rem; color: var(--primary);"></i>
        <span class="pkg-label">Total Accounts</span>
        <span class="pkg-value"><?= $total_clients ?></span>
    </div>
    <div class="pkg-strip-item">
        <i data-lucide="user-check" style="width: 1rem; height: 1rem; color: var(--accent-emerald);"></i>
        <span class="pkg-label">Active</span>
        <span class="pkg-value" style="color: var(--accent-emerald);"><?= $active_clients ?></span>
    </div>
    <?php if ($suspended_clients > 0): ?>
    <div class="pkg-strip-item">
        <i data-lucide="user-x" style="width: 1rem; height: 1rem; color: var(--accent-red);"></i>
        <span class="pkg-label">Suspended</span>
        <span class="pkg-value" style="color: var(--accent-red);"><?= $suspended_clients ?></span>
    </div>
    <?php endif; ?>
    <div class="pkg-strip-item">
        <i data-lucide="server" style="width: 1rem; height: 1rem; color: var(--primary);"></i>
        <span class="pkg-label">Server</span>
        <span class="pkg-value" style="font-family: monospace;"><?= htmlspecialchars($system_hostname) ?></span>
    </div>
    <div class="pkg-strip-item">
        <span class="badge badge-success">WHM Online</span>
    </div>
</div>

<!-- TOP METRICS GRID -->
<div
    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
    <!-- CPU -->
    <div class="stat-card animate-slide-right hover-glow"
        style="padding: 1.5rem; position: relative; overflow: hidden; animation-delay: 0.1s;">
        <div class="bg-icon"
            style="position: absolute; right: 0; top: 0; padding: 1.5rem; opacity: 0.06; transition: transform 0.5s;">
            <i data-lucide="cpu" style="width: 4rem; height: 4rem; color: var(--text-primary);"></i>
        </div>
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; border-radius: 0.5rem; background: rgba(59, 130, 246, 0.1); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.2);">
                <i data-lucide="cpu" style="width: 1.25rem; height: 1.25rem;"></i>
            </div>
            <span
                style="font-size: 0.6875rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em;">CPU
                Load</span>
        </div>
        <p class="metric-value">
            <span id="cpu-text">0</span>%
        </p>
    </div>

    <!-- RAM -->
    <div class="stat-card animate-slide-right hover-glow"
        style="padding: 1.5rem; position: relative; overflow: hidden; animation-delay: 0.2s;">
        <div class="bg-icon"
            style="position: absolute; right: 0; top: 0; padding: 1.5rem; opacity: 0.06; transition: transform 0.5s;">
            <i data-lucide="layers" style="width: 4rem; height: 4rem; color: var(--text-primary);"></i>
        </div>
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; border-radius: 0.5rem; background: rgba(168, 85, 247, 0.1); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.2);">
                <i data-lucide="layers" style="width: 1.25rem; height: 1.25rem;"></i>
            </div>
            <span
                style="font-size: 0.6875rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em;">RAM
                Usage</span>
        </div>
        <p class="metric-value">
            <span id="ram-text">0</span>%
        </p>
    </div>

    <!-- DISK -->
    <div class="stat-card animate-slide-right hover-glow"
        style="padding: 1.5rem; position: relative; overflow: hidden; animation-delay: 0.3s;">
        <div class="bg-icon"
            style="position: absolute; right: 0; top: 0; padding: 1.5rem; opacity: 0.06; transition: transform 0.5s;">
            <i data-lucide="hard-drive" style="width: 4rem; height: 4rem; color: var(--text-primary);"></i>
        </div>
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; border-radius: 0.5rem; background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2);">
                <i data-lucide="hard-drive" style="width: 1.25rem; height: 1.25rem;"></i>
            </div>
            <span
                style="font-size: 0.6875rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em;">Disk
                Space</span>
        </div>
        <p class="metric-value">
            <span id="disk-text">0</span>%
        </p>
    </div>

    <!-- UPTIME -->
    <div class="stat-card animate-slide-right hover-glow"
        style="padding: 1.5rem; position: relative; overflow: hidden; animation-delay: 0.4s;">
        <div class="bg-icon"
            style="position: absolute; right: 0; top: 0; padding: 1.5rem; opacity: 0.06; transition: transform 0.5s;">
            <i data-lucide="clock" style="width: 4rem; height: 4rem; color: var(--text-primary);"></i>
        </div>
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; border-radius: 0.5rem; background: rgba(249, 115, 22, 0.1); color: #fb923c; border: 1px solid rgba(249, 115, 22, 0.2);">
                <i data-lucide="clock" style="width: 1.25rem; height: 1.25rem;"></i>
            </div>
            <span
                style="font-size: 0.6875rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em;">Uptime</span>
        </div>
        <p class="metric-value">
            <span id="uptime-text" style="font-size: 1.25rem;">...</span>
        </p>
    </div>
</div>

<!-- GRAPH & NETWORK SECTION -->
<div
    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">

    <!-- Live Graph -->
    <div class="premium-glass" style="grid-column: span 2 / span 2; padding: 1.5rem; border-radius: 1.25rem;">
        <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--slate-900); margin-bottom: 1rem;">Live Resource
            History</h3>
        <div style="height: 300px; width: 100%;">
            <canvas id="resourceChart"></canvas>
        </div>
    </div>

    <!-- Network Configuration Card -->
    <div class="premium-glass"
        style="padding: 1.5rem; border-radius: 1.25rem; position: relative; overflow: hidden; display: flex; flex-direction: column;">
        <!-- Decoration -->
        <div
            style="position: absolute; right: -1.5rem; top: -1.5rem; width: 8rem; height: 8rem; background: rgba(59, 130, 246, 0.1); border-radius: 9999px; filter: blur(3xl);">
        </div>

        <div
            style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 1.5rem; position: relative; z-index: 10;">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 1rem; background: var(--slate-50); border-radius: 0.75rem; color: #60a5fa; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);">
                <i data-lucide="network" style="width: 2rem; height: 2rem;"></i>
            </div>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">
                    Network Config</h3>
                <div style="font-size: 0.875rem; color: var(--slate-700); font-family: monospace;"><?= $main_domain ?>
                </div>
            </div>
        </div>

        <div
            style="display: flex; flex-direction: column; gap: 1rem; font-size: 0.875rem; color: var(--slate-700); font-family: monospace; position: relative; z-index: 10;">
            <!-- IP -->
            <div
                style="display: flex; justify-content: space-between; items-align: center; border-bottom: 1px solid var(--slate-300); padding-bottom: 0.5rem;">
                <span style="display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700);">
                    <i data-lucide="server" style="width: 1rem; height: 1rem;"></i> IP
                </span>
                <span style="color: var(--slate-900);"><?= $server_ip ?></span>
            </div>
            <!-- NS -->
            <div
                style="display: flex; justify-content: space-between; items-align: center; border-bottom: 1px solid var(--slate-300); padding-bottom: 0.5rem;">
                <span style="display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700);">
                    <i data-lucide="globe" style="width: 1rem; height: 1rem;"></i> Name Server 1
                </span>
                <span
                    style="color: #2563eb; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 150px;"
                    title="<?= $ns_display ?>"><?= $ns_display ?></span>
            </div>
            <div
                style="display: flex; justify-content: space-between; items-align: center; border-bottom: 1px solid var(--slate-300); padding-bottom: 0.5rem;">
                <span style="display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700);">
                    <i data-lucide="globe" style="width: 1rem; height: 1rem;"></i> Name Server 2
                </span>
                <span
                    style="color: #2563eb; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 150px;"
                    title="<?= $ns2_display ?>"><?= $ns2_display ?></span>
            </div>
            <!-- MX -->
            <div
                style="display: flex; justify-content: space-between; items-align: center; border-bottom: 1px solid var(--slate-300); padding-bottom: 0.5rem;">
                <span style="display: flex; align-items: center; gap: 0.5rem; color: var(--slate-700);">
                    <i data-lucide="mail" style="width: 1rem; height: 1rem;"></i> MX
                </span>
                <span
                    style="color: #9333ea; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 150px;"
                    title="<?= $mx_display ?>"><?= $mx_display ?></span>
            </div>
        </div>
    </div>
</div>

<!-- SOFTWARE & HARDWARE SPECIFICATIONS -->
<div class="premium-glass" style="padding: 1.5rem; border-radius: 1.25rem;">
    <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--slate-900); margin-bottom: 1.5rem;">Server
        Specifications</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">

        <!-- OS Info -->
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: rgba(99, 102, 241, 0.1); border-radius: 0.5rem; color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.2);">
                <i data-lucide="monitor" style="width: 1.5rem; height: 1.5rem;"></i>
            </div>
            <div style="overflow: hidden;">
                <div
                    style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--slate-700); font-weight: 700;">
                    Operating System</div>
                <div style="color: var(--slate-900); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                    title="<?= $os_name ?>"><?= $os_name ?></div>
            </div>
        </div>

        <!-- PHP Version -->
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: rgba(236, 72, 153, 0.1); border-radius: 0.5rem; color: #f472b6; border: 1px solid rgba(236, 72, 153, 0.2);">
                <i data-lucide="code-2" style="width: 1.5rem; height: 1.5rem;"></i>
            </div>
            <div style="overflow: hidden;">
                <div
                    style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--slate-700); font-weight: 700;">
                    PHP Version</div>
                <div style="color: var(--slate-900); font-weight: 500;">v<?= $php_version ?></div>
            </div>
        </div>

        <!-- Web Server -->
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: rgba(20, 184, 166, 0.1); border-radius: 0.5rem; color: #2dd4bf; border: 1px solid rgba(20, 184, 166, 0.2);">
                <i data-lucide="globe-2" style="width: 1.5rem; height: 1.5rem;"></i>
            </div>
            <div style="overflow: hidden;">
                <div
                    style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--slate-700); font-weight: 700;">
                    Web Server</div>
                <div style="color: var(--slate-900); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                    title="<?= $web_server ?>"><?= $web_server_display ?>
                </div>
            </div>
        </div>

        <!-- Architecture -->
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div
                style="display: flex; align-items: center; justify-content: center; padding: 0.75rem; background: rgba(245, 158, 11, 0.1); border-radius: 0.5rem; color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.2);">
                <i data-lucide="cpu" style="width: 1.5rem; height: 1.5rem;"></i>
            </div>
            <div style="overflow: hidden;">
                <div
                    style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--slate-700); font-weight: 700;">
                    Architecture</div>
                <div style="color: var(--slate-900); font-weight: 500;"><?= $arch ?></div>
            </div>
        </div>

    </div>
</div>

<!-- ==========================================
     4. JAVASCRIPT (Live Updates)
   ========================================== -->
<script>
    document.addEventListener("DOMContentLoaded", function () {

        // --- Chart Setup ---
        const ctx = document.getElementById('resourceChart').getContext('2d');

        // Gradients
        let gradCpu = ctx.createLinearGradient(0, 0, 0, 400);
        gradCpu.addColorStop(0, 'rgba(59, 130, 246, 0.5)');
        gradCpu.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

        let gradRam = ctx.createLinearGradient(0, 0, 0, 400);
        gradRam.addColorStop(0, 'rgba(168, 85, 247, 0.5)');
        gradRam.addColorStop(1, 'rgba(168, 85, 247, 0.0)');

        const myChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: Array(20).fill(''),
                datasets: [
                    {
                        label: 'CPU %',
                        borderColor: '#60A5FA',
                        backgroundColor: gradCpu,
                        data: Array(20).fill(0),
                        tension: 0.3,
                        fill: true,
                        pointRadius: 0,
                        borderWidth: 3
                    },
                    {
                        label: 'RAM %',
                        borderColor: '#C084FC',
                        backgroundColor: gradRam,
                        data: Array(20).fill(0),
                        tension: 0.3,
                        fill: true,
                        pointRadius: 0,
                        borderWidth: 3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-muted') || '#94a3b8' } } },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: { color: 'rgba(150, 150, 150, 0.1)' },
                        ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary') || '#64748b' }
                    },
                    x: { display: false }
                }
            }
        });

        // --- Live Data Fetcher ---
        async function fetchStats() {
            try {
                // Call THIS file with query param
                const response = await fetch('?ajax_stats=1');
                const data = await response.json();

                // Update Cards
                if (document.getElementById('cpu-text')) document.getElementById('cpu-text').innerText = data.cpu;
                if (document.getElementById('ram-text')) document.getElementById('ram-text').innerText = data.ram;
                if (document.getElementById('disk-text')) document.getElementById('disk-text').innerText = data.disk;
                if (document.getElementById('uptime-text')) document.getElementById('uptime-text').innerText = data.uptime;

                // Update Chart Arrays (Remove first, Add last)
                myChart.data.datasets[0].data.shift();
                myChart.data.datasets[0].data.push(data.cpu);

                myChart.data.datasets[1].data.shift();
                myChart.data.datasets[1].data.push(data.ram);

                myChart.update();

            } catch (error) {
                console.log('Stats fetch error (stats.php not responding or JSON invalid)');
            }
        }

        // Start Loop
        fetchStats();
        setInterval(fetchStats, 2000); // 2 Seconds
    });
</script>

<?php include 'layout/footer.php'; ?>