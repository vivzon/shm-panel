<?php
include __DIR__ . '/../layout/header.php';
?>

<div class="mb-6 flex justify-between items-center">
    <div>
        <h2 class="text-2xl font-bold text-white font-heading">Backup Manager</h2>
        <p class="text-slate-400">Manage system and client backups.</p>
    </div>
    <button onclick="createBackup()"
        class="bg-blue-600 hover:bg-blue-500 text-white px-6 py-2 rounded-xl font-bold transition flex items-center gap-2">
        <i data-lucide="plus-circle" class="w-5 h-5"></i>
        Create Backup
    </button>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden">
        <div class="flex justify-between items-start mb-4">
            <div class="p-3 bg-blue-500/10 text-blue-400 rounded-xl border border-blue-500/20">
                <i data-lucide="archive" class="w-6 h-6"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-white mb-1" id="total-backups">-</h3>
        <p class="text-sm text-slate-400">Total Backups</p>
    </div>

    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden">
        <div class="flex justify-between items-start mb-4">
            <div class="p-3 bg-purple-500/10 text-purple-400 rounded-xl border border-purple-500/20">
                <i data-lucide="hard-drive" class="w-6 h-6"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-white mb-1" id="total-size">-</h3>
        <p class="text-sm text-slate-400">Total Size</p>
    </div>

    <div class="glass-panel p-6 rounded-2xl relative overflow-hidden">
        <div class="flex justify-between items-start mb-4">
            <div class="p-3 bg-emerald-500/10 text-emerald-400 rounded-xl border border-emerald-500/20">
                <i data-lucide="calendar-check" class="w-6 h-6"></i>
            </div>
        </div>
        <div class="text-sm text-slate-400">Last Backup</div>
        <h3 class="text-lg font-bold text-white mt-1" id="last-backup">-</h3>
    </div>
</div>

<!-- Backups Table -->
<div class="glass-panel rounded-2xl overflow-hidden">
    <div class="p-6 border-b border-slate-700/50">
        <h3 class="text-lg font-bold text-white">Existing Backups</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="text-xs font-bold text-slate-400 uppercase border-b border-slate-700/50 bg-slate-800/20">
                    <th class="px-6 py-4">Filename</th>
                    <th class="px-6 py-4">Type</th>
                    <th class="px-6 py-4">Size</th>
                    <th class="px-6 py-4">Date</th>
                    <th class="px-6 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="backup-list" class="divide-y divide-slate-700/50 text-sm text-slate-300">
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-slate-500">Loading backups...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    async function loadBackups() {
        try {
            const formData = new FormData();
            formData.append('action', 'list');

            const res = await fetch('api/backups.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.status === 'success') {
                const list = document.getElementById('backup-list');

                // Update Stats
                document.getElementById('total-backups').innerText = data.backups.length;
                document.getElementById('total-size').innerText = formatBytes(data.total_size);
                document.getElementById('last-backup').innerText = data.backups.length > 0 ? data.backups[0].date : 'Never';

                if (data.backups.length === 0) {
                    list.innerHTML = '<tr><td colspan="5" class="px-6 py-8 text-center text-slate-500">No backups found.</td></tr>';
                    return;
                }

                list.innerHTML = data.backups.map(b => `
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="px-6 py-4 font-mono text-xs text-blue-400">
                            <i data-lucide="file-archive" class="w-4 h-4 inline mr-2 opacity-70"></i>${b.filename}
                        </td>
                        <td class="px-6 py-4"><span class="px-2 py-1 rounded bg-slate-800 border border-slate-700 text-xs">${b.type}</span></td>
                        <td class="px-6 py-4 text-slate-400">${b.size}</td>
                        <td class="px-6 py-4 text-slate-400">${b.date}</td>
                        <td class="px-6 py-4 text-right space-x-2">
                            <button onclick="downloadBackup('${b.filename}')" class="text-slate-400 hover:text-white transition" title="Download"><i data-lucide="download" class="w-4 h-4"></i></button>
                            <button onclick="restoreBackup('${b.filename}')" class="text-slate-400 hover:text-emerald-400 transition" title="Restore"><i data-lucide="rotate-ccw" class="w-4 h-4"></i></button>
                            <button onclick="deleteBackup('${b.filename}')" class="text-slate-400 hover:text-red-400 transition" title="Delete"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        </td>
                    </tr>
                `).join('');

                lucide.createIcons();
            }
        } catch (e) {
            console.error(e);
        }
    }

    async function createBackup() {
        if (!confirm('Start a new backup process? This might take a while.')) return;

        try {
            const formData = new FormData();
            formData.append('action', 'create');

            // Show creating state
            const btn = document.querySelector('button[onclick="createBackup()"]');
            const orgText = btn.innerHTML;
            btn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i> Creating...';
            btn.disabled = true;
            lucide.createIcons();

            const res = await fetch('api/backups.php', { method: 'POST', body: formData });
            const data = await res.json();

            alert(data.msg);
            loadBackups();

            btn.innerHTML = orgText;
            btn.disabled = false;
            lucide.createIcons();
        } catch (e) {
            alert('Failed to start backup.');
        }
    }

    async function deleteBackup(filename) {
        if (!confirm('Are you sure you want to delete this backup?')) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('filename', filename);

        const res = await fetch('api/backups.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.status === 'success') loadBackups();
        else alert(data.msg);
    }

    function formatBytes(bytes, decimals = 2) {
        if (!+bytes) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
    }

    document.addEventListener("DOMContentLoaded", loadBackups);
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>