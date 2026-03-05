<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

/**
 * HELPER: Fetch Clients (One row per client)
 */
function getClientsData($pdo, $search = '', $page = 1, $limit = 10, $status = '', $plan = '')
{
    $offset = ($page - 1) * $limit;
    $params = [];
    $where = " WHERE 1=1 ";

    if (!empty($search)) {
        $where .= " AND (c.username LIKE ? OR d.domain LIKE ? OR c.email LIKE ?) ";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($status)) {
        $where .= " AND c.status = ? ";
        $params[] = $status;
    }

    if (!empty($plan)) {
        $where .= " AND c.package_id = ? ";
        $params[] = $plan;
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
        verify_csrf();
        if ($action == 'search_clients') {
            echo json_encode(getClientsData($pdo, $_POST['query'] ?? '', (int) ($_POST['page'] ?? 1), 10, $_POST['status'] ?? '', $_POST['plan'] ?? ''));
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
                $pdo->prepare("INSERT INTO mail_domains (client_id, domain) VALUES (?, ?)")->execute([$cid, $d]);

                // DNS Records
                $ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'A', '@', ?)")->execute([$dom_id, $ip]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'MX', '@', ?)")->execute([$dom_id, "mail.$d"]);
                $pdo->commit();

                // Send Email Notification if requested
                if (isset($_POST['send_welcome']) && $_POST['send_welcome'] == '1' && !empty($_POST['pass'])) {
                    $subject = "Welcome to " . get_branding() . " - Account Created";
                    $message = "Hello $u,\n\nYour account has been created successfully.\n\n";
                    $message .= "Domain: $d\nUsername: $u\nPassword: " . $_POST['pass'] . "\n\n";
                    $message .= "Control Panel URL: " . $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . "/cpanel\n\n";
                    $message .= "Thank you for choosing " . get_branding() . "!\n";
                    $headers = "From: no-reply@" . $_SERVER['HTTP_HOST'];
                    @mail($e, $subject, $message, $headers);
                }

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

            // Execute system command first while DB records still exist for the bash script to query
            cmd("delete-account " . escapeshellarg($user));

            foreach ($doms as $dm) {
                $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ?")->execute([$dm['id']]);
                $pdo->prepare("DELETE FROM mail_domains WHERE domain = ?")->execute([$dm['domain']]);
            }
            $pdo->prepare("DELETE FROM ftp_users WHERE homedir LIKE ?")->execute(["%/home/$user%"]);
            $pdo->prepare("DELETE FROM domains WHERE client_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);
            $pdo->commit();

            echo json_encode($res);
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
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
            $host = $_SERVER['HTTP_HOST'];
            $host = str_replace('admin.', 'client.', $host);
            $host = str_replace('whm.', 'client.', $host);
            echo json_encode(['status' => 'success', 'redirect' => (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $host]);
            exit;
        }

        echo json_encode($res);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        sendResponse(['status' => 'error', 'msg' => $e->getMessage()]);
    }
}

$packages = $pdo->query("SELECT * FROM packages")->fetchAll(PDO::FETCH_ASSOC);
include 'layout/header.php';
?>

