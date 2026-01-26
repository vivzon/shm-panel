<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// ACTION HANDLER
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Action processed'];

    try {
        if ($action == 'save_account') {
            $id = $_POST['id'] ?? null;
            $did = $_POST['domain_id'] ?? null; // Domain ID from hidden input
            $u = trim($_POST['user']);
            $d = trim($_POST['dom']);
            $e = trim($_POST['email']);
            $pkg = (int) $_POST['package_id'];

            if ($id) {
                $pdo->prepare("UPDATE clients SET email=?, package_id=? WHERE id=?")->execute([$e, $pkg, $id]);

                // Only update domain if domain_id is provided and valid ownership
                if ($did && $d) {
                    // Check if domain name is actually changing to avoid redundant unique checks
                    $curr = $pdo->prepare("SELECT domain FROM domains WHERE id=?");
                    $curr->execute([$did]);
                    $currDom = $curr->fetchColumn();

                    if ($currDom !== $d) {
                        // Check for duplicates
                        $exists = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND id != ?");
                        $exists->execute([$d, $did]);
                        if ($exists->fetch())
                            throw new Exception("Domain $d already exists.");

                        $pdo->prepare("UPDATE domains SET domain=? WHERE id=? AND client_id=?")->execute([$d, $did, $id]);
                    }
                }

                if (!empty($_POST['pass'])) {
                    $hash = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE clients SET password=? WHERE id=?")->execute([$hash, $id]);
                }
            } else {
                // Check Username
                $chkUser = $pdo->prepare("SELECT id FROM clients WHERE username = ?");
                $chkUser->execute([$u]);
                if ($chkUser->fetch())
                    throw new Exception("Username '$u' already exists.");

                // Check Domain
                $chkDom = $pdo->prepare("SELECT id FROM domains WHERE domain = ?");
                $chkDom->execute([$d]);
                if ($chkDom->fetch())
                    throw new Exception("Domain '$d' already exists.");

                $pdo->beginTransaction();
                $hash = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO clients (username, email, password, package_id) VALUES (?,?,?,?)")->execute([$u, $e, $hash, $pkg]);
                $cid = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO domains (client_id, domain, document_root) VALUES (?,?,?)")->execute([$cid, $d, "/var/www/clients/$u/public_html"]);
                $pdo->prepare("INSERT INTO mail_domains (domain) VALUES (?)")->execute([$d]);

                // Auto-Generate DNS Records
                $server_ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1'; // Fallback if internal
                // If behind NAT, you might want to fetch public IP, but SERVER_ADDR is standard for now.

                $dom_id = $pdo->prepare("SELECT id FROM domains WHERE domain = ?");
                $dom_id->execute([$d]);
                $dom_id = $dom_id->fetchColumn();

                $host_parts = explode('.', $_SERVER['HTTP_HOST']);
                $base_domain = implode('.', array_slice($host_parts, -2));
                $mail_host = "mail." . $d; // Point MX to mail.clientdomain.com

                // A Records
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', '@', ?)")->execute([$dom_id, $server_ip]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', 'mail', ?)")->execute([$dom_id, $server_ip]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', 'ftp', ?)")->execute([$dom_id, $server_ip]);

                // CNAME
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'CNAME', 'www', '@')")->execute([$dom_id]);

                // MX
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'MX', '@', ?)")->execute([$dom_id, $mail_host]);

                // SPF & DMARC
                $spf = "v=spf1 a mx ip4:$server_ip -all";
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'TXT', '@', ?)")->execute([$dom_id, $spf]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'TXT', '_dmarc', 'v=DMARC1; p=none')")->execute([$dom_id]);

                // NS Records
                $ns1 = "ns1." . $base_domain;
                $ns2 = "ns2." . $base_domain;
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'NS', '@', ?)")->execute([$dom_id, $ns1]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'NS', '@', ?)")->execute([$dom_id, $ns2]);

                $pdo->commit();

                // Send response BEFORE shell command
                echo json_encode($res);
                if (ob_get_level() > 0)
                    ob_end_flush();
                flush();
                if (function_exists('fastcgi_finish_request'))
                    fastcgi_finish_request();

                cmd("create-account " . escapeshellarg($u) . " " . escapeshellarg($d) . " " . escapeshellarg($e) . " " . escapeshellarg($_POST['pass']));
                exit;
            }
        }

        if ($action == 'delete_account') {
            $id = (int) $_POST['id'];
            $user = $_POST['user'];
            $dom = $_POST['dom'];
            $pdo->prepare("DELETE FROM domains WHERE client_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM mail_domains WHERE domain = ?")->execute([$dom]);
            $pdo->prepare("DELETE FROM ftp_users WHERE homedir LIKE ?")->execute(["%/home/$user%"]);
            $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);

            echo json_encode($res);
            if (ob_get_level() > 0)
                ob_end_flush();
            flush();
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            cmd("delete-account " . escapeshellarg($user));
            exit;
        }

        if ($action == 'suspend_account') {
            $user = $_POST['user'];
            $suspend = $_POST['suspend'] === 'true';
            $status = $suspend ? 'suspended' : 'active';

            $pdo->prepare("UPDATE clients SET status = ? WHERE username = ?")->execute([$status, $user]);

            echo json_encode($res);
            if (ob_get_level() > 0)
                ob_end_flush();
            flush();
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();

            $cmd = $suspend ? 'suspend-account' : 'unsuspend-account';
            cmd("$cmd " . escapeshellarg($user));
            exit;
        }

        if ($action == 'login_as_client') {
            $_SESSION['client'] = $_POST['user'];
            $_SESSION['cid'] = $_POST['cid'];

            $host = $_SERVER['HTTP_HOST'];
            $target = str_replace('admin.', 'client.', $host);
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';

            echo json_encode(['status' => 'success', 'redirect' => $protocol . $target]);
            exit;
        }

        if ($action == 'reset_account') {
            $user = $_POST['user'];
            echo json_encode($res);
            if (ob_get_level() > 0)
                ob_end_flush();
            flush();
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            cmd("reset-account " . escapeshellarg($user));
            exit;
        }

        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Pagination
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)
    $page = 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Count Total
