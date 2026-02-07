<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

/**
 * HELPER: Fetch Clients (One row per client)
 */
function getClientsData($pdo, $search = '', $page = 1, $limit = 10)
{
    $offset = ($page - 1) * $limit;
    $params = [];
    $where = " WHERE 1=1 ";

    if (!empty($search)) {
        $where .= " AND (c.username LIKE ? OR d.domain LIKE ? OR c.email LIKE ?) ";
        $params = ["%$search%", "%$search%", "%$search%"];
    }

    // Count Total Unique Clients
    $stCount = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM clients c LEFT JOIN domains d ON c.id = d.client_id $where");
    $stCount->execute($params);
    $total = $stCount->fetchColumn();

    // Fetch Unique Rows
    $sql = "SELECT c.*, d.domain, d.id as domain_id, p.name as pkg_name 
            FROM clients c 
            LEFT JOIN domains d ON d.id = (SELECT id FROM domains WHERE client_id = c.id ORDER BY id ASC LIMIT 1)
            LEFT JOIN packages p ON c.package_id = p.id 
            $where 
            GROUP BY c.id 
            ORDER BY c.id DESC LIMIT $limit OFFSET $offset";

    $stData = $pdo->prepare($sql);
    $stData->execute($params);
    $rows = $stData->fetchAll(PDO::FETCH_ASSOC);

    return ['rows' => $rows, 'total' => (int) $total, 'pages' => ceil($total / $limit)];
}

