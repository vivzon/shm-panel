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

    ob_start();

    // CSRF Protection
    try {
        verify_csrf();
    } catch (Exception $e) {
        ob_end_clean();
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

            $out = ob_get_clean();
            if (!empty(trim($out))) {
                throw new Exception("PHP Output Error: " . strip_tags($out));
            }
            echo json_encode($res);
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

            echo json_encode($res);
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
            echo json_encode($res);
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
            echo json_encode($res);
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
            echo json_encode($res);
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
            echo json_encode($res);
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
            echo json_encode($res);
            exit;
        }

        if ($action == 'toggle_maintenance') {
            $did = (int) $_POST['domain_id'];
            $status = $_POST['status'] === 'on' ? 'on' : 'off';

            $chk = $pdo->prepare("SELECT domain FROM domains WHERE id = ? AND client_id = ?");
            $chk->execute([$did, $cid]);
            $d_info = $chk->fetch();
            if (!$d_info)
                throw new Exception("Access Denied");

            cmd("maintenance $status " . escapeshellarg($d_info['domain']));
            echo json_encode($res);
            exit;
        }

    } catch (Throwable $e) {
        $out = ob_get_clean();
        // Return 200 so Nginx doesn't intercept it with 50x.html
        // http_response_code(500);
        $msg = $e->getMessage();
        if (!empty(trim($out))) {
            $msg .= " | Output: " . strip_tags($out);
        }
        $msg = mb_convert_encoding($msg, 'UTF-8', 'auto');
        echo json_encode(['status' => 'error', 'msg' => $msg]);
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

<div
    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
    <div style="display: flex; align-items: center; gap: 1rem;">
        <h2 style="font-size: 1.5rem; font-weight: 500; color: var(--slate-900);">Domain Management</h2>
        <form method="GET" style="position: relative;">
            <i data-lucide="search"
                style="width: 16px; height: 16px; position: absolute; left: 0.75rem; top: 0.75rem; color: var(--slate-400);"></i>
            <input name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search domains..."
                class="form-input" style="padding-left: 2.5rem; width: 12rem; transition: width 0.2s;"
                onfocus="this.style.width='16rem'" onblur="this.style.width='12rem'">
        </form>
        <?php if ($search_query): ?>
            <a href="?" style="font-size: 0.75rem; color: var(--slate-500); text-decoration: none;">Clear</a>
        <?php endif; ?>
    </div>
    <div style="display: flex; gap: 1rem;">
        <!-- Add Domain - Only main domains allowed (no subdomains) -->
        <form onsubmit="handleAddDomain(event)" style="display: flex; gap: 0.5rem;" id="form-add-domain">
            <?= csrf_field() ?>
            <input name="domain" required placeholder="example.com" class="form-input" style="width: 12rem;">
            <button class="btn btn-secondary">
                + Domain</button>
        </form>

        <!-- Subdomain - Select from all domains -->
        <form onsubmit="handleAddSubdomain(event)" style="display: none; gap: 0.5rem;" id="form-add-subdomain">
            <?= csrf_field() ?>
            <input name="sub" required placeholder="sub (e.g. blog)" class="form-input"
                style="width: 8rem; text-align: right;">
            <span style="align-self: center; font-weight: 500; color: var(--slate-700);">.</span>
            <select name="parent_id" class="form-select" style="width: 10rem;">
                <?php foreach ($all_domains as $d): ?>
                    <option value="<?= $d['domain'] ?>">
                        <?= $d['domain'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary">
                + Sub</button>
        </form>

        <button onclick="toggleDomainMode()"
            style="padding: 0.75rem; background: var(--bg-card); color: var(--slate-700); border: 1px solid var(--border-color); border-radius: var(--radius-md); cursor: pointer; transition: all 0.2s;"
            title="Toggle Subdomain Mode"
            onmouseover="this.style.backgroundColor='var(--slate-100)'; this.style.color='var(--slate-900)';"
            onmouseout="this.style.backgroundColor='var(--bg-card)'; this.style.color='var(--slate-700)';">
            <i data-lucide="shuffle" style="width: 16px; height: 16px;"></i>
        </button>
    </div>
</div>
<div id="domain-list">

    <?php if (count($domains) === 0): ?>
        <div class="glass-card" style="padding: 2.5rem; text-align: center;">
            <i data-lucide="globe" style="width: 3rem; height: 3rem; color: var(--slate-700); margin: 0 auto 1rem;"></i>
            <h3 style="font-size: 1.125rem; font-weight: 500; color: var(--slate-700);">No domains found</h3>
            <p style="font-size: 0.875rem; color: var(--slate-700); margin-top: 0.5rem;">
                <?= $search_query ? 'Try a different search term' : 'Add your first domain to get started' ?>
            </p>
        </div>
    <?php endif; ?>

    <?php foreach ($domains as $index => $d):
        $is_first = ($index === 0);
        $domain_id = $d['id'];
        ?>
        <div class="glass-card domain-card" data-domain-id="<?= $domain_id ?>"
            style="margin-bottom: 2rem; border-color: transparent;">
            <!-- Domain Header - Always Visible -->
            <div class="domain-header"
                style="padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; cursor: pointer; border-radius: var(--radius-lg); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); background: var(--bg-surface);"
                onmouseover="this.style.backgroundColor='var(--bg-body)'; this.style.boxShadow='0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -1px rgba(0, 0, 0, 0.04)';"
                onmouseout="this.style.backgroundColor='var(--bg-surface)'; this.style.boxShadow='none';"
                onclick="toggleDomain(<?= $domain_id ?>)">
                <div style="display: flex; align-items: center; gap: 1.25rem;">
                    <div
                        style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, rgba(37,99,235,0.1) 0%, rgba(37,99,235,0.05) 100%); display: flex; align-items: center; justify-content: center; box-shadow: inset 0 2px 4px rgba(255,255,255,0.5);">
                        <i data-lucide="globe" style="width: 20px; height: 20px; color: var(--primary);"></i>
                    </div>
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <h3
                                style="font-size: 1.25rem; font-weight: 800; color: var(--slate-900); font-family: var(--font-heading); display: flex; align-items: center; gap: 0.5rem;">
                                <?= htmlspecialchars($d['domain']) ?>
                                <?php if ($is_first): ?>
                                    <span class="badge"
                                        style="background: rgba(37, 99, 235, 0.1); color: var(--primary); font-size: 0.625rem; padding: 0.125rem 0.375rem; border: 1px solid rgba(37, 99, 235, 0.2);">Primary</span>
                                <?php endif; ?>
                            </h3>
                            <i data-lucide="chevron-down" id="chevron-<?= $domain_id ?>"
                                style="width: 18px; height: 18px; color: var(--slate-400); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); transform: <?= $is_first ? 'rotate(0)' : 'rotate(-90deg)' ?>;"></i>
                        </div>
                        <p
                            style="font-size: 0.8125rem; color: var(--slate-500); font-family: 'JetBrains Mono', monospace; margin-top: 0.375rem; display: flex; align-items: center; gap: 0.375rem;">
                            <i data-lucide="folder" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                            /home/<?= $username ?>/public_html
                        </p>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 1.5rem;">
                    <!-- Quick Stats -->
                    <div style="display: flex; gap: 0.75rem;">
                        <div class="badge badge-emerald"
                            style="padding: 0.375rem 0.625rem; border-radius: 9999px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            <i data-lucide="activity" style="width: 12px; height: 12px;"></i>
                            <span
                                style="font-weight: 500;"><?= $d['traffic_today'] ? round($d['traffic_today'] / 1024 / 1024, 2) . ' MB' : '0 MB' ?></span>
                        </div>
                        <?php if ($d['ssl_active']): ?>
                            <div class="badge badge-emerald"
                                style="padding: 0.375rem 0.625rem; border-radius: 9999px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <i data-lucide="lock" style="width: 12px; height: 12px;"></i> <span
                                    style="font-weight: 500;">SSL</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($d['scan_status'] == 'clean'): ?>
                            <div class="badge badge-emerald"
                                style="padding: 0.375rem 0.625rem; border-radius: 9999px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <i data-lucide="shield-check" style="width: 12px; height: 12px;"></i> <span
                                    style="font-weight: 500;">Clean</span>
                            </div>
                        <?php elseif ($d['scan_status'] == 'infected'): ?>
                            <div class="badge badge-red"
                                style="padding: 0.375rem 0.625rem; border-radius: 9999px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <i data-lucide="shield-alert" style="width: 12px; height: 12px;"></i> <span
                                    style="font-weight: 500;">Infected</span>
                            </div>
                        <?php elseif ($d['scan_status'] == 'running'): ?>
                            <div class="badge badge-blue"
                                style="padding: 0.375rem 0.625rem; border-radius: 9999px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <i data-lucide="loader-2" style="width: 12px; height: 12px;" class="animate-spin"></i> <span
                                    style="font-weight: 500;">Scanning</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Quick Actions -->
                    <div style="display: flex; gap: 0.5rem;" onclick="event.stopPropagation()">
                        <a href="files.php?domain_id=<?= $d['id'] ?>&path=/" target="_blank"
                            style="padding: 0.5rem; color: var(--primary); background: rgba(37, 99, 235, 0.1); border: 1px solid rgba(37, 99, 235, 0.2); border-radius: var(--radius-md); transition: all 0.2s; display: flex; align-items: center; justify-content: center;"
                            title="File Manager"
                            onmouseover="this.style.backgroundColor='rgba(37, 99, 235, 0.2)'; this.style.borderColor='rgba(37, 99, 235, 0.3)';"
                            onmouseout="this.style.backgroundColor='rgba(37, 99, 235, 0.1)'; this.style.borderColor='rgba(37, 99, 235, 0.2)';">
                            <i data-lucide="folder-open" style="width: 16px; height: 16px;"></i>
                        </a>
                        <button onclick="fixDefaultPage(<?= $d['id'] ?>)"
                            style="padding: 0.5rem; color: var(--accent-orange); background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: var(--radius-md); transition: all 0.2s; cursor: pointer; display: flex; align-items: center; justify-content: center;"
                            title="Fix Default Page Issue"
                            onmouseover="this.style.backgroundColor='rgba(245, 158, 11, 0.2)'; this.style.borderColor='rgba(245, 158, 11, 0.3)';"
                            onmouseout="this.style.backgroundColor='rgba(245, 158, 11, 0.1)'; this.style.borderColor='rgba(245, 158, 11, 0.2)';">
                            <i data-lucide="wrench" style="width: 16px; height: 16px;"></i>
                        </button>
                        <button onclick="deleteAction('delete_domain', 'domain_id', <?= $d['id'] ?>)"
                            style="padding: 0.5rem; color: var(--accent-red); background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius-md); transition: all 0.2s; cursor: pointer; display: flex; align-items: center; justify-content: center;"
                            title="Delete Domain"
                            onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.2)'; this.style.borderColor='rgba(239, 68, 68, 0.3)';"
                            onmouseout="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'; this.style.borderColor='rgba(239, 68, 68, 0.2)';">
                            <i data-lucide="trash-2" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Domain Content - Collapsible -->
            <div id="domain-content-<?= $domain_id ?>" class="domain-content <?= $is_first ? '' : 'hidden' ?>"
                style="border-top: 1px solid var(--slate-200);">
                <div style="padding: 1.25rem;">
                    <!-- Configuration Row -->
                    <form onsubmit="handleGeneric(event, 'update_domain_config')" class="setup-form"
                        style="display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; padding: 1rem; margin-bottom: 1.5rem; background: var(--bg-body); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <label
                                style="font-size: 0.625rem; font-weight: 800; color: var(--slate-500); text-transform: uppercase; letter-spacing: 0.05em;">PHP</label>
                            <select name="php_version" class="form-select"
                                style="padding: 0.5rem 2rem 0.5rem 0.75rem; font-size: 0.8125rem; font-weight: 500; color: var(--text-primary); background-color: var(--bg-surface); border-color: var(--border-color); cursor: pointer; border-radius: var(--radius-sm); outline: none; transition: border-color 0.2s, box-shadow 0.2s;"
                                onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                                onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                                <option value="8.1" <?= $d['php_version'] == '8.1' ? 'selected' : '' ?>>8.1</option>
                                <option value="8.2" <?= $d['php_version'] == '8.2' ? 'selected' : '' ?>>8.2</option>
                                <option value="8.3" <?= $d['php_version'] == '8.3' ? 'selected' : '' ?>>8.3</option>
                            </select>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <label
                                style="font-size: 0.625rem; font-weight: 800; color: var(--slate-500); text-transform: uppercase; letter-spacing: 0.05em;">Memory</label>
                            <select name="mem" class="form-select"
                                style="padding: 0.5rem 2rem 0.5rem 0.75rem; font-size: 0.8125rem; font-weight: 500; color: var(--text-primary); background-color: var(--bg-surface); border-color: var(--border-color); cursor: pointer; border-radius: var(--radius-sm); outline: none; transition: border-color 0.2s, box-shadow 0.2s;"
                                onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 3px rgba(37, 99, 235, 0.1)';"
                                onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
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
                        <div
                            style="display: flex; align-items: center; gap: 0.5rem; padding: 0 1rem; border-left: 1px solid rgba(203, 213, 225, 0.5);">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="ssl" <?= $d['ssl_active'] ? 'checked' : '' ?>
                                    style="width: 16px; height: 16px; accent-color: var(--accent-emerald); cursor: pointer;">
                                <span style="font-size: 0.75rem; font-weight: 500; color: var(--slate-700);">AutoSSL</span>
                            </label>
                        </div>
                        <?php $is_maint = file_exists("/etc/nginx/sites-available/{$d['domain']}.backup"); ?>
                        <div
                            style="display: flex; align-items: center; gap: 0.5rem; padding: 0 1rem; border-left: 1px solid rgba(203, 213, 225, 0.5);">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" onchange="toggleMaintenance(event, <?= $d['id'] ?>, this.checked)"
                                    <?= $is_maint ? 'checked' : '' ?>
                                    style="width: 16px; height: 16px; accent-color: var(--accent-orange); cursor: pointer;">
                                <span
                                    style="font-size: 0.75rem; font-weight: 500; color: var(--slate-700);">Maintenance</span>
                            </label>
                        </div>
                        <button class="btn btn-primary"
                            style="margin-left: auto; padding: 0.5rem 1rem; font-size: 0.75rem;">
                            <i data-lucide="save" style="width: 16px; height: 16px;"></i> Save
                        </button>
                    </form>

                    <?php if (isset($d['parent_id']) && $d['parent_id']): ?>
                        <?php
                        $pname = $pdo->query("SELECT domain FROM domains WHERE id={$d['parent_id']}")->fetchColumn();
                        ?>
                        <div
                            style="text-align: center; padding: 2rem; background: var(--bg-body); border-radius: 0.75rem; border: 1px dashed var(--border-color);">
                            <i data-lucide="git-merge"
                                style="width: 2rem; height: 2rem; color: var(--slate-700); margin: 0 auto 0.5rem;"></i>
                            <p style="font-size: 0.875rem; font-weight: 500; color: var(--slate-700);">DNS Managed by Parent
                                Domain</p>
                            <p style="font-size: 0.75rem; color: var(--slate-700);">This subdomain is a record of <span
                                    style="color: #60a5fa;"><?= $pname ?></span></p>
                        </div>
                    <?php else: ?>
                        <h4
                            style="font-size: 0.75rem; font-weight: 900; color: var(--slate-700); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1rem;">
                            DNS Zone Management</h4>

                        <!-- Security Section -->
                        <div class="glass-card"
                            style="margin-bottom: 1.5rem; padding: 1rem; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h4
                                    style="color: var(--slate-900); font-weight: 500; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem;">
                                    <i data-lucide="shield" style="width: 16px; height: 16px; color: #a855f7;"></i> Malware
                                    Protection
                                </h4>
                                <p style="font-size: 0.625rem; color: var(--slate-700); margin-top: 0.25rem;">Status:
                                    <?php if ($d['scan_status'] == 'clean'): ?>
                                        <span style="color: var(--accent-emerald);">Clean</span>
                                    <?php elseif ($d['scan_status'] == 'infected'): ?>
                                        <span style="color: var(--accent-red); animation: blink 1s infinite;">Infected!</span>
                                    <?php elseif ($d['scan_status'] == 'running'): ?>
                                        <span class="animate-pulse" style="color: var(--primary);">Scanning...</span>
                                    <?php else: ?>
                                        <span style="color: var(--slate-700);">Not Scanned</span>
                                    <?php endif; ?>
                                    <?php if ($d['last_scan']): ?>
                                        <span style="opacity: 0.5; margin-left: 0.5rem;">Last: <?= $d['last_scan'] ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <button onclick="startScan(<?= $d['id'] ?>)" class="btn btn-secondary"
                                style="color: #a855f7; border-color: rgba(168, 85, 247, 0.2); background: rgba(168, 85, 247, 0.05);">Run
                                Scan</button>
                        </div>

                        <!-- DNS Tabs -->
                        <div style="margin-bottom: 1rem;">
                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem;"
                                id="dns-tabs-<?= $d['id'] ?>">
                                <?php foreach (['A', 'AAAA', 'MX', 'CNAME', 'NS', 'TXT', 'SRV', 'SOA'] as $t): ?>
                                    <button type="button" onclick="setDnsType(<?= $d['id'] ?>, '<?= $t ?>')"
                                        id="btn-dns-<?= $t ?>-<?= $d['id'] ?>"
                                        class="dns-type-btn <?= $t === 'A' ? 'btn-primary' : 'btn-secondary' ?>"
                                        style="padding: 0.5rem 1rem; border-radius: var(--radius-md); font-size: 0.75rem; font-weight: 500; transition: all 0.2s; border: 1px solid <?= $t === 'A' ? 'var(--primary)' : 'var(--slate-300)' ?>;">
                                        <?= $t ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <!-- Add DNS Form -->
                            <form onsubmit="handleGeneric(event, 'add_dns')" class="glass-card"
                                style="padding: 1.25rem; position: relative; overflow: hidden; margin-bottom: 1.5rem;">
                                <div
                                    style="position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--primary);">
                                </div>
                                <?= csrf_field() ?>
                                <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="type" id="input-dns-type-<?= $d['id'] ?>" value="A">

                                <div id="dns-fields-<?= $d['id'] ?>"
                                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: flex-end;">
                                    <div>
                                        <label
                                            style="font-size: 0.625rem; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Host</label>
                                        <input name="host" value="@" class="form-input">
                                    </div>
                                    <div style="grid-column: span 2 / span 2;">
                                        <label
                                            style="font-size: 0.625rem; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">IPv4
                                            Address</label>
                                        <input name="value" placeholder="192.168.1.1" class="form-input">
                                    </div>
                                </div>

                                <div style="margin-top: 1rem; display: flex; justify-content: flex-end;">
                                    <button class="btn btn-primary"
                                        style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1.25rem;">
                                        <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i> Add Record
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- DNS Records Table -->
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Host</th>
                                        <th>Type</th>
                                        <th>Value</th>
                                        <th style="text-align: right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $recs = $pdo->prepare("SELECT * FROM dns_records WHERE domain_id = ?");
                                    $recs->execute([$d['id']]);
                                    $has_records = false;
                                    while ($r = $recs->fetch()):
                                        $has_records = true;
                                        ?>
                                        <tr>
                                            <td style="font-weight: 500; color: var(--slate-900);"><?= $r['name'] ?></td>
                                            <td><span class="badge"
                                                    style="background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-secondary);"><?= $r['type'] ?></span>
                                            </td>
                                            <td
                                                style="font-family: monospace; color: var(--slate-700); font-size: 0.75rem; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?= $r['value'] ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <button
                                                    onclick="deleteAction('delete_dns', 'id', <?= $r['id'] ?>, 'domain_id', <?= $d['id'] ?>)"
                                                    style="background: transparent; border: none; cursor: pointer; color: var(--accent-red); opacity: 0.7; transition: opacity 0.2s;"
                                                    onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'"><i
                                                        data-lucide="trash-2" style="width: 16px; height: 16px;"></i></button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <?php if (!$has_records): ?>
                                        <tr>
                                            <td colspan="4"
                                                style="padding: 2rem; text-align: center; color: var(--slate-500); font-size: 0.875rem;">
                                                <i data-lucide="database"
                                                    style="width: 24px; height: 24px; margin: 0 auto 0.5rem; opacity: 0.5;"></i>
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
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2rem;">
            <div style="font-size: 0.75rem; color: var(--slate-700); font-weight: 500;">
                Page <?= $page ?> of <?= $total_pages ?>
                <?php if ($search_query): ?>
                    (filtered)
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>"
                        class="btn btn-secondary" style="font-size: 0.75rem;">Previous</a>
                <?php endif; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>"
                        class="btn btn-secondary" style="font-size: 0.75rem;">Next</a>
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

    async function toggleMaintenance(e, did, isChecked) {
        e.preventDefault();
        if (!confirm(`Are you sure you want to turn maintenance mode ${isChecked ? 'ON' : 'OFF'}?`)) {
            e.target.checked = !isChecked;
            return;
        }

        const fd = new FormData();
        fd.append('ajax_action', 'toggle_maintenance');
        fd.append('domain_id', did);
        fd.append('status', isChecked ? 'on' : 'off');
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        try {
            const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                showToast('success', 'Maintenance Mode', `Maintenance mode is now ${isChecked ? 'ON' : 'OFF'}.`);
            } else {
                showToast('error', 'Failed', res.msg || 'Could not toggle maintenance mode.');
                e.target.checked = !isChecked; // Revert
            }
        } catch (err) {
            showToast('error', 'Error', 'System error.');
            e.target.checked = !isChecked; // Revert
        }
    }

    const dnsTemplates = {
        'A': `
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Host</label><input name="host" value="@" class="form-input" style="width: 100%;" required></div>
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">IPv4 Address</label><input name="value" placeholder="192.168.1.1" class="form-input" style="width: 100%;" required></div>
        `,
        'AAAA': `
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Host</label><input name="host" value="@" class="form-input" style="width: 100%;" required></div>
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">IPv6 Address</label><input name="value" placeholder="2001:0db8:..." class="form-input" style="width: 100%;" required></div>
        `,
        'MX': `
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Host</label><input name="host" value="@" class="form-input" style="width: 100%;" required></div>
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Priority</label><input name="priority" type="number" value="10" class="form-input" style="width: 100%;" required></div>
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Destination</label><input name="value" placeholder="mail.example.com" class="form-input" style="width: 100%;" required></div>
        `,
        'CNAME': `
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Host</label><input name="host" placeholder="www" class="form-input" style="width: 100%;" required></div>
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Target</label><input name="value" placeholder="example.com" class="form-input" style="width: 100%;" required></div>
        `,
        'NS': `
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Host</label><input name="host" value="@" class="form-input" style="width: 100%;" required></div>
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Nameserver</label><input name="value" placeholder="ns1.example.com" class="form-input" style="width: 100%;" required></div>
        `,
        'TXT': `
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Host</label><input name="host" value="@" class="form-input" style="width: 100%;" required></div>
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">TXT Value</label><input name="value" placeholder="v=spf1..." class="form-input" style="width: 100%;" required></div>
        `,
        'SRV': `
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Service</label><input name="host" placeholder="_sip._tcp" class="form-input" style="width: 100%;" required></div>
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Priority</label><input name="priority" type="number" value="10" class="form-input" style="width: 100%;" required></div>
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Weight</label><input name="weight" type="number" value="10" class="form-input" style="width: 100%;" required></div>
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Port</label><input name="port" type="number" value="5060" class="form-input" style="width: 100%;" required></div>
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Target</label><input name="value" placeholder="sip.example.com" class="form-input" style="width: 100%;" required></div>
        `,
        'SOA': `
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">MNAME</label><input name="mname" placeholder="ns1.example.com" class="form-input" style="width: 100%;" required></div>
            <div style="grid-column: span 2 / span 2;"><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">RNAME</label><input name="rname" placeholder="admin.example.com" class="form-input" style="width: 100%;" required></div>
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Serial</label><input name="serial" placeholder="2024010101" class="form-input" style="width: 100%;" required></div>
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">TTL</label><input name="ttl" value="86400" class="form-input" style="width: 100%;" required></div>
            
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Refresh</label><input name="refresh" value="3600" class="form-input" style="width: 100%;" required></div>
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Retry</label><input name="retry" value="7200" class="form-input" style="width: 100%;" required></div>
            <div><label style="font-size: 10px; text-transform: uppercase; font-weight: 500; color: var(--slate-700); margin-bottom: 0.25rem; display: block;">Expire</label><input name="expire" value="1209600" class="form-input" style="width: 100%;" required></div>
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
                btn.className = "dns-type-btn btn-primary";
                btn.style.border = "1px solid var(--primary)";
            } else {
                btn.className = "dns-type-btn btn-secondary";
                btn.style.border = "1px solid var(--slate-300)";
            }
        });
    }
</script>