$total_clients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$total_pages = ceil($total_clients / $per_page);

// Fetch Clients
$clients = $pdo->query("SELECT c.*, d.id as domain_id, d.domain, p.name as pkg_name FROM clients c LEFT JOIN domains d ON c.id = d.client_id LEFT JOIN packages p ON c.package_id = p.id ORDER BY c.id DESC LIMIT $per_page OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
$packages = $pdo->query("SELECT * FROM packages")->fetchAll(PDO::FETCH_ASSOC);

include 'layout/header.php';
?>

<div class="flex justify-between items-center mb-8 gap-4">
    <div class="flex items-center gap-4">
        <h2 class="text-2xl font-bold text-white font-heading">Client Accounts <span
                class="text-slate-500 text-lg ml-2">(<?= $total_clients ?>)</span></h2>
        <div class="relative group">
            <i data-lucide="search"
                class="w-4 absolute left-3 top-3 text-slate-500 group-focus-within:text-blue-400 transition"></i>
            <input id="acc-search" onkeyup="filterTable('acc-table', this.value)" placeholder="Search clients..."
                class="bg-slate-900/50 border border-slate-700/50 rounded-xl pl-10 pr-4 py-2.5 text-sm w-64 focus:w-80 transition-all outline-none focus:border-blue-500 focus:bg-slate-900 text-white placeholder-slate-600">
        </div>
    </div>
    <button onclick="openAccModal()"
        class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2.5 rounded-xl font-bold shadow-lg shadow-blue-900/20 text-sm flex items-center gap-2 transition border border-blue-500/50">
        <i data-lucide="plus-circle" class="w-4"></i> Create Account
    </button>
</div>
<div class="glass-panel rounded-2xl overflow-hidden">
    <table id="acc-table" class="w-full text-left border-collapse">
        <thead
            class="bg-slate-900/50 text-slate-400 text-[10px] font-bold uppercase tracking-widest border-b border-slate-800">
            <tr>
                <th class="p-5">Client / Domain</th>
                <th class="p-5">Plan</th>
                <th class="p-5">Status</th>
                <th class="p-5 text-right">Management</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/50">
            <?php foreach ($clients as $c): ?>
                <tr class="hover:bg-slate-800/30 transition-colors group">
                    <td class="p-5">
                        <div class="font-bold text-white text-sm">
                            <?= $c['username'] ?>
                        </div>
                        <a href="http://<?= $c['domain'] ?>" target="_blank"
                            class="text-xs text-blue-400 hover:underline flex items-center gap-1">
                            <?= $c['domain'] ?> <i data-lucide="external-link"
                                class="w-3 opacity-0 group-hover:opacity-100 transition"></i>
                        </a>
                    </td>
                    <td class="p-5">
                        <span
                            class="bg-slate-800 border border-slate-700 px-3 py-1 rounded-full text-[10px] font-bold text-slate-300">
                            <?= $c['pkg_name'] ?>
                        </span>
                    </td>
                    <td class="p-5">
                        <?php if ($c['status'] == 'suspended'): ?>
                            <span
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-red-500/10 text-red-500 border border-red-500/20">
                                <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Suspended
                            </span>
                        <?php else: ?>
                            <span
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="p-5 text-right flex justify-end gap-2">
                        <button onclick="loginAs('<?= $c['username'] ?>', <?= $c['id'] ?>)"
                            class="p-2 hover:bg-blue-500/10 text-slate-400 hover:text-blue-400 rounded-lg transition"
                            title="Access Account">
                            <i data-lucide="key" class="w-4"></i>
                        </button>
                        <?php if ($c['status'] == 'active'): ?>
                            <button onclick="toggleSuspend('<?= $c['username'] ?>', true)"
                                class="p-2 hover:bg-orange-500/10 text-slate-400 hover:text-orange-400 rounded-lg transition"
                                title="Suspend">
                                <i data-lucide="pause-circle" class="w-4"></i>
                            </button>
                        <?php else: ?>
                            <button onclick="toggleSuspend('<?= $c['username'] ?>', false)"
                                class="p-2 hover:bg-emerald-500/10 text-slate-400 hover:text-emerald-400 rounded-lg transition"
                                title="Unsuspend">
                                <i data-lucide="play-circle" class="w-4"></i>
                            </button>
                        <?php endif; ?>
                        <button onclick='openAccModal(<?= json_encode($c) ?>)'
                            class="p-2 hover:bg-blue-500/10 text-slate-400 hover:text-blue-400 rounded-lg transition border border-transparent hover:border-blue-500/20"
                            title="Edit">
                            <i data-lucide="edit-3" class="w-4"></i>
                        </button>
                        <button onclick="resetAccount('<?= $c['username'] ?>')"
                            class="p-2 hover:bg-red-500/10 text-slate-400 hover:text-red-400 rounded-lg transition"
                            title="Reset Account">
                            <i data-lucide="rotate-ccw" class="w-4"></i>
                        </button>
                        <button onclick="delAcc(<?= $c['id'] ?>, '<?= $c['username'] ?>', '<?= $c['domain'] ?>')"
                            class="p-2 hover:bg-red-500/10 text-slate-400 hover:text-red-400 rounded-lg transition border border-transparent hover:border-red-500/20"
                            title="Delete">
                            <i data-lucide="trash-2" class="w-4"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($total_pages > 1): ?>
    <div class="flex justify-between items-center mt-6">
        <div class="text-xs text-slate-500 font-bold">
            Page <?= $page ?> of <?= $total_pages ?>
        </div>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>"
                    class="bg-slate-800 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-slate-700 transition">Previous</a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?>"
                    class="bg-slate-800 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-slate-700 transition">Next</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>



<!-- ACCOUNT MODAL -->
<div id="modal-acc"
    class="fixed inset-0 bg-slate-950/80 backdrop-blur-md hidden flex items-center justify-center z-50 p-6">
    <form id="form-acc" onsubmit="handleGeneric(event, 'save_account')"
        class="glass-panel p-10 rounded-3xl w-full max-w-lg relative">
        <h3 id="acc-title" class="text-2xl font-bold mb-8 text-white font-heading">Provision Account</h3>
        <input type="hidden" name="id" id="acc-id">
        <input type="hidden" name="domain_id" id="acc-did">

        <div class="space-y-5">
            <div class="space-y-2">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Client ID</label>
                <input name="user" id="acc-user" placeholder="Username" required
                    class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
            </div>

            <div class="space-y-2">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Primary
                    Domain</label>
                <input name="dom" id="acc-dom" placeholder="example.com" required
                    class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
            </div>

            <div class="space-y-2">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Contact</label>
                <input name="email" id="acc-email" placeholder="client@email.com" required
                    class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
            </div>

            <div class="space-y-2">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Security</label>
                <input name="pass" type="password" placeholder="Password (Leave empty to keep)"
                    class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 outline-none focus:border-blue-500 text-white placeholder:text-slate-600 focus:bg-slate-900 transition">
            </div>

            <div class="space-y-2">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-widest pl-2">Plan</label>
                <div class="relative">
                    <select name="package_id" id="acc-pkg"
                        class="w-full bg-slate-900/50 p-4 rounded-xl border border-slate-700 text-slate-300 outline-none focus:border-blue-500 focus:bg-slate-900 transition appearance-none cursor-pointer">
                        <?php foreach ($packages as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= $p['name'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i data-lucide="chevron-down"
                        class="w-4 h-4 absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"></i>
                </div>
            </div>

            <div class="flex gap-4 pt-4">
                <button type="button" onclick="closeModal('modal-acc')"
                    class="flex-1 p-4 rounded-xl font-bold text-slate-400 hover:bg-slate-800 transition">Cancel</button>
                <button type="submit"
                    class="flex-1 bg-blue-600 hover:bg-blue-500 p-4 rounded-xl font-bold text-white shadow-lg shadow-blue-600/20 transition">
                    Confirm
                </button>
            </div>
        </div>
    </form>
</div>

<?php include 'layout/footer.php'; ?>

<script>
    function openAccModal(data = null) {
        const f = document.getElementById('form-acc'); f.reset();
        const title = document.getElementById('acc-title');

        if (data) {
            document.getElementById('acc-id').value = data.id;
            document.getElementById('acc-did').value = data.domain_id;
            document.getElementById('acc-user').value = data.username;
            document.getElementById('acc-user').readOnly = true;
            document.getElementById('acc-dom').value = data.domain;
            document.getElementById('acc-email').value = data.email;
            document.getElementById('acc-pkg').value = data.package_id;
            title.innerText = "Edit Account";
        } else {
            document.getElementById('acc-id').value = "";
            document.getElementById('acc-user').readOnly = false;
            title.innerText = "Provision Account";
        }
        document.getElementById('modal-acc').classList.remove('hidden');
    }

    async function toggleSuspend(user, suspend) {
        if (!confirm('Are you sure you want to ' + (suspend ? 'suspend' : 'unsuspend') + ' this account?')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'suspend_account');
        fd.append('user', user);
        fd.append('suspend', suspend);
        try {
            const res = await fetch('', { method: 'POST', body: fd });
            const d = await res.json();
            if (d.status === 'success') {
                showToast('success', 'Account Status Updated');
                setTimeout(() => location.reload(), 1000);
            } else showToast('error', d.msg);
        } catch (e) { showToast('error', 'Network Error'); }
    }

    async function resetAccount(user) {
        if (!confirm('DANGER: Reset entire account for ' + user + '? This mimicks a fresh install.')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'reset_account');
        fd.append('user', user);
        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => showToast('success', 'Account Reset Initiated'));
    }

    async function delAcc(id, user, dom) {
        if (!confirm('PERMANENTLY DELETE ' + dom + '? Data cannot be recovered.')) return;
        const fd = new FormData();
        fd.append('ajax_action', 'delete_account');
        fd.append('id', id);
        fd.append('user', user);
        fd.append('dom', dom);
        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'success') {
                    showToast('success', 'Account Deleted');
                    setTimeout(() => location.reload(), 1000);
                }
            });
    }

    function loginAs(user, cid) {
        const fd = new FormData();
        fd.append('ajax_action', 'login_as_client');
        fd.append('user', user);
        fd.append('cid', cid);
        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'success') location.href = d.redirect;
            });
    }

    function filterTable(tableId, query) {
        const lower = query.toLowerCase();
        const rows = document.querySelectorAll(`#${tableId} tbody tr`);
        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(lower) ? '' : 'none';
        });
    }
</script>