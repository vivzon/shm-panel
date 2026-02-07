<?php
// Expects $data array from Controller
extract($data);
include __DIR__ . '/../layout/header.php';
?>

<!-- ==========================================
     3. FRONTEND DISPLAY
   ========================================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<h2 class="text-2xl font-bold mb-6 text-white font-heading">System Overview</h2>

<!-- TOP METRICS GRID -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
    <!-- CPU -->
    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden group">
        <div class="absolute right-0 top-0 p-6 opacity-10 group-hover:scale-110 transition duration-500">
            <i data-lucide="cpu" class="w-16 h-16 text-white"></i>
        </div>
        <div class="flex items-center gap-3 mb-4">
            <div class="p-2 rounded-lg bg-blue-500/10 text-blue-400 border border-blue-500/20">
                <i data-lucide="cpu" class="w-5 h-5"></i>
            </div>
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">CPU Load</span>
        </div>
        <p class="text-3xl font-bold text-white tracking-tight">
            <span id="cpu-text">0</span>%
        </p>
    </div>

    <!-- RAM -->
    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden group">
        <div class="absolute right-0 top-0 p-6 opacity-10 group-hover:scale-110 transition duration-500">
            <i data-lucide="layers" class="w-16 h-16 text-white"></i>
        </div>
        <div class="flex items-center gap-3 mb-4">
            <div class="p-2 rounded-lg bg-purple-500/10 text-purple-400 border border-purple-500/20">
                <i data-lucide="layers" class="w-5 h-5"></i>
            </div>
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">RAM Usage</span>
        </div>
        <p class="text-3xl font-bold text-white tracking-tight">
            <span id="ram-text">0</span>%
        </p>
    </div>

    <!-- DISK -->
    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden group">
        <div class="absolute right-0 top-0 p-6 opacity-10 group-hover:scale-110 transition duration-500">
            <i data-lucide="hard-drive" class="w-16 h-16 text-white"></i>
        </div>
        <div class="flex items-center gap-3 mb-4">
            <div class="p-2 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <i data-lucide="hard-drive" class="w-5 h-5"></i>
            </div>
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">Disk Space</span>
        </div>
        <p class="text-3xl font-bold text-white tracking-tight">
            <span id="disk-text">0</span>%
        </p>
    </div>

    <!-- UPTIME -->
    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden group">
        <div class="absolute right-0 top-0 p-6 opacity-10 group-hover:scale-110 transition duration-500">
            <i data-lucide="clock" class="w-16 h-16 text-white"></i>
        </div>
        <div class="flex items-center gap-3 mb-4">
            <div class="p-2 rounded-lg bg-orange-500/10 text-orange-400 border border-orange-500/20">
                <i data-lucide="clock" class="w-5 h-5"></i>
            </div>
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">Uptime</span>
        </div>
        <p class="text-3xl font-bold text-white tracking-tight">
            <span id="uptime-text" class="text-xl">...</span>
        </p>
    </div>
</div>

