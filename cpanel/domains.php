<?php
require_once __DIR__ . '/../shared/config.php';

if (!isset($_SESSION['client'])) {
    header("Location: login.php");
    exit;
}
$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

// Global search functionality
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    // CSRF Protection
    try {
        verify_csrf();
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }

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

            // Check if parent_id column exists
            $has_parent_id = false;
            try {
                $check_col = $pdo->query("SHOW COLUMNS FROM domains LIKE 'parent_id'");
                $has_parent_id = ($check_col->rowCount() > 0);
            } catch (Exception $e) {
                $has_parent_id = false;
            }

            // Validate: if no parent_id is passed via form, this must be a main domain (not subdomain)
            // Main domain = exactly 2 parts (domain.tld), Subdomain = 3+ parts (sub.domain.tld)
            $dom_for_validation = preg_replace('/^www\./', '', $dom);
            $dom_parts = explode('.', $dom_for_validation);
            $is_subdomain = (count($dom_parts) > 2);

            // Check Parent Domain (If Subdomain)
            $parent_id = null;
            $explicit_parent = isset($_POST['parent_id']) ? trim($_POST['parent_id']) : '';

            if ($has_parent_id && $explicit_parent) {
                // Subdomain mode - parent explicitly selected
                $get_p = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND client_id = ?");
                $get_p->execute([$explicit_parent, $cid]);
                $pid = $get_p->fetchColumn();
                if ($pid)
                    $parent_id = $pid;
            } elseif ($is_subdomain && !$explicit_parent) {
                // Trying to create subdomain without selecting parent - reject
                throw new Exception("Subdomains must be created using the +Sub mode. Please select a parent domain.");
            }

            try {
                if ($has_parent_id) {
                    $pdo->prepare("INSERT INTO domains (client_id, domain, document_root, parent_id) VALUES (?, ?, ?, ?)")->execute([$cid, $dom, "/var/www/clients/$username/domains/$dom/public_html", $parent_id]);
                } else {
                    $pdo->prepare("INSERT INTO domains (client_id, domain, document_root) VALUES (?, ?, ?)")->execute([$cid, $dom, "/var/www/clients/$username/domains/$dom/public_html"]);
                }
                $dom_id = $pdo->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    throw new Exception("Domain already exists (Database Constraint)");
                }
                throw $e;
            }

            $server_ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';

            if ($has_parent_id && $parent_id) {
                // It IS a subdomain of a managed parent. 
                // We do NOT create a new Zone. We add an A record to the PARENT.
                $host = str_replace("." . $explicit_parent, "", $dom); // e.g. "blog"

                // Add 'A' record to Parent
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'A', ?, ?)")->execute([$parent_id, $host, $server_ip]);

                // Sync Parent DNS
                cmd("dns-tool sync $parent_id");

                // Sync VHost (still needed for the sub)
                cmd("add-domain " . escapeshellarg($username) . " " . escapeshellarg($dom));
                cmd("vhost-tool sync $dom_id");

            } else {
                // Standard Domain Logic
                // Auto DNS
                $host_parts = explode('.', $_SERVER['HTTP_HOST'] ?? 'localhost');
                $base_domain = count($host_parts) >= 2 ? implode('.', array_slice($host_parts, -2)) : 'localhost';
                $mail_host = "mail." . $base_domain;

                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'A', '@', ?)")->execute([$dom_id, $server_ip]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'CNAME', 'www', '@')")->execute([$dom_id]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'A', 'mail', ?)")->execute([$dom_id, $server_ip]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'MX', '@', ?)")->execute([$dom_id, $mail_host]);

                $spf = "v=spf1 a mx ip4:$server_ip -all";
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'TXT', '@', ?)")->execute([$dom_id, $spf]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'TXT', '_dmarc', 'v=DMARC1; p=none')")->execute([$dom_id]);

                // Add NS Records
                $ns1 = "ns1." . $base_domain;
                $ns2 = "ns2." . $base_domain;
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'NS', '@', ?)")->execute([$dom_id, $ns1]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, 'NS', '@', ?)")->execute([$dom_id, $ns2]);

                // Syncs
                cmd("add-domain " . escapeshellarg($username) . " " . escapeshellarg($dom));
                cmd("vhost-tool sync $dom_id");
                cmd("dns-tool sync $dom_id");
            }

            sendResponse($res);
            exit;
        }

        if ($action == 'delete_domain') {
            $dom_id = (int) $_POST['domain_id'];

            // Check if parent_id column exists
            $has_parent_id = false;
            try {
                $check_col = $pdo->query("SHOW COLUMNS FROM domains LIKE 'parent_id'");
                $has_parent_id = ($check_col->rowCount() > 0);
            } catch (Exception $e) {
                $has_parent_id = false;
            }

            // Build query based on column existence
            if ($has_parent_id) {
                $d = $pdo->prepare("SELECT domain, parent_id FROM domains WHERE id=? AND client_id=?");
            } else {
                $d = $pdo->prepare("SELECT domain FROM domains WHERE id=? AND client_id=?");
            }

            $d->execute([$dom_id, $cid]);
            $dom_info = $d->fetch();

            if (!$dom_info)
                throw new Exception("Invalid Domain");

            $domain_name = $dom_info['domain'];
            $parent_id = $has_parent_id ? ($dom_info['parent_id'] ?? null) : null;

            // Start transaction for clean deletion
            $pdo->beginTransaction();

            try {
                if ($parent_id) {
                    // Cleanup Parent DNS
                    $pd = $pdo->prepare("SELECT domain FROM domains WHERE id=?");
                    $pd->execute([$parent_id]);
                    $parent_name = $pd->fetchColumn();

                    if ($parent_name) {
                        $host = str_replace("." . $parent_name, "", $domain_name);
                        $pdo->prepare("DELETE FROM dns_records WHERE domain_id=? AND name=? AND type='A'")->execute([$parent_id, $host]);
                        cmd("dns-tool sync $parent_id");
                    }
                } else {
                    // This is a parent domain - delete all its DNS records
                    $pdo->prepare("DELETE FROM dns_records WHERE domain_id=?")->execute([$dom_id]);

                    // Also delete DNS records for any subdomains pointing to this parent (only if parent_id column exists)
                    if ($has_parent_id) {
                        $pdo->prepare("DELETE FROM dns_records WHERE domain_id IN (SELECT id FROM domains WHERE parent_id=?)")->execute([$dom_id]);
                    }
                }

                // Delete all related records for this domain
                // 1. Delete PHP config
                try {
                    $pdo->prepare("DELETE FROM php_config WHERE domain_id=?")->execute([$dom_id]);
                } catch (Exception $e) {
                }

                // 2. Delete domain traffic records
                try {
                    $pdo->prepare("DELETE FROM domain_traffic WHERE domain_id=?")->execute([$dom_id]);
                } catch (Exception $e) {
                }

                // 3. Delete malware scan records
                try {
                    $pdo->prepare("DELETE FROM malware_scans WHERE domain_id=?")->execute([$dom_id]);
                } catch (Exception $e) {
                }

                // 4. Delete any subdomains of this domain (only if parent_id column exists)
                if ($has_parent_id) {
                    $subdomains = $pdo->prepare("SELECT id FROM domains WHERE parent_id=?");
                    $subdomains->execute([$dom_id]);
                    while ($sub = $subdomains->fetch()) {
                        $sub_id = $sub['id'];
                        // Delete subdomain related records
                        try {
                            $pdo->prepare("DELETE FROM php_config WHERE domain_id=?")->execute([$sub_id]);
                        } catch (Exception $e) {
                        }
                        try {
                            $pdo->prepare("DELETE FROM domain_traffic WHERE domain_id=?")->execute([$sub_id]);
                        } catch (Exception $e) {
                        }
                        try {
                            $pdo->prepare("DELETE FROM malware_scans WHERE domain_id=?")->execute([$sub_id]);
                        } catch (Exception $e) {
                        }
                    }

                    // 5. Delete the subdomains themselves
                    $pdo->prepare("DELETE FROM domains WHERE parent_id=?")->execute([$dom_id]);
                }

                // 6. Finally delete the domain itself
                $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$dom_id]);

                $pdo->commit();

                // Execute system commands after successful DB deletion
                cmd("delete-domain " . escapeshellarg($username) . " " . escapeshellarg($domain_name));

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            sendResponse($res);
            exit;
        }

        if ($action == 'update_domain_config') {
            set_time_limit(300); // Allow 5 minutes for Certbot/SSL operations
            $did = (int) $_POST['domain_id'];

            // Check domain ownership first
            $chk = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $chk->execute([$did, $cid]);
            if (!$chk->fetch())
                throw new Exception("Invalid Domain ID");

            $pdo->prepare("UPDATE domains SET php_version = ?, ssl_active = ? WHERE id = ?")->execute([$_POST['php_version'], isset($_POST['ssl']) ? 1 : 0, $did]);

            // Handle php_config safely
            $exists = $pdo->prepare("SELECT 1 FROM php_config WHERE domain_id = ?");
            $exists->execute([$did]);
            if ($exists->fetch()) {
                $pdo->prepare("UPDATE php_config SET memory_limit = ? WHERE domain_id = ?")->execute([$_POST['mem'], $did]);
            } else {
                try {
                    $pdo->prepare("INSERT INTO php_config (domain_id, memory_limit) VALUES (?, ?)")->execute([$did, $_POST['mem']]);
                } catch (PDOException $e) {
                    // If duplicate entry occurs (race condition), fallback to update
                    if ($e->getCode() == 23000) {
                        $pdo->prepare("UPDATE php_config SET memory_limit = ? WHERE domain_id = ?")->execute([$_POST['mem'], $did]);
                    } else {
                        throw $e;
                    }
                }
            }

            // Sync Vhost (Triggers SSL Install if needed)
            if (function_exists('cmd')) {
                cmd("vhost-tool sync " . $did . " > /dev/null 2>&1 &");
            }
            sendResponse($res);
            exit;
        }

        if ($action == 'fix_default_page') {
            $did = (int) $_POST['domain_id'];
            $chk = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $chk->execute([$did, $cid]);
            if (!$chk->fetch())
                throw new Exception("Invalid Domain");

            if (function_exists('cmd')) {
                cmd("troubleshoot fix-default-page $did");
            }
            sendResponse($res);
            exit;
        }

        if ($action == 'add_dns') {
            $dom_id = $_POST['domain_id'];
            $check = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $check->execute([$dom_id, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $type = $_POST['type'];
            $host = $_POST['host'];
            $value = '';

            // Validation & Packing
            if ($type == 'A') {
                if (!filter_var($_POST['value'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
                    throw new Exception("Invalid IPv4 Address");
                $value = $_POST['value'];
            } elseif ($type == 'AAAA') {
                if (!filter_var($_POST['value'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
                    throw new Exception("Invalid IPv6 Address");
                $value = $_POST['value'];
            } elseif ($type == 'CNAME' || $type == 'NS') {
                $value = $_POST['value'];
            } elseif ($type == 'TXT') {
                $value = $_POST['value'];
            } elseif ($type == 'MX') {
                $prio = (int) $_POST['priority'];
                $val = $_POST['value'];
                $value = "$prio $val";
            } elseif ($type == 'SRV') {
                $prio = (int) $_POST['priority'];
                $weight = (int) $_POST['weight'];
                $port = (int) $_POST['port'];
                $target = $_POST['value'];
                $value = "$prio $weight $port $target";
            } elseif ($type == 'SOA') {
                $mname = $_POST['mname'];
                $rname = $_POST['rname'];
                $serial = $_POST['serial'];
                $refresh = $_POST['refresh'];
                $retry = $_POST['retry'];
                $expire = $_POST['expire'];
                $ttl = $_POST['ttl'];
                $value = "$mname $rname $serial $refresh $retry $expire $ttl";
            } else {
                throw new Exception("Invalid Record Type");
            }

            $pdo->prepare("INSERT INTO dns_records (domain_id, type, name, value) VALUES (?, ?, ?, ?)")->execute([$dom_id, $type, $host, $value]);

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
            exit;
        }

        if ($action == 'start_scan') {
            $did = (int) $_POST['domain_id'];
            // Check ownership
            $chk = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $chk->execute([$did, $cid]);
            if (!$chk->fetch())
                throw new Exception("Access Denied");

            cmd("malware-scan $did");
            sendResponse($res);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// Data
// Pagination
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)
    $page = 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build search condition
$search_condition = "";
$search_params = [];
if ($search_query) {
    $search_condition = "AND d.domain LIKE ?";
    $search_params[] = "%$search_query%";
}

// Count Total with search
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM domains d WHERE d.client_id = ? $search_condition");
$total_stmt->execute(array_merge([$cid], $search_params));
$total_domains = $total_stmt->fetchColumn();
$total_pages = ceil($total_domains / $per_page);

// Fetch domains with search
$domain_stmt = $pdo->prepare("
    SELECT d.*, 
    (SELECT bytes_sent FROM domain_traffic WHERE domain_id = d.id ORDER BY date DESC LIMIT 1) as traffic_today,
    (SELECT status FROM malware_scans WHERE domain_id = d.id ORDER BY scanned_at DESC LIMIT 1) as scan_status,
    (SELECT scanned_at FROM malware_scans WHERE domain_id = d.id ORDER BY scanned_at DESC LIMIT 1) as last_scan
    FROM domains d 
    WHERE d.client_id = ? $search_condition
    ORDER BY d.id DESC
    LIMIT $per_page OFFSET $offset
");
$domain_stmt->execute(array_merge([$cid], $search_params));
$domains = $domain_stmt->fetchAll();

// Base Domain
$server_host = $_SERVER['HTTP_HOST'];
$parts = explode('.', $server_host);
$base_domain = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $server_host;

// Fetch ALL domains for subdomain dropdown (not just paginated ones)
$all_domains = $pdo->query("SELECT id, domain FROM domains WHERE client_id = $cid ORDER BY domain ASC")->fetchAll();

include 'layout/header.php';
?>

<div class="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
    <div class="flex items-center gap-4">
        <h2 class="text-2xl font-bold text-white">Domain Management</h2>
        <form method="GET" class="relative group">
            <i data-lucide="search"
                class="w-4 absolute left-3 top-3 text-slate-500 group-focus-within:text-blue-400 transition"></i>
            <input name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search domains..."
                class="bg-slate-900/50 border border-slate-700 p-3 pl-10 rounded-xl text-sm w-48 focus:w-64 outline-none shadow-sm focus:border-blue-500 text-white placeholder-slate-500 transition-all">
        </form>
        <?php if ($search_query): ?>
            <a href="?" class="text-xs text-slate-400 hover:text-white transition">Clear</a>
        <?php endif; ?>
    </div>
    <div class="flex gap-4">
        <!-- Add Domain - Only main domains allowed (no subdomains) -->
        <form onsubmit="handleAddDomain(event)" class="flex gap-2" id="form-add-domain">
            <?= csrf_field() ?>
            <input name="domain" required placeholder="example.com"
                class="bg-slate-900/50 border border-slate-700 p-3 rounded-xl text-sm outline-none shadow-sm focus:border-blue-500 text-white placeholder-slate-500 w-48 transition">
            <button
                class="bg-slate-800 text-white px-4 py-3 rounded-xl font-bold text-xs uppercase shadow-xl hover:bg-slate-700 border border-slate-700 transition whitespace-nowrap">
                + Domain</button>
        </form>

        <!-- Subdomain - Select from all domains -->
        <form onsubmit="handleAddSubdomain(event)" class="flex gap-2 hidden" id="form-add-subdomain">
            <?= csrf_field() ?>
            <input name="sub" required placeholder="sub (e.g. blog)"
                class="bg-slate-900/50 border border-slate-700 p-3 rounded-xl text-sm outline-none shadow-sm focus:border-blue-500 text-white placeholder-slate-500 w-32 transition text-right">
            <span class="self-center font-bold text-slate-500">.</span>
            <select name="parent_id"
                class="bg-slate-900/50 border border-slate-700 p-3 rounded-xl text-sm outline-none shadow-sm focus:border-blue-500 text-white w-40 transition">
                <?php foreach ($all_domains as $d): ?>
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

    <?php if (count($domains) === 0): ?>
        <div class="glass-card p-10 text-center">
            <i data-lucide="globe" class="w-12 h-12 text-slate-600 mx-auto mb-4"></i>
            <h3 class="text-lg font-bold text-slate-400">No domains found</h3>
            <p class="text-sm text-slate-500 mt-2">
                <?= $search_query ? 'Try a different search term' : 'Add your first domain to get started' ?>
            </p>
        </div>
    <?php endif; ?>

    <?php foreach ($domains as $index => $d):
        $is_first = ($index === 0);
        $domain_id = $d['id'];
        ?>
        <div class="glass-card mb-4 shadow-sm group domain-card" data-domain-id="<?= $domain_id ?>">
            <!-- Domain Header - Always Visible -->
            <div class="domain-header p-5 flex justify-between items-center cursor-pointer hover:bg-slate-800/30 transition rounded-xl"
                onclick="toggleDomain(<?= $domain_id ?>)">
                <div class="flex items-center gap-4">
                    <i data-lucide="chevron-down" id="chevron-<?= $domain_id ?>"
                        class="w-5 h-5 text-slate-500 transition-transform <?= $is_first ? '' : '-rotate-90' ?>"></i>
                    <div>
                        <h3 class="text-xl font-black text-white">
                            <?= $d['domain'] ?>
                        </h3>
                        <p class="text-xs text-slate-500 font-mono mt-1">/home/<?= $username ?>/public_html</p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <!-- Quick Stats -->
                    <div class="flex gap-3">
                        <div
                            class="bg-slate-900/80 backdrop-blur border border-slate-700 px-3 py-1 rounded-full text-[10px] font-bold text-slate-400 flex items-center gap-2">
                            <i data-lucide="activity" class="w-3 h-3 text-emerald-400"></i>
                            <?= $d['traffic_today'] ? round($d['traffic_today'] / 1024 / 1024, 2) . ' MB' : '0 MB' ?>
                        </div>
                        <?php if ($d['ssl_active']): ?>
                            <div
                                class="bg-emerald-500/10 border border-emerald-500/20 px-3 py-1 rounded-full text-[10px] font-bold text-emerald-400 flex items-center gap-2">
                                <i data-lucide="lock" class="w-3 h-3"></i> SSL
                            </div>
                        <?php endif; ?>
                        <?php if ($d['scan_status'] == 'clean'): ?>
                            <div
                                class="bg-emerald-500/10 border border-emerald-500/20 px-3 py-1 rounded-full text-[10px] font-bold text-emerald-400 flex items-center gap-2">
                                <i data-lucide="shield-check" class="w-3 h-3"></i> Clean
                            </div>
                        <?php elseif ($d['scan_status'] == 'infected'): ?>
                            <div
                                class="bg-red-500/10 border border-red-500/20 px-3 py-1 rounded-full text-[10px] font-bold text-red-400 flex items-center gap-2">
                                <i data-lucide="shield-alert" class="w-3 h-3"></i> Infected
                            </div>
                        <?php elseif ($d['scan_status'] == 'running'): ?>
                            <div
                                class="bg-blue-500/10 border border-blue-500/20 px-3 py-1 rounded-full text-[10px] font-bold text-blue-400 flex items-center gap-2">
                                <i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i> Scanning
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Quick Actions -->
                    <div class="flex gap-2" onclick="event.stopPropagation()">
                        <a href="files.php?domain_id=<?= $d['id'] ?>&path=/" target="_blank"
                            class="bg-blue-500/10 text-blue-400 px-3 py-2 rounded-lg text-xs font-bold hover:bg-blue-600 hover:text-white transition flex items-center gap-2 border border-blue-500/20">
                            <i data-lucide="folder-open" class="w-4 h-4"></i>
                        </a>
                        <button onclick="fixDefaultPage(<?= $d['id'] ?>)"
                            class="bg-yellow-500/10 text-yellow-500 px-3 py-2 rounded-lg text-xs font-bold hover:bg-yellow-600 hover:text-white transition border border-yellow-500/20"
                            title="Fix Default Page Issue">
                            <i data-lucide="wrench" class="w-4 h-4"></i>
                        </button>
                        <button onclick="deleteAction('delete_domain', 'domain_id', <?= $d['id'] ?>)"
                            class="bg-red-500/10 text-red-400 px-3 py-2 rounded-lg text-xs font-bold hover:bg-red-600 hover:text-white transition border border-red-500/20">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Domain Content - Collapsible -->
            <div id="domain-content-<?= $domain_id ?>"
                class="domain-content <?= $is_first ? '' : 'hidden' ?> border-t border-slate-700/50">
                <div class="p-5">
                    <!-- Configuration Row -->
                    <form onsubmit="handleGeneric(event, 'update_domain_config')"
                        class="flex flex-wrap items-center gap-4 bg-slate-900/50 p-4 rounded-2xl border border-slate-700/50 mb-6">
                        <?= csrf_field() ?>
                        <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                        <div class="flex items-center gap-2">
                            <label class="text-[10px] uppercase font-bold text-slate-500">PHP</label>
                            <select name="php_version"
                                class="bg-slate-800 border border-slate-700 p-2 rounded-xl text-xs font-bold text-white">
                                <option value="8.1" <?= $d['php_version'] == '8.1' ? 'selected' : '' ?>>PHP 8.1</option>
                                <option value="8.2" <?= $d['php_version'] == '8.2' ? 'selected' : '' ?>>PHP 8.2</option>
                                <option value="8.3" <?= $d['php_version'] == '8.3' ? 'selected' : '' ?>>PHP 8.3</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-[10px] uppercase font-bold text-slate-500">Memory</label>
                            <select name="mem"
                                class="bg-slate-800 border border-slate-700 p-2 rounded-xl text-xs font-bold text-white">
                                <?php
                                $curr_mem = $pdo->query("SELECT memory_limit FROM php_config WHERE domain_id=" . $d['id'])->fetchColumn();
                                if (!$curr_mem)
                                    $curr_mem = '512M';
                                $opts = ['128M', '256M', '512M', '1024M', '2048M', '4096M'];
                                foreach ($opts as $m): ?>
                                    <option value="<?= $m ?>" <?= $curr_mem == $m ? 'selected' : '' ?>><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-center gap-2 px-3 border-l border-slate-700">
                            <input type="checkbox" name="ssl" <?= $d['ssl_active'] ? 'checked' : '' ?>
                                class="w-4 h-4 text-emerald-500 accent-emerald-500">
                            <span class="text-[10px] font-bold uppercase text-emerald-400">SSL</span>
                        </div>
                        <button
                            class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-500 transition text-xs font-bold ml-auto">
                            <i data-lucide="save" class="w-4 h-4 inline mr-1"></i> Save
                        </button>
                    </form>

                    <?php if (isset($d['parent_id']) && $d['parent_id']): ?>
                        <?php
                        $pname = $pdo->query("SELECT domain FROM domains WHERE id={$d['parent_id']}")->fetchColumn();
                        ?>
                        <div class="text-center p-8 bg-slate-900/30 rounded-xl border border-slate-800 border-dashed">
                            <i data-lucide="git-merge" class="w-8 h-8 text-slate-600 mx-auto mb-2"></i>
                            <p class="text-sm font-bold text-slate-400">DNS Managed by Parent Domain</p>
                            <p class="text-xs text-slate-600">This subdomain is a record of <span
                                    class="text-blue-400"><?= $pname ?></span></p>
                        </div>
                    <?php else: ?>
                        <h4 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-4">DNS Zone Management</h4>

                        <!-- Security Section -->
                        <div
                            class="mb-6 p-4 bg-slate-900/30 rounded-xl border border-slate-800 flex justify-between items-center">
                            <div>
                                <h4 class="text-white font-bold text-sm flex items-center gap-2"><i data-lucide="shield"
                                        class="w-4 text-purple-400"></i> Malware Protection</h4>
                                <p class="text-[10px] text-slate-500 mt-1">Status:
                                    <?php if ($d['scan_status'] == 'clean'): ?>
                                        <span class="text-emerald-400">Clean</span>
                                    <?php elseif ($d['scan_status'] == 'infected'): ?>
                                        <span class="text-red-400 blink">Infected!</span>
                                    <?php elseif ($d['scan_status'] == 'running'): ?>
                                        <span class="text-blue-400 animate-pulse">Scanning...</span>
                                    <?php else: ?>
                                        <span class="text-slate-500">Not Scanned</span>
                                    <?php endif; ?>
                                    <?php if ($d['last_scan']): ?>
                                        <span class="opacity-50 ml-2">Last: <?= $d['last_scan'] ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <button onclick="startScan(<?= $d['id'] ?>)"
                                class="bg-purple-500/10 text-purple-400 border border-purple-500/20 px-4 py-2 rounded-lg text-xs font-bold hover:bg-purple-600 hover:text-white transition">Run
                                Scan</button>
                        </div>

                        <!-- DNS Tabs -->
                        <div class="mb-4">
                            <div class="flex flex-wrap gap-2 mb-4" id="dns-tabs-<?= $d['id'] ?>">
                                <?php foreach (['A', 'AAAA', 'MX', 'CNAME', 'NS', 'TXT', 'SRV', 'SOA'] as $t): ?>
                                    <button type="button" onclick="setDnsType(<?= $d['id'] ?>, '<?= $t ?>')"
                                        id="btn-dns-<?= $t ?>-<?= $d['id'] ?>"
                                        class="dns-type-btn px-4 py-2 rounded-lg text-xs font-bold border border-slate-700 transition <?= $t === 'A' ? 'bg-blue-600 text-white border-blue-500' : 'bg-slate-800 text-slate-400 hover:bg-slate-700' ?>">
                                        <?= $t ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <!-- Add DNS Form -->
                            <form onsubmit="handleGeneric(event, 'add_dns')"
                                class="glass-card p-5 border border-slate-700/50 bg-slate-900/30 rounded-xl relative overflow-hidden mb-6">
                                <div class="absolute top-0 left-0 w-1 h-full bg-blue-500"></div>
                                <?= csrf_field() ?>
                                <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="type" id="input-dns-type-<?= $d['id'] ?>" value="A">

                                <div id="dns-fields-<?= $d['id'] ?>" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                                    <div class="col-span-4"><label
                                            class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Host</label><input
                                            name="host" value="@"
                                            class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner">
                                    </div>
                                    <div class="col-span-8"><label
                                            class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">IPv4
                                            Address</label><input name="value" placeholder="192.168.1.1"
                                            class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner">
                                    </div>
                                </div>

                                <div class="mt-4 flex justify-end">
                                    <button
                                        class="bg-blue-600 text-white px-5 py-2 rounded-xl font-bold text-xs uppercase shadow-xl hover:bg-blue-500 transition border border-blue-400 flex items-center gap-2">
                                        <i data-lucide="plus-circle" class="w-4 h-4"></i> Add Record
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- DNS Records Table -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
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
                                    $has_records = false;
                                    while ($r = $recs->fetch()):
                                        $has_records = true;
                                        ?>
                                        <tr class="text-sm hover:bg-slate-800/30 transition">
                                            <td class="p-3 font-bold text-slate-300"><?= $r['name'] ?></td>
                                            <td class="p-3"><span
                                                    class="bg-slate-800 border border-slate-700 px-2 py-1 rounded text-xs font-bold text-slate-400"><?= $r['type'] ?></span>
                                            </td>
                                            <td class="p-3 font-mono text-slate-500 text-xs truncate max-w-md"><?= $r['value'] ?>
                                            </td>
                                            <td class="p-3 text-right">
                                                <button
                                                    onclick="deleteAction('delete_dns', 'id', <?= $r['id'] ?>, 'domain_id', <?= $d['id'] ?>)"
                                                    class="text-red-400 hover:text-red-500"><i data-lucide="trash-2"
                                                        class="w-4"></i></button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <?php if (!$has_records): ?>
                                        <tr>
                                            <td colspan="4" class="p-6 text-center text-slate-500 text-sm">
                                                <i data-lucide="database" class="w-6 h-6 mx-auto mb-2 opacity-50"></i>
                                                No DNS records found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($total_pages > 1): ?>
        <div class="flex justify-between items-center mt-6">
            <div class="text-xs text-slate-500 font-bold">
                Page <?= $page ?> of <?= $total_pages ?>
                <?php if ($search_query): ?>
                    (filtered)
                <?php endif; ?>
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>"
                        class="bg-slate-800 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-slate-700 transition">Previous</a>
                <?php endif; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>"
                        class="bg-slate-800 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-slate-700 transition">Next</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
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

    function toggleDomain(domainId) {
        const content = document.getElementById('domain-content-' + domainId);
        const chevron = document.getElementById('chevron-' + domainId);

        if (content.classList.contains('hidden')) {
            // Expand
            content.classList.remove('hidden');
            chevron.classList.remove('-rotate-90');
        } else {
            // Collapse
            content.classList.add('hidden');
            chevron.classList.add('-rotate-90');
        }
    }

    // Validate main domain (not subdomain)
    function isMainDomain(domain) {
        // Remove www. prefix if present for validation
        domain = domain.replace(/^www\./, '');
        // Split by dots
        const parts = domain.split('.');
        // Main domain has exactly 2+ parts and max 2 levels (domain.tld)
        // Subdomain has 3+ parts (sub.domain.tld)
        return parts.length === 2;
    }

    async function handleAddDomain(e) {
        e.preventDefault();
        const form = e.target;
        const domain = form.domain.value.trim().toLowerCase();

        if (!domain) {
            showToast('error', 'Validation Error', 'Please enter a domain name.');
            return;
        }

        // Validate it's a main domain, not a subdomain
        if (!isMainDomain(domain)) {
            showToast('error', 'Invalid Domain', 'Please enter a main domain (e.g., example.com). Subdomains should be created using the +Sub mode.');
            return;
        }

        const fd = new FormData();
        fd.append('ajax_action', 'add_domain');
        fd.append('domain', domain);
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        const btn = form.querySelector('button');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="animate-pulse">...</span>`;

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Domain Created', `Domain ${domain} created successfully.`);
                setTimeout(() => forceReload(), 1000);
            } else {
                showToast('error', 'Operation Failed', res.msg);
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        } catch (err) {
            showToast('error', 'System Error', 'Failed to create domain.');
            btn.disabled = false;
            btn.innerHTML = oldHtml;
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
        fd.append('parent_id', parent); // Pass parent domain for validation
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

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
        if (!confirm("Permanent Action: Are you sure? This will delete all related data including DNS records, traffic logs, scan history, and subdomains.")) return;
        const fd = new FormData();
        fd.append('ajax_action', action);
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
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

    async function fixDefaultPage(did) {
        if (!confirm("This will attempt to fix the 'Default Page' issue by hiding the placeholder page so your uploaded content can load. Continue?")) return;
        const fd = new FormData();
        fd.append('ajax_action', 'fix_default_page');
        fd.append('domain_id', did);
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Fixed', 'Default page hidden. Please clear your browser cache.');
            } else {
                showToast('error', 'Failed', res.msg || 'Could not fix default page.');
            }
        } catch (e) {
            showToast('error', 'Error', 'System error.');
        }
    }

    async function startScan(did) {
        if (!confirm("Start a comprehensive malware scan? This may take a few minutes.")) return;
        const fd = new FormData();
        fd.append('ajax_action', 'start_scan');
        fd.append('domain_id', did);
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Scan Started', 'The scan is running in background.');
                setTimeout(() => forceReload(), 2000);
            } else {
                showToast('error', res.msg);
            }
        } catch (e) { showToast('error', 'Network Error'); }
    }

    const dnsTemplates = {
        'A': `
            <div class="col-span-4"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Host</label><input name="host" value="@" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-8"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">IPv4 Address</label><input name="value" placeholder="192.168.1.1" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
        `,
        'AAAA': `
            <div class="col-span-4"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Host</label><input name="host" value="@" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-8"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">IPv6 Address</label><input name="value" placeholder="2001:0db8:..." class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
        `,
        'MX': `
            <div class="col-span-3"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Host</label><input name="host" value="@" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-3"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Priority</label><input name="priority" type="number" value="10" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-6"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Destination</label><input name="value" placeholder="mail.example.com" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
        `,
        'CNAME': `
            <div class="col-span-4"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Host</label><input name="host" placeholder="www" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-8"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Target</label><input name="value" placeholder="example.com" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
        `,
        'NS': `
            <div class="col-span-4"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Host</label><input name="host" value="@" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-8"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Nameserver</label><input name="value" placeholder="ns1.example.com" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
        `,
        'TXT': `
            <div class="col-span-4"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Host</label><input name="host" value="@" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-8"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">TXT Value</label><input name="value" placeholder="v=spf1..." class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
        `,
        'SRV': `
            <div class="col-span-3"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Service</label><input name="host" placeholder="_sip._tcp" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-2"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Priority</label><input name="priority" type="number" value="10" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-2"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Weight</label><input name="weight" type="number" value="10" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-2"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Port</label><input name="port" type="number" value="5060" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-3"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Target</label><input name="value" placeholder="sip.example.com" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
        `,
        'SOA': `
            <div class="col-span-4"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">MNAME</label><input name="mname" placeholder="ns1.example.com" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-4"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">RNAME</label><input name="rname" placeholder="admin.example.com" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-2"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Serial</label><input name="serial" placeholder="2024010101" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-2"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">TTL</label><input name="ttl" value="86400" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            
            <div class="col-span-2"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Refresh</label><input name="refresh" value="3600" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-2"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Retry</label><input name="retry" value="7200" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <div class="col-span-2"><label class="text-[10px] uppercase font-bold text-slate-500 mb-1 block">Expire</label><input name="expire" value="1209600" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg text-sm text-white outline-none focus:border-blue-500 shadow-inner" required></div>
            <input type="hidden" name="host" value="@">
        `
    };

    function setDnsType(did, type) {
        document.getElementById(`input-dns-type-${did}`).value = type;
        document.getElementById(`dns-fields-${did}`).innerHTML = dnsTemplates[type];

        // Update tabs
        const parent = document.getElementById(`dns-tabs-${did}`);
        parent.querySelectorAll('button').forEach(btn => {
            if (btn.id === `btn-dns-${type}-${did}`) {
                btn.className = "dns-type-btn px-4 py-2 rounded-lg text-xs font-bold border border-blue-500 bg-blue-600 text-white transition shadow-lg shadow-blue-500/20";
            } else {
                btn.className = "dns-type-btn px-4 py-2 rounded-lg text-xs font-bold border border-slate-700 bg-slate-800 text-slate-400 hover:bg-slate-700 transition";
            }
        });
    }
</script>