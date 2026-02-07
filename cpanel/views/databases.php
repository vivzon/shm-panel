<?php include 'layout/header.php'; ?>

<style>
    .btn-loading {
        pointer-events: none;
        opacity: 0.6;
    }
</style>

<div class="grid grid-cols-1 md:grid-cols-3 gap-8">
    <!-- LEFT SIDE: FORMS -->
    <div class="space-y-8">
        <!-- CREATE DATABASE -->
        <div>
            <h3 class="font-bold mb-4 text-white">Create Database</h3>
            <form onsubmit="handleSubmit(event, 'add_db')" class="glass-card p-6 space-y-4">
                <div class="flex items-center bg-slate-900/50 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-4 py-4 bg-slate-800 text-slate-400 font-mono text-sm border-r border-slate-700">
                        <?= htmlspecialchars($data['username']) ?>_</div>
                    <input name="db_name" required placeholder="dbname"
                        class="w-full bg-transparent p-4 outline-none text-white placeholder-slate-600">
                </div>
                <select name="domain_id"
                    class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-slate-300">
                    <option value="">Global (No Domain)</option>
                    <?php foreach ($data['domains'] as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['domain']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                    class="w-full bg-blue-600 text-white p-4 rounded-xl font-bold hover:bg-blue-500 transition">Create
                    Database</button>
            </form>
        </div>

        <!-- CREATE USER -->
        <div>
            <h3 class="font-bold mb-4 text-white">Create User</h3>
            <form onsubmit="handleSubmit(event, 'add_db_user')" class="glass-card p-6 space-y-4">
                <div class="flex items-center bg-slate-900/50 rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-4 py-4 bg-slate-800 text-slate-400 font-mono text-sm border-r border-slate-700">
                        <?= htmlspecialchars($data['username']) ?>_</div>
                    <input name="db_user" required placeholder="dbuser"
                        class="w-full bg-transparent p-4 outline-none text-white placeholder-slate-600">
                </div>
                <input name="db_pass" type="password" required placeholder="Password"
                    class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-white">
                <select name="target_db"
                    class="w-full bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-slate-300">
                    <?php foreach ($data['my_dbs'] as $db): ?>
                        <option value="<?= $db['db_name'] ?>"><?= $db['db_name'] ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                    class="w-full bg-slate-800 text-white p-4 rounded-xl font-bold border border-slate-700">Create
                    User</button>
            </form>
        </div>
    </div>

    <!-- RIGHT SIDE: TABLES -->
    <div class="md:col-span-2 space-y-8">
        <!-- DATABASE LIST -->
        <div>
            <h3 class="font-bold mb-4 text-white">Your Databases</h3>
            <div class="glass-card overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                        <tr>
                            <th class="p-6">Name</th>
                            <th class="p-6 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['my_dbs'] as $db): ?>
                            <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                                <td class="p-6">
                                    <div class="font-bold text-slate-200"><?= htmlspecialchars($db['db_name']) ?></div>
                                    <div class="text-xs text-blue-400">
                                        <?= $db['domain'] ? htmlspecialchars($db['domain']) : 'Global' ?></div>
                                </td>
                                <td class="p-6 text-right">
                                    <a href="http://phpmyadmin.<?= $data['base_domain'] ?>" target="_blank"
                                        class="text-xs font-bold text-blue-400 mr-4 uppercase">Login</a>
                                    <button
                                        onclick="handleDeleteAction('delete_db', 'db_name', '<?= $db['db_name'] ?>', this)"
                                        class="text-red-400 p-2"><i data-lucide="trash-2" class="w-4"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($data['my_dbs'])): ?>
                            <tr><td colspan="2" class="p-6 text-center text-slate-500">No databases found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
             <!-- Pagination for DBs could be added here if needed -->
        </div>

        <!-- USER LIST -->
        <div>
            <h3 class="font-bold mb-4 text-white">Database Users</h3>
            <div class="glass-card overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                        <tr>
                            <th class="p-6">Username</th>
                            <th class="p-6 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['db_users'] as $u): ?>
                            <tr class="border-t border-slate-700/50 hover:bg-slate-800/30 transition">
                                <td class="p-6 font-bold text-slate-300"><?= htmlspecialchars($u['db_user']) ?></td>
                                <td class="p-6 text-right flex justify-end gap-2">
                                    <button onclick="handleResetPass('<?= $u['db_user'] ?>', this)"
                                        class="text-orange-400 p-2" title="Reset Password"><i data-lucide="key"
                                            class="w-4"></i></button>
                                    <button
                                        onclick="handleDeleteAction('delete_db_user', 'db_user', '<?= $u['db_user'] ?>', this)"
                                        class="text-red-400 p-2" title="Delete User"><i data-lucide="trash-2"
                                            class="w-4"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                         <?php if (empty($data['db_users'])): ?>
                            <tr><td colspan="2" class="p-6 text-center text-slate-500">No users found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    const API_URL = 'api/databases.php';

    async function handleSubmit(e, action) {
        e.preventDefault();
        const btn = e.target.querySelector('button');
        const originalText = btn.innerText;

        btn.disabled = true;
        btn.classList.add('btn-loading');
        btn.innerText = 'Processing...';

        const fd = new FormData(e.target);
        fd.append('action', action);
        fd.append('token', '<?= $_SESSION['csrf_token'] ?>');

        try {
            const res = await fetch(API_URL, { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', res.msg);
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', res.msg);
                btn.disabled = false;
                btn.classList.remove('btn-loading');
                btn.innerText = originalText;
            }
        } catch (e) { showToast('error', 'Server error occurred'); }
    }

    async function handleDeleteAction(action, key, val, btn) {
        if (!confirm(`Are you sure you want to delete ${val}?`)) return;

        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="w-4 animate-spin"></i>';
        if (window.lucide) lucide.createIcons();

        const fd = new FormData();
        fd.append('action', action);
        fd.append(key, val);
        fd.append('token', '<?= $_SESSION['csrf_token'] ?>');

        try {
            const res = await fetch(API_URL, { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Deleted successfully');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast('error', res.msg);
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                if (window.lucide) lucide.createIcons();
            }
        } catch (e) { showToast('error', 'Connection error'); }
    }

    async function handleResetPass(user, btn) {
        const newPass = prompt(`Enter new password for ${user}:`);
        if (!newPass) return;

        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i data-lucide="loader-2" class="w-4 animate-spin"></i>';
        if (window.lucide) lucide.createIcons();

        const fd = new FormData();
        fd.append('action', 'reset_db_pass');
        fd.append('db_user', user);
        fd.append('new_pass', newPass);
        fd.append('token', '<?= $_SESSION['csrf_token'] ?>');

        try {
            const res = await fetch(API_URL, { method: 'POST', body: fd }).then(r => r.json());
            showToast(res.status, res.msg);
        } catch (e) { showToast('error', 'Reset failed'); }

        btn.innerHTML = originalHtml;
        if (window.lucide) lucide.createIcons();
    }
</script>

<?php include 'layout/footer.php'; ?>
