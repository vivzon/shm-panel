<?php
include __DIR__ . '/../layout/header.php';
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-white font-heading">Service Manager</h2>
    <p class="text-slate-400">Monitor and control core system services.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">

    <!-- NGINX -->
    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden group">
        <div class="absolute right-0 top-0 p-6 opacity-10 group-hover:scale-110 transition duration-500">
            <i data-lucide="globe" class="w-16 h-16 text-white"></i>
        </div>
        <div class="flex justify-between items-start mb-4 relative z-10">
            <div class="p-3 bg-emerald-500/10 text-emerald-400 rounded-xl border border-emerald-500/20">
                <i data-lucide="globe" class="w-6 h-6"></i>
            </div>
            <span id="status-nginx"
                class="status-badge bg-slate-800 text-slate-500 border border-slate-700">Checking...</span>
        </div>
        <h3 class="text-xl font-bold text-white mb-2 relative z-10">Nginx Web Server</h3>
        <p class="text-sm text-slate-400 mb-6 relative z-10">Handles all incoming HTTP connections.</p>

        <button onclick="restartService('nginx')"
            class="w-full py-3 px-4 bg-slate-800 hover:bg-emerald-600 border border-slate-700 hover:border-emerald-500 text-white rounded-xl font-bold transition flex items-center justify-center gap-2 group relative z-10">
            <i data-lucide="refresh-cw" class="w-4 h-4 group-hover:rotate-180 transition duration-500"></i>
            Restart Nginx
        </button>
    </div>

    <!-- PHP-FPM -->
    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden group">
        <div class="absolute right-0 top-0 p-6 opacity-10 group-hover:scale-110 transition duration-500">
            <i data-lucide="code-2" class="w-16 h-16 text-white"></i>
        </div>
        <div class="flex justify-between items-start mb-4 relative z-10">
            <div class="p-3 bg-purple-500/10 text-purple-400 rounded-xl border border-purple-500/20">
                <i data-lucide="code-2" class="w-6 h-6"></i>
            </div>
            <span id="status-php"
                class="status-badge bg-slate-800 text-slate-500 border border-slate-700">Checking...</span>
        </div>
        <h3 class="text-xl font-bold text-white mb-2 relative z-10">PHP-FPM 8.2</h3>
        <p class="text-sm text-slate-400 mb-6 relative z-10">Process manager for PHP scripts.</p>

        <button onclick="restartService('php')"
            class="w-full py-3 px-4 bg-slate-800 hover:bg-purple-600 border border-slate-700 hover:border-purple-500 text-white rounded-xl font-bold transition flex items-center justify-center gap-2 group relative z-10">
            <i data-lucide="refresh-cw" class="w-4 h-4 group-hover:rotate-180 transition duration-500"></i>
            Restart PHP
        </button>
    </div>

    <!-- MARIADB -->
    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden group">
        <div class="absolute right-0 top-0 p-6 opacity-10 group-hover:scale-110 transition duration-500">
            <i data-lucide="database" class="w-16 h-16 text-white"></i>
        </div>
        <div class="flex justify-between items-start mb-4 relative z-10">
            <div class="p-3 bg-blue-500/10 text-blue-400 rounded-xl border border-blue-500/20">
                <i data-lucide="database" class="w-6 h-6"></i>
            </div>
            <span id="status-mysql"
                class="status-badge bg-slate-800 text-slate-500 border border-slate-700">Checking...</span>
        </div>
        <h3 class="text-xl font-bold text-white mb-2 relative z-10">MariaDB Database</h3>
        <p class="text-sm text-slate-400 mb-6 relative z-10">The main relational database server.</p>

        <button onclick="restartService('mysql')"
            class="w-full py-3 px-4 bg-slate-800 hover:bg-blue-600 border border-slate-700 hover:border-blue-500 text-white rounded-xl font-bold transition flex items-center justify-center gap-2 group relative z-10">
            <i data-lucide="refresh-cw" class="w-4 h-4 group-hover:rotate-180 transition duration-500"></i>
            Restart DB
        </button>
    </div>

</div>

<script>
    async function checkStatus(service) {
        const el = document.getElementById(`status-${service}`);
        try {
            const formData = new FormData();
            formData.append('action', 'status');
            formData.append('service', service);

            const res = await fetch('api/services.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.status === 'active') {
                el.innerHTML = '<span class="w-2 h-2 rounded-full bg-green-500 mr-2 inline-block"></span>Running';
                el.className = 'status-badge bg-green-500/10 text-green-400 border border-green-500/20 px-3 py-1 rounded-full text-xs font-bold';
            } else {
                el.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500 mr-2 inline-block"></span>Stopped';
                el.className = 'status-badge bg-red-500/10 text-red-400 border border-red-500/20 px-3 py-1 rounded-full text-xs font-bold';
            }
        } catch (e) {
            el.innerText = 'Error';
            el.className = 'status-badge bg-red-500/10 text-red-400 border border-red-500/20 px-3 py-1 rounded-full text-xs font-bold';
        }
    }

    async function restartService(service) {
        if (!confirm(`Are you sure you want to restart ${service}?`)) return;

        const btn = event.currentTarget;
        const orgText = btn.innerHTML;
        btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Restarting...';
        btn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('action', 'restart');
            formData.append('service', service);

            const res = await fetch('api/services.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.status === 'success') {
                // Wait a moment for service to come back
                setTimeout(() => {
                    checkStatus(service);
                    alert(data.msg);
                }, 2000);
            } else {
                alert('Error: ' + data.msg);
            }
        } catch (e) {
            alert('System Error');
        } finally {
            btn.innerHTML = orgText;
            btn.disabled = false;
            lucide.createIcons();
        }
    }

    // Init
    document.addEventListener("DOMContentLoaded", () => {
        checkStatus('nginx');
        checkStatus('php');
        checkStatus('mysql');
    });
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>