<!-- HEADER & REAL-TIME SEARCH -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; gap: 1rem;">
    <div style="display: flex; align-items: center; gap: 1rem;">
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--slate-900); font-family: var(--font-heading);">
            Clients <span id="client-count"
                style="color: var(--slate-700); font-size: 1.125rem; margin-left: 0.5rem;"></span></h2>
        <div style="position: relative; display: flex; gap: 0.5rem;">
            <div style="position: relative;">
                <i data-lucide="search"
                    style="width: 1rem; height: 1rem; position: absolute; left: 0.75rem; top: 0.75rem; color: var(--slate-700);"></i>
                <input id="live-search" onkeyup="debounceSearch()" placeholder="Search username, email or domain..."
                    class="form-input" style="padding-left: 2.5rem; width: 18rem; border-radius: 0.75rem;">
            </div>
            <select id="filter-status" onchange="debounceSearch()" class="form-input"
                style="padding: 0.5rem; border-radius: 0.75rem; font-size: 0.875rem;">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
            </select>
            <select id="filter-plan" onchange="debounceSearch()" class="form-input"
                style="padding: 0.5rem; border-radius: 0.75rem; font-size: 0.875rem; max-width: 12rem;">
                <option value="">All Plans</option>
                <?php foreach ($packages as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <button onclick="openAccModal()" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem;">
        <i data-lucide="plus-circle" style="width: 1rem; height: 1rem;"></i> Create Account
    </button>
</div>

<!-- DATA TABLE -->
<div class="glass-card" style="border-radius: 1rem; overflow: hidden; padding: 0;">
    <div class="table-container" style="margin: 0;">
        <table class="modern-table" style="width: 100%; text-align: left; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="padding: 1.25rem;">Client / Primary Domain</th>
                    <th style="padding: 1.25rem;">Plan</th>
                    <th style="padding: 1.25rem;">Status</th>
                    <th style="padding: 1.25rem; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="client-table-body" style="border-top: 1px solid var(--border-color);"></tbody>
        </table>
    </div>
</div>

<div id="pagination-container"
    style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem;"></div>

<!-- CRUD MODAL -->
<div id="modal-acc" class="modal hidden"
    style="position: fixed; inset: 0; background: var(--bg-glass); backdrop-filter: blur(12px); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 1.5rem; transition: all var(--transition-normal);">
    <form id="form-acc" onsubmit="handleGeneric(event, 'save_account')" class="glass-card animate-slide-right"
        style="padding: 2.5rem; border-radius: 1.5rem; width: 100%; max-width: 32rem; background: var(--bg-surface); box-shadow: var(--shadow-2xl); border: 1px solid var(--border-color);">
        <h3 id="acc-title"
            style="font-size: 1.5rem; font-weight: 700; margin-bottom: 2rem; color: var(--text-primary);">
            Client Details</h3>
        <input type="hidden" name="id" id="acc-id">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div>
                <label
                    style="font-size: 0.625rem; color: var(--slate-700); font-weight: 700; text-transform: uppercase;">Username</label>
                <input name="user" id="acc-user" required class="form-input" style="width: 100%;">
            </div>
            <div>
                <label
                    style="font-size: 0.625rem; color: var(--slate-700); font-weight: 700; text-transform: uppercase;">Domain</label>
                <input name="dom" id="acc-dom" required placeholder="domain.com" class="form-input"
                    style="width: 100%;">
            </div>
            <div style="grid-column: span 2;">
                <label
                    style="font-size: 0.625rem; color: var(--slate-700); font-weight: 700; text-transform: uppercase;">Email</label>
                <input name="email" id="acc-email" type="email" required class="form-input" style="width: 100%;">
            </div>
            <div>
                <label
                    style="font-size: 0.625rem; color: var(--slate-700); font-weight: 700; text-transform: uppercase;">Password</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input name="pass" id="acc-pass" type="password" class="form-input" style="width: 100%;"
                        placeholder="(Leave blank to keep)">
                    <button type="button" onclick="generatePass()" class="btn btn-outline" style="padding: 0 0.5rem;"
                        title="Generate Password"><i data-lucide="key" style="width:1rem;height:1rem;"></i></button>
                </div>
                <div id="gen-pass-display"
                    style="font-size: 0.75rem; color: var(--primary); margin-top: 0.25rem; font-weight: bold;"></div>
            </div>
            <div>
                <label
                    style="font-size: 0.625rem; color: var(--slate-700); font-weight: 700; text-transform: uppercase;">Plan</label>
                <select name="package_id" id="acc-pkg" class="form-input" style="width: 100%;">
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="acc-welcome-wrapper"
                style="grid-column: span 2; display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
                <input type="checkbox" name="send_welcome" id="acc-send-email" value="1" checked
                    style="width: 1rem; height: 1rem;">
                <label for="acc-send-email" style="font-size: 0.875rem; color: var(--slate-700);">Send welcome email to
                    client with credentials</label>
            </div>

            <div
                style="grid-column: span 2; display: flex; gap: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <button type="button" onclick="closeModal('modal-acc')" class="btn btn-outline"
                    style="flex: 1;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="flex: 1;">Save Client</button>
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
        fd.append('status', document.getElementById('filter-status').value);
        fd.append('plan', document.getElementById('filter-plan').value);
        fd.append('page', currentPage);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        loadedClients = data.rows;

        document.getElementById('client-count').innerText = `(${data.total})`;
        const tbody = document.getElementById('client-table-body');

        tbody.innerHTML = data.rows.map(c => {
            const safeUsername = c.username.replace(/'/g, "\\'");
            return `
            <tr style="transition: background-color var(--transition-fast); border-bottom: 1px solid var(--border-color);" onmouseover="this.style.backgroundColor='var(--primary-light)'" onmouseout="this.style.backgroundColor='transparent'">
                <td style="padding: 1.25rem;">
                    <div style="font-weight: 700; color: var(--text-primary); font-size: 0.875rem;">${c.username}</div>
                    <div style="font-size: 0.75rem; color: var(--primary-hover);">${c.domain || 'No domain'}</div>
                </td>
                <td style="padding: 1.25rem;">
                    <span style="background: var(--bg-body); border: 1px solid var(--border-color); padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.625rem; font-weight: 700; color: var(--text-secondary);">${c.pkg_name}</span>
                </td>
                <td style="padding: 1.25rem;">
                    <span style="padding: 0.25rem 0.625rem; border-radius: 9999px; font-size: 0.625rem; font-weight: 700; border: 1px solid ${c.status === 'active' ? 'rgba(16,185,129,0.2)' : 'rgba(239,68,68,0.2)'}; background: ${c.status === 'active' ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)'}; color: ${c.status === 'active' ? 'var(--accent-emerald)' : 'var(--accent-red)'};">
                        ${c.status.toUpperCase()}
                    </span>
                </td>
                <td style="padding: 1.25rem; text-align: right; display: flex; justify-content: flex-end; gap: 0.25rem;">
                    <button onclick="loginAs('${safeUsername}', ${c.id})" style="padding: 0.5rem; color: var(--text-secondary); background: transparent; border: none; cursor: pointer; transition: color 0.2s;" onmouseover="this.style.color='var(--primary-hover)'" onmouseout="this.style.color='var(--text-secondary)'" title="Login"><i data-lucide="key" style="width: 1rem; height: 1rem;"></i></button>
                    <button onclick="toggleSus('${safeUsername}', ${c.status === 'active'})" style="padding: 0.5rem; color: var(--text-secondary); background: transparent; border: none; cursor: pointer; transition: color 0.2s;" onmouseover="this.style.color='var(--accent-amber)'" onmouseout="this.style.color='var(--text-secondary)'" title="Suspend"><i data-lucide="${c.status === 'active' ? 'pause-circle' : 'play-circle'}" style="width: 1rem; height: 1rem;"></i></button>
                    <button onclick="editClient(${c.id})" style="padding: 0.5rem; color: var(--text-secondary); background: transparent; border: none; cursor: pointer; transition: color 0.2s;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-secondary)'" title="Edit"><i data-lucide="edit-3" style="width: 1rem; height: 1rem;"></i></button>
                    <button onclick="resetAcc('${safeUsername}')" style="padding: 0.5rem; color: var(--text-secondary); background: transparent; border: none; cursor: pointer; transition: color 0.2s;" onmouseover="this.style.color='var(--accent-red)'" onmouseout="this.style.color='var(--text-secondary)'" title="Reset Files"><i data-lucide="rotate-ccw" style="width: 1rem; height: 1rem;"></i></button>
                    <button onclick="delAcc(${c.id}, '${safeUsername}')" style="padding: 0.5rem; color: var(--text-secondary); background: transparent; border: none; cursor: pointer; transition: color 0.2s;" onmouseover="this.style.color='var(--accent-red)'" onmouseout="this.style.color='var(--text-secondary)'" title="Delete"><i data-lucide="trash-2" style="width: 1rem; height: 1rem;"></i></button>
                </td>
            </tr>
        `}).join('');

        renderPagination(data.pages);
        lucide.createIcons();
    }

    function renderPagination(totalPages) {
        const container = document.getElementById('pagination-container');
        if (totalPages <= 1) { container.innerHTML = ''; return; }
        container.innerHTML = `
            <div style="font-size: 0.75rem; color: var(--slate-700); font-weight: 700; text-transform: uppercase;">Page ${currentPage} / ${totalPages}</div>
            <div style="display: flex; gap: 0.5rem;">
                <button onclick="changePage(-1)" ${currentPage === 1 ? 'disabled' : ''} class="btn btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; border-radius: 0.5rem;" ${currentPage === 1 ? 'style="opacity: 0.5; cursor: not-allowed;"' : ''}>Prev</button>
                <button onclick="changePage(1)" ${currentPage === totalPages ? 'disabled' : ''} class="btn btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; border-radius: 0.5rem;" ${currentPage === totalPages ? 'style="opacity: 0.5; cursor: not-allowed;"' : ''}>Next</button>
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
            document.getElementById('acc-welcome-wrapper').style.display = 'none'; // Only show email send on creation
        } else {
            document.getElementById('acc-id').value = ""; uInp.readOnly = false;
            document.getElementById('acc-title').innerText = "Create Client";
            document.getElementById('acc-welcome-wrapper').style.display = 'flex';
        }
        document.getElementById('acc-pass').type = 'password';
        document.getElementById('gen-pass-display').innerText = '';
        document.getElementById('modal-acc').classList.remove('hidden');
    }

    function generatePass() {
        const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
        let pass = "";
        for (let i = 0; i < 12; i++) { pass += chars.charAt(Math.floor(Math.random() * chars.length)); }
        const passInp = document.getElementById('acc-pass');
        passInp.type = 'text';
        passInp.value = pass;
        document.getElementById('gen-pass-display').innerText = "Generated: " + pass;
    }

    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

    async function handleGeneric(e, action) {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('ajax_action', action);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
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
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        fetch('', { method: 'POST', body: fd }).then(() => loadClients());
    }

    function resetAcc(user) {
        if (!confirm(`WIPE ALL FILES for ${user}? Databases and records stay, but public_html is reset.`)) return;
        const fd = new FormData();
        fd.append('ajax_action', 'reset_account');
        fd.append('user', user);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        fetch('', { method: 'POST', body: fd }).then(() => showToast('success', 'Reset process started'));
    }

    function delAcc(id, user) {
        if (!confirm(`PERMANENTLY DELETE user ${user}? This will delete all files, DNS, mail and databases.`)) return;
        const fd = new FormData();
        fd.append('ajax_action', 'delete_account');
        fd.append('id', id); fd.append('user', user);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        fetch('', { method: 'POST', body: fd }).then(() => loadClients());
    }

    function loginAs(user, cid) {
        const fd = new FormData();
        fd.append('ajax_action', 'login_as_client');
        fd.append('user', user); fd.append('cid', cid);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
        fetch('', { method: 'POST', body: fd }).then(r => r.json()).then(d => window.location.href = d.redirect);
    }

    document.addEventListener('DOMContentLoaded', loadClients);
</script>

<?php include 'layout/footer.php'; ?>