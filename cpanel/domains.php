<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        $limits = $pdo->query("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = $cid")->fetch();

        if ($action == 'add_domain') {
            $dom = strtolower(trim($_POST['domain']));
            if (!preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/', $dom))
                throw new Exception("Invalid Domain Name Format");

            $curr = $pdo->query("SELECT COUNT(*) FROM domains WHERE client_id = $cid")->fetchColumn();
            if ($curr >= $limits['max_domains'])
                throw new Exception("Domain limit reached ({$limits['max_domains']})");

            $exists = $pdo->prepare("SELECT id FROM domains WHERE domain = ?");
            $exists->execute([$dom]);
            if ($exists->fetch())
                throw new Exception("Domain already exists on server");

            $pdo->prepare("INSERT INTO domains (client_id, domain, document_root) VALUES (?, ?, ?)")->execute([$cid, $dom, "/var/www/clients/$username/domains/$dom/public_html"]);
            $dom_id = $pdo->lastInsertId();

            // Auto DNS
            $server_ip = $_SERVER['SERVER_ADDR'];
            $host_parts = explode('.', $_SERVER['HTTP_HOST']);
            $base_domain = implode('.', array_slice($host_parts, -2));
            $mail_host = "mail." . $base_domain;

            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', '@', ?)")->execute([$dom_id, $server_ip]);
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'CNAME', 'www', '@')")->execute([$dom_id]);
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', 'mail', ?)")->execute([$dom_id, $server_ip]);
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'MX', '@', ?)")->execute([$dom_id, $mail_host]);

            $spf = "v=spf1 a mx ip4:$server_ip -all";
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'TXT', '@', ?)")->execute([$dom_id, $spf]);
            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'TXT', '_dmarc', 'v=DMARC1; p=none')")->execute([$dom_id]);

            cmd("shm-manage add-domain " . escapeshellarg($username) . " " . escapeshellarg($dom));
            cmd("dns-tool sync $dom_id");
            sendResponse($res);
            exit;
        }

        if ($action == 'delete_domain') {
            $dom_id = (int) $_POST['domain_id'];
            $d = $pdo->prepare("SELECT domain FROM domains WHERE id=? AND client_id=?");
            $d->execute([$dom_id, $cid]);
            $domain_name = $d->fetchColumn();
            if (!$domain_name)
                throw new Exception("Invalid Domain");

            $pdo->prepare("DELETE FROM dns_records WHERE domain_id=?")->execute([$dom_id]);
            $pdo->prepare("DELETE FROM php_config WHERE domain_id=?")->execute([$dom_id]);
            $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$dom_id]);

            cmd("shm-manage delete-domain " . escapeshellarg($username) . " " . escapeshellarg($domain_name));
            sendResponse($res);
            exit;
        }

        if ($action == 'update_domain_config') {
            $pdo->prepare("UPDATE domains SET php_version = ?, ssl_active = ? WHERE id = ? AND client_id = ?")->execute([$_POST['php_version'], isset($_POST['ssl']) ? 1 : 0, $_POST['domain_id'], $cid]);
            $pdo->prepare("INSERT INTO php_config (domain_id, memory_limit) VALUES (?, ?) ON DUPLICATE KEY UPDATE memory_limit=VALUES(memory_limit)")->execute([$_POST['domain_id'], $_POST['mem']]);

            // Sync Vhost (Triggers SSL Install if needed)
            cmd("vhost-tool sync " . (int) $_POST['domain_id']);
            sendResponse($res);
            exit;
        }

        if ($action == 'add_dns') {
            $dom_id = $_POST['domain_id'];
            $check = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $check->execute([$dom_id, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, ?, ?, ?)")->execute([$dom_id, $_POST['type'], $_POST['host'], $_POST['value']]);

            cmd("dns-tool sync " . (int) $dom_id);
            sendResponse($res);
            exit;
        }

        if ($action == 'delete_dns') {
            $did = (int) $_POST['id'];
            $dom_id = (int) $_POST['domain_id'];
            $check = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $check->execute([$dom_id, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $pdo->prepare("DELETE FROM dns_records WHERE id = ? AND domain_id = ?")->execute([$did, $dom_id]);

            cmd("dns-tool sync " . $dom_id);
            sendResponse($res);
            exit; // Added explicit exit for consistency, though sendResponse exits.
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Data
$domains = $pdo->query("SELECT * FROM domains WHERE client_id = $cid")->fetchAll();

// Base Domain
$server_host = $_SERVER['HTTP_HOST'];
$parts = explode('.', $server_host);
$base_domain = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $server_host;

include 'layout/header.php';
?>

<div class="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
    <div class="flex items-center gap-4">
        <h2 class="text-2xl font-bold text-white">Domain Management</h2>
        <div class="relative group">
            <i data-lucide="search"
                class="w-4 absolute left-3 top-3 text-slate-500 group-focus-within:text-blue-400 transition"></i>
            <input id="dom-search" onkeyup="filterDomains(this.value)" placeholder="Search domains..."
                class="bg-slate-900/50 border border-slate-700 p-3 pl-10 rounded-xl text-sm w-48 focus:w-64 outline-none shadow-sm focus:border-blue-500 text-white placeholder-slate-500 transition-all">
        </div>
    </div>
    <div class="flex gap-4">
        <!-- Add Domain -->
        <form onsubmit="handleGeneric(event, 'add_domain')" class="flex gap-2" id="form-add-domain">
            <input name="domain" required placeholder="example.com"
                class="bg-slate-900/50 border border-slate-700 p-3 rounded-xl text-sm outline-none shadow-sm focus:border-blue-500 text-white placeholder-slate-500 w-48 transition">
            <button
                class="bg-slate-800 text-white px-4 py-3 rounded-xl font-bold text-xs uppercase shadow-xl hover:bg-slate-700 border border-slate-700 transition whitespace-nowrap">
                + Domain</button>
        </form>

        <!-- Subdomain -->
        <form onsubmit="handleAddSubdomain(event)" class="flex gap-2 hidden" id="form-add-subdomain">
            <input name="sub" required placeholder="sub (e.g. blog)"
                class="bg-slate-900/50 border border-slate-700 p-3 rounded-xl text-sm outline-none shadow-sm focus:border-blue-500 text-white placeholder-slate-500 w-32 transition text-right">
            <span class="self-center font-bold text-slate-500">.</span>
            <select name="parent_id"
                class="bg-slate-900/50 border border-slate-700 p-3 rounded-xl text-sm outline-none shadow-sm focus:border-blue-500 text-white w-40 transition">
                <?php foreach ($domains as $d): ?>
                    <option value="<?= $d['domain'] ?>">
                        <?= $d['domain'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button
                class="bg-blue-600 text-white px-4 py-3 rounded-xl font-bold text-xs uppercase shadow-xl hover:bg-blue-500 border border-blue-500 transition whitespace-nowrap">
                + Sub</button>
        </form>

        <button onclick="toggleDomainMode()"
            class="p-3 bg-slate-800 text-slate-400 rounded-xl hover:text-white transition"
            title="Toggle Subdomain Mode">
            <i data-lucide="shuffle" class="w-4 h-4"></i>
        </button>
    </div>
</div>
<div id="domain-list">

    <?php foreach ($domains as $d): ?>
        <div class="glass-card p-10 mb-8 shadow-sm group">
            <div class="flex justify-between items-center mb-10">
                <div>
                    <h3 class="text-2xl font-black text-white">
                        <?= $d['domain'] ?>
                    </h3>
                    <p class="text-xs text-slate-500 font-mono mt-1">Root: /home/
                        <?= $username ?>/public_html
                    </p>
                </div>
                <div class="flex gap-2">
                    <a href="files.php?domain_id=<?= $d['id'] ?>&path=/" target="_blank"
                        class="bg-blue-500/10 text-blue-400 -4 py-2 rounded-xl text-xs font-bold hover:bg-blue-600 hover:text-white transition flex items-center gap-2 border border-blue-500/20 px-4"><i
                            data-lucide="folder-open" class="w-4 h-4"></i> Manage Files</a>
                    <button onclick="deleteAction('delete_domain', 'domain_id', <?= $d['id'] ?>)"
                        class="bg-red-500/10 text-red-400 px-4 py-2 rounded-xl text-xs font-bold hover:bg-red-600 hover:text-white transition border border-red-500/20">Delete</button>
                </div>
                <form onsubmit="handleGeneric(event, 'update_domain_config')"
                    class="flex items-center gap-4 bg-slate-900/50 p-4 rounded-3xl border border-slate-700/50">
                    <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                    <select name="php_version"
                        class="bg-slate-800 border border-slate-700 p-2 rounded-xl text-xs font-bold text-white">
                        <option value="8.1" <?= $d['php_version'] == '8.1' ? 'selected' : '' ?>>PHP 8.1</option>
                        <option value="8.2" <?= $d['php_version'] == '8.2' ? 'selected' : '' ?>>PHP 8.2</option>
                        <option value="8.3" <?= $d['php_version'] == '8.3' ? 'selected' : '' ?>>PHP 8.3</option>
                    </select>
                    <select name="mem"
                        class="bg-slate-800 border border-slate-700 p-2 rounded-xl text-xs font-bold text-white">
                        <option>128M</option>
                        <option>256M</option>
                        <option>512M</option>
                    </select>
                    <div class="flex items-center gap-2 px-2 border-l border-slate-700">
                        <input type="checkbox" name="ssl" <?= $d['ssl_active'] ? 'checked' : '' ?>
                            class="w-4 h-4 text-emerald-500 accent-emerald-500">
                        <span class="text-[10px] font-bold uppercase text-emerald-400">SSL</span>
                    </div>
                    <button class="bg-blue-600 text-white p-2 rounded-lg hover:bg-blue-500 transition"><i data-lucide="save"
                            class="w-4"></i></button>
                </form>
            </div>
            <div class="border-t border-slate-700/50 pt-8">
                <h4 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-6">DNS Zone Management
                </h4>
                <form onsubmit="handleGeneric(event, 'add_dns')" class="grid grid-cols-4 gap-3 mb-4">
                    <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                    <input name="host" placeholder="Host (e.g. @)"
                        class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-sm text-white placeholder-slate-600 outline-none focus:border-blue-500 transition"
                        required>
                    <select name="type"
                        class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-sm font-bold text-slate-300 outline-none">
                        <option>A</option>
                        <option>CNAME</option>
                        <option>MX</option>
                        <option>TXT</option>
                    </select>
                    <input name="value" placeholder="Value (IP or Domain)"
                        class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl text-sm text-white placeholder-slate-600 outline-none focus:border-blue-500 transition"
                        required>
                    <button
                        class="bg-slate-800 text-white rounded-xl font-bold text-xs uppercase shadow-xl hover:bg-slate-700 border border-slate-700 transition">Add
                        Record</button>
                </form>

                <table class="w-full mt-6 text-left">
                    <thead class="bg-slate-900/50 text-[10px] font-bold uppercase text-slate-400">
                        <tr>
                            <th class="p-3">Host</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Value</th>
                            <th class="p-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/50">
                        <?php
                        $recs = $pdo->prepare("SELECT * FROM dns_records WHERE domain_id = ?");
                        $recs->execute([$d['id']]);
                        while ($r = $recs->fetch()): ?>
                            <tr class="text-sm hover:bg-slate-800/30 transition">
                                <td class="p-3 font-bold text-slate-300">
                                    <?= $r['host'] ?>
                                </td>
                                <td class="p-3"><span
                                        class="bg-slate-800 border border-slate-700 px-2 py-1 rounded text-xs font-bold text-slate-400">
                                        <?= $r['type'] ?>
                                    </span>
                                </td>
                                <td class="p-3 font-mono text-slate-500 text-xs">
                                    <?= $r['value'] ?>
                                </td>
                                <td class="p-3 text-right">
                                    <button
                                        onclick="deleteAction('delete_dns', 'id', <?= $r['id'] ?>, 'domain_id', <?= $d['id'] ?>)"
                                        class="text-red-400 hover:text-red-500"><i data-lucide="trash-2"
                                            class="w-4"></i></button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php include 'layout/footer.php'; ?>

<script>
    function toggleDomainMode() {
        const domForm = document.getElementById('form-add-domain');
        const subForm = document.getElementById('form-add-subdomain');

        if (domForm.classList.contains('hidden')) {
            domForm.classList.remove('hidden');
            subForm.classList.add('hidden');
        } else {
            domForm.classList.add('hidden');
            subForm.classList.remove('hidden');
        }
    }

    async function handleAddSubdomain(e) {
        e.preventDefault();
        const form = e.target;
        const sub = form.sub.value.trim().toLowerCase();
        const parent = form.parent_id.value;

        if (!sub || !parent) {
            showToast('error', 'Validation Error', 'Please fill in all fields.');
            return;
        }

        const fqdn = `${sub}.${parent}`;
        const fd = new FormData();
        fd.append('ajax_action', 'add_domain');
        fd.append('domain', fqdn);

        const btn = form.querySelector('button');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="animate-pulse">...</span>`;

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Subdomain Created', `Subdomain ${fqdn} created successfully.`);
                setTimeout(() => forceReload(), 1000);
            } else {
                showToast('error', 'Operation Failed', res.msg);
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        } catch (err) {
            showToast('error', 'System Error', 'Failed to create subdomain.');
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        }
    }

    async function deleteAction(action, ...args) {
        if (!confirm("Permanent Action: Are you sure?")) return;
        const fd = new FormData();
        fd.append('ajax_action', action);
        // Correctly handling args here. args is array.
        // The original called it with specific keys. My generic handler in previous files assumed exact keys.
        // Here I will use manually passed keys for the deleteAction since it takes varied args.
        // Ah, the previous file used `...args` and looped `i+=2`. Let's copy that logic.
        for (let i = 0; i < args.length; i += 2) fd.append(args[i], args[i + 1]);

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Deleted', 'Item deleted successfully.');
                setTimeout(() => forceReload(), 1000);
            } else {
                showToast('error', 'Delete Failed', res.msg || 'Could not delete item.');
            }
        } catch (e) {
            showToast('error', 'Error', 'System error during deletion.');
        }
    }

    function filterDomains(query) {
        const lower = query.toLowerCase();
        const items = document.querySelectorAll('#domain-list > .glass-card');
        items.forEach(item => {
            const text = item.innerText.toLowerCase();
            item.style.display = text.includes(lower) ? '' : 'none';
        });
    }
</script>