/**
 * ACTION HANDLER
 */
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $res = ['status' => 'success', 'msg' => 'Action processed'];

    try {
        if ($action == 'search_clients') {
            echo json_encode(getClientsData($pdo, $_POST['query'] ?? '', (int) ($_POST['page'] ?? 1)));
            exit;
        }

        if ($action == 'save_account') {
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $u = trim($_POST['user']);
            $d = trim($_POST['dom']);
            $e = trim($_POST['email']);
            $pkg = (int) $_POST['package_id'];

            if ($id) {
                // --- SURGICAL UPDATE ---
                $oldSt = $pdo->prepare("SELECT c.*, d.domain, d.id as domain_id FROM clients c LEFT JOIN domains d ON d.id = (SELECT id FROM domains WHERE client_id = c.id LIMIT 1) WHERE c.id = ?");
                $oldSt->execute([$id]);
                $curr = $oldSt->fetch(PDO::FETCH_ASSOC);

                // Update email/package if changed
                if ($curr['email'] !== $e || (int) $curr['package_id'] !== $pkg) {
                    $pdo->prepare("UPDATE clients SET email=?, package_id=? WHERE id=?")->execute([$e, $pkg, $id]);
                }
                // Update domain name if changed
                if ($curr['domain'] !== $d) {
                    $pdo->prepare("UPDATE domains SET domain=? WHERE id=?")->execute([$d, $curr['domain_id']]);
                    $pdo->prepare("UPDATE mail_domains SET domain=? WHERE domain=?")->execute([$d, $curr['domain']]);
                }
                // Update password if provided
                if (!empty($_POST['pass'])) {
                    $hash = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE clients SET password=? WHERE id=?")->execute([$hash, $id]);
                }
            } else {
                // --- CREATE ACCOUNT ---
                $pdo->beginTransaction();
                $hash = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO clients (username, email, password, package_id, status) VALUES (?,?,?,?, 'active')")->execute([$u, $e, $hash, $pkg]);
                $cid = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO domains (client_id, domain, document_root) VALUES (?,?,?)")->execute([$cid, $d, "/var/www/clients/$u/public_html"]);
                $dom_id = $pdo->lastInsertId();

                // Mail domain
                $pdo->prepare("INSERT INTO mail_domains (domain) VALUES (?)")->execute([$d]);

                // DNS Records
                $ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', '@', ?)")->execute([$dom_id, $ip]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'MX', '@', ?)")->execute([$dom_id, "mail.$d"]);
                $pdo->commit();

                echo json_encode($res);
                if (function_exists('fastcgi_finish_request'))
                    fastcgi_finish_request();
                cmd("create-account " . escapeshellarg($u) . " " . escapeshellarg($d) . " " . escapeshellarg($e) . " " . escapeshellarg($_POST['pass']));
                exit;
            }
        }

        if ($action == 'delete_account') {
            $id = (int) $_POST['id'];
            $user = $_POST['user'];

            // 1. Fetch all domains for this client to clean up records
            $stmt = $pdo->prepare("SELECT id, domain FROM domains WHERE client_id = ?");
            $stmt->execute([$id]);
            $doms = $stmt->fetchAll();

            $pdo->beginTransaction();
            foreach ($doms as $dm) {
                $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ?")->execute([$dm['id']]);
                $pdo->prepare("DELETE FROM mail_domains WHERE domain = ?")->execute([$dm['domain']]);
            }
            $pdo->prepare("DELETE FROM ftp_users WHERE homedir LIKE ?")->execute(["%/$user%"]);
            $pdo->prepare("DELETE FROM domains WHERE client_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);
            $pdo->commit();

            echo json_encode($res);
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            cmd("delete-account " . escapeshellarg($user));
            exit;
        }

        if ($action == 'suspend_account') {
            $user = $_POST['user'];
            $sus = $_POST['suspend'] === 'true';
            $pdo->prepare("UPDATE clients SET status = ? WHERE username = ?")->execute([$sus ? 'suspended' : 'active', $user]);
            echo json_encode($res);
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            $c = $sus ? 'suspend-account' : 'unsuspend-account';
            cmd("$c " . escapeshellarg($user));
            exit;
        }

        if ($action == 'reset_account') {
            $user = $_POST['user'];
            echo json_encode($res);
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            cmd("reset-account " . escapeshellarg($user));
            exit;
        }

        if ($action == 'login_as_client') {
            $_SESSION['client'] = $_POST['user'];
            $_SESSION['cid'] = $_POST['cid'];
            $host = str_replace('admin.', 'client.', $_SERVER['HTTP_HOST']);
            echo json_encode(['status' => 'success', 'redirect' => (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $host]);
            exit;
        }

        echo json_encode($res);
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

$packages = $pdo->query("SELECT * FROM packages")->fetchAll(PDO::FETCH_ASSOC);
include 'layout/header.php';
?>

<!-- HEADER & REAL-TIME SEARCH -->
<div class="flex justify-between items-center mb-8 gap-4">
    <div class="flex items-center gap-4">
        <h2 class="text-2xl font-bold text-white font-heading">Clients <span id="client-count"
                class="text-slate-500 text-lg ml-2"></span></h2>
        <div class="relative">
            <i data-lucide="search" class="w-4 absolute left-3 top-3 text-slate-500"></i>
            <input id="live-search" onkeyup="debounceSearch()" placeholder="Search username, email or domain..."
                class="bg-slate-900/50 border border-slate-700/50 rounded-xl pl-10 pr-4 py-2.5 text-sm w-80 outline-none focus:border-blue-500 text-white transition-all">
        </div>
    </div>
    <button onclick="openAccModal()"
        class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 transition shadow-lg shadow-blue-900/20">
        <i data-lucide="plus-circle" class="w-4"></i> Create Account
    </button>
</div>

<!-- DATA TABLE -->
<div class="glass-panel rounded-2xl overflow-hidden">
    <table class="w-full text-left border-collapse">
        <thead
            class="bg-slate-900/50 text-slate-400 text-[10px] font-bold uppercase tracking-widest border-b border-slate-800">
            <tr>
                <th class="p-5">Client / Primary Domain</th>
                <th class="p-5">Plan</th>
                <th class="p-5">Status</th>
                <th class="p-5 text-right">Actions</th>
            </tr>
        </thead>
        <tbody id="client-table-body" class="divide-y divide-slate-800/50"></tbody>
    </table>
</div>

<div id="pagination-container" class="flex justify-between items-center mt-6"></div>

<!-- CRUD MODAL -->
<div id="modal-acc"
    class="fixed inset-0 bg-slate-950/80 backdrop-blur-md hidden flex items-center justify-center z-50 p-6">
    <form id="form-acc" onsubmit="handleGeneric(event, 'save_account')"
        class="glass-panel p-10 rounded-3xl w-full max-w-lg">
        <h3 id="acc-title" class="text-2xl font-bold mb-8 text-white">Client Details</h3>
        <input type="hidden" name="id" id="acc-id">
        <div class="space-y-4">
            <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase pl-1">Username</label>
                <input name="user" id="acc-user" required
                    class="w-full bg-slate-900/50 p-3 rounded-xl border border-slate-700 text-white outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase pl-1">Domain</label>
                <input name="dom" id="acc-dom" required placeholder="domain.com"
                    class="w-full bg-slate-900/50 p-3 rounded-xl border border-slate-700 text-white outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase pl-1">Email</label>
                <input name="email" id="acc-email" type="email" required
                    class="w-full bg-slate-900/50 p-3 rounded-xl border border-slate-700 text-white outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase pl-1">Password (Leave blank to keep
                    current)</label>
                <input name="pass" type="password"
                    class="w-full bg-slate-900/50 p-3 rounded-xl border border-slate-700 text-white outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase pl-1">Plan</label>
                <select name="package_id" id="acc-pkg"
                    class="w-full bg-slate-900/50 p-3 rounded-xl border border-slate-700 text-white outline-none">
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= $p['name'] ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-4 pt-4">
                <button type="button" onclick="closeModal('modal-acc')"
                    class="flex-1 p-3 text-slate-400 font-bold hover:bg-slate-800 rounded-xl transition">Cancel</button>
                <button type="submit"
                    class="flex-1 bg-blue-600 text-white p-3 rounded-xl font-bold transition hover:bg-blue-500">Save</button>
            </div>
        </div>
    </form>
</div>

<script>
    let searchTimer;
    let currentPage = 1;
    let loadedClients = [];

    function debounceSearch() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { currentPage = 1; loadClients(); }, 300);
    }

    async function loadClients() {
        const query = document.getElementById('live-search').value;
        const fd = new FormData();
        fd.append('ajax_action', 'search_clients');
        fd.append('query', query);
        fd.append('page', currentPage);

        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        loadedClients = data.rows;

        document.getElementById('client-count').innerText = `(${data.total})`;
        const tbody = document.getElementById('client-table-body');

        tbody.innerHTML = data.rows.map(c => `
            <tr class="hover:bg-slate-800/30 transition-colors">
                <td class="p-5">
                    <div class="font-bold text-white text-sm">${c.username}</div>
                    <div class="text-xs text-blue-400">${c.domain || 'No domain'}</div>
                </td>
                <td class="p-5">
                    <span class="bg-slate-800 border border-slate-700 px-3 py-1 rounded-full text-[10px] font-bold text-slate-300">${c.pkg_name}</span>
                </td>
                <td class="p-5">
                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border ${c.status === 'active' ? 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20' : 'bg-red-500/10 text-red-500 border-red-500/20'}">
                        ${c.status.toUpperCase()}
                    </span>
                </td>
                <td class="p-5 text-right flex justify-end gap-1">
                    <button onclick="loginAs('${c.username}', ${c.id})" class="p-2 text-slate-400 hover:text-blue-400" title="Login"><i data-lucide="key" class="w-4"></i></button>
                    <button onclick="toggleSus('${c.username}', ${c.status === 'active'})" class="p-2 text-slate-400 hover:text-orange-400" title="Suspend"><i data-lucide="${c.status === 'active' ? 'pause-circle' : 'play-circle'}" class="w-4"></i></button>
                    <button onclick="editClient(${c.id})" class="p-2 text-slate-400 hover:text-white" title="Edit"><i data-lucide="edit-3" class="w-4"></i></button>
                    <button onclick="resetAcc('${c.username}')" class="p-2 text-slate-400 hover:text-red-400" title="Reset Files"><i data-lucide="rotate-ccw" class="w-4"></i></button>
                    <button onclick="delAcc(${c.id}, '${c.username}')" class="p-2 text-slate-400 hover:text-red-500" title="Delete"><i data-lucide="trash-2" class="w-4"></i></button>
                </td>
            </tr>
        `).join('');

        renderPagination(data.pages);
        lucide.createIcons();
    }

    function renderPagination(totalPages) {
        const container = document.getElementById('pagination-container');
        if (totalPages <= 1) { container.innerHTML = ''; return; }
        container.innerHTML = `
            <div class="text-xs text-slate-500 font-bold uppercase">Page ${currentPage} / ${totalPages}</div>
            <div class="flex gap-2">
                <button onclick="changePage(-1)" ${currentPage === 1 ? 'disabled' : ''} class="bg-slate-800 text-white px-4 py-2 rounded-lg text-xs disabled:opacity-30">Prev</button>
                <button onclick="changePage(1)" ${currentPage === totalPages ? 'disabled' : ''} class="bg-slate-800 text-white px-4 py-2 rounded-lg text-xs disabled:opacity-30">Next</button>
            </div>`;
    }

    function changePage(dir) { currentPage += dir; loadClients(); }

    function editClient(id) {
        const data = loadedClients.find(c => c.id == id);
        openAccModal(data);
    }

    function openAccModal(data = null) {
        const f = document.getElementById('form-acc'); f.reset();
        const uInp = document.getElementById('acc-user');
        if (data) {
            document.getElementById('acc-id').value = data.id;
            uInp.value = data.username; uInp.readOnly = true;
            document.getElementById('acc-dom').value = data.domain;
            document.getElementById('acc-email').value = data.email;
            document.getElementById('acc-pkg').value = data.package_id;
            document.getElementById('acc-title').innerText = "Edit Client";
        } else {
            document.getElementById('acc-id').value = ""; uInp.readOnly = false;
            document.getElementById('acc-title').innerText = "Create Client";
        }
        document.getElementById('modal-acc').classList.remove('hidden');
    }

    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

    async function handleGeneric(e, action) {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('ajax_action', action);
        const res = await fetch('', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.status === 'success') { showToast('success', 'Changes applied'); loadClients(); closeModal('modal-acc'); }
        else showToast('error', d.msg);
    }

    function toggleSus(user, active) {
        if (!confirm(`${active ? 'Suspend' : 'Unsuspend'} user ${user}?`)) return;
        const fd = new FormData();
        fd.append('ajax_action', 'suspend_account');
        fd.append('user', user); fd.append('suspend', active);
        fetch('', { method: 'POST', body: fd }).then(() => loadClients());
    }

    function resetAcc(user) {
        if (!confirm(`WIPE ALL FILES for ${user}? Databases and records stay, but public_html is reset.`)) return;
        const fd = new FormData();
        fd.append('ajax_action', 'reset_account');
        fd.append('user', user);
        fetch('', { method: 'POST', body: fd }).then(() => showToast('success', 'Reset process started'));
    }

    function delAcc(id, user) {
        if (!confirm(`PERMANENTLY DELETE user ${user}? This will delete all files, DNS, mail and databases.`)) return;
        const fd = new FormData();
        fd.append('ajax_action', 'delete_account');
        fd.append('id', id); fd.append('user', user);
        fetch('', { method: 'POST', body: fd }).then(() => loadClients());
    }

    function loginAs(user, cid) {
        const fd = new FormData();
        fd.append('ajax_action', 'login_as_client');
        fd.append('user', user); fd.append('cid', cid);
        fetch('', { method: 'POST', body: fd }).then(r => r.json()).then(d => window.location.href = d.redirect);
    }

    document.addEventListener('DOMContentLoaded', loadClients);
</script>

<?php include 'layout/footer.php'; ?>