<!-- GRAPH & NETWORK SECTION -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

    <!-- Live Graph -->
    <div class="lg:col-span-2 glass-panel p-6 rounded-2xl">
        <h3 class="text-lg font-bold text-white mb-4">Live Resource History</h3>
        <div style="height: 300px; width: 100%;">
            <canvas id="resourceChart"></canvas>
        </div>
    </div>

    <!-- Network Configuration Card -->
    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden flex flex-col">
        <!-- Decoration -->
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-blue-500/10 rounded-full blur-3xl"></div>

        <div class="flex items-center gap-6 mb-6 relative z-10">
            <div class="p-4 bg-slate-800 rounded-xl text-blue-400 shadow-lg shadow-black/20">
                <i data-lucide="network" class="w-8 h-8"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-white mb-1">Network Config</h3>
                <div class="text-sm text-slate-400 font-mono">
                    <?= $main_domain ?>
                </div>
            </div>
        </div>

        <div class="space-y-4 text-sm text-slate-300 font-mono relative z-10">
            <!-- IP -->
            <div class="flex justify-between items-center border-b border-slate-700/50 pb-2">
                <span class="flex items-center gap-2 text-slate-500">
                    <i data-lucide="server" class="w-4 h-4"></i> IP
                </span>
                <span class="text-white">
                    <?= $server_ip ?>
                </span>
            </div>
            <!-- NS -->
            <div class="flex justify-between items-center border-b border-slate-700/50 pb-2">
                <span class="flex items-center gap-2 text-slate-500">
                    <i data-lucide="globe" class="w-4 h-4"></i> Name Server 1
                </span>
                <span class="text-blue-300 truncate max-w-[150px]" title="<?= $ns_display ?>">
                    <?= $ns_display ?>
                </span>
            </div>
            <div class="flex justify-between items-center border-b border-slate-700/50 pb-2">
                <span class="flex items-center gap-2 text-slate-500">
                    <i data-lucide="globe" class="w-4 h-4"></i> Name Server 2
                </span>
                <span class="text-blue-300 truncate max-w-[150px]" title="<?= $ns2_display ?>">
                    <?= $ns2_display ?>
                </span>
            </div>
            <!-- MX -->
            <div class="flex justify-between items-center border-b border-slate-700/50 pb-2">
                <span class="flex items-center gap-2 text-slate-500">
                    <i data-lucide="mail" class="w-4 h-4"></i> MX
                </span>
                <span class="text-purple-300 truncate max-w-[150px]" title="<?= $mx_display ?>">
                    <?= $mx_display ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- SOFTWARE & HARDWARE SPECIFICATIONS -->
<div class="glass-panel p-6 rounded-2xl">
    <h3 class="text-lg font-bold text-white mb-6">Server Specifications</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

        <!-- OS Info -->
        <div class="flex items-center gap-4">
            <div class="p-3 bg-indigo-500/10 rounded-lg text-indigo-400 border border-indigo-500/20">
                <i data-lucide="monitor" class="w-6 h-6"></i>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Operating System</div>
                <div class="text-white font-medium truncate" title="<?= $os_name ?>">
                    <?= $os_name ?>
                </div>
            </div>
        </div>

        <!-- PHP Version -->
        <div class="flex items-center gap-4">
            <div class="p-3 bg-pink-500/10 rounded-lg text-pink-400 border border-pink-500/20">
                <i data-lucide="code-2" class="w-6 h-6"></i>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">PHP Version</div>
                <div class="text-white font-medium">v
                    <?= $php_version ?>
                </div>
            </div>
        </div>

        <!-- Web Server -->
        <div class="flex items-center gap-4">
            <div class="p-3 bg-teal-500/10 rounded-lg text-teal-400 border border-teal-500/20">
                <i data-lucide="globe-2" class="w-6 h-6"></i>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Web Server</div>
                <div class="text-white font-medium truncate" title="<?= $web_server ?>">
                    <?= $web_server_display ?>
                </div>
            </div>
        </div>

        <!-- Architecture -->
        <div class="flex items-center gap-4">
            <div class="p-3 bg-amber-500/10 rounded-lg text-amber-400 border border-amber-500/20">
                <i data-lucide="cpu" class="w-6 h-6"></i>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Architecture</div>
                <div class="text-white font-medium">
                    <?= $arch ?>
                </div>
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
                        tension: 0.4,
                        fill: true,
                        pointRadius: 0,
                        borderWidth: 2
                    },
                    {
                        label: 'RAM %',
                        borderColor: '#C084FC',
                        backgroundColor: gradRam,
                        data: Array(20).fill(0),
                        tension: 0.4,
                        fill: true,
                        pointRadius: 0,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { labels: { color: '#94a3b8' } } },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#64748b' }
                    },
                    x: { display: false }
                }
            }
        });

        // --- Live Data Fetcher ---
        async function fetchStats() {
            try {
                // Point to new API
                const response = await fetch('api/stats.php');
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
                console.log('Stats fetch error: ', error);
            }
        }

        // Start Loop
        fetchStats();
        setInterval(fetchStats, 2000); // 2 Seconds
    });
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>