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
            
            // Check Parent Domain (If Subdomain)
            $parent_id = null;
            if (isset($_POST['parent_id'])) {
                // If explicitly passed (Select Box)
                $parent_name = $_POST['parent_id']; // This is domain string in current form
                // Let's resolve ID
                $get_p = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND client_id = ?");
                $get_p->execute([$parent_name, $cid]);
                $pid = $get_p->fetchColumn();
                if($pid) $parent_id = $pid;
            } 
            
            // Auto Detect if not passed?
            // "foo.bar.com" -> check if "bar.com" exists.
            if (!$parent_id) {
                $parts = explode('.', $dom);
                 if (count($parts) > 2) {
                     $possible_parent = implode('.', array_slice($parts, 1));
                     $get_p = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND client_id = ?");
                     $get_p->execute([$possible_parent, $cid]);
                     $pid = $get_p->fetchColumn();
                     if($pid) $parent_id = $pid;
                 }
            }

            try {
                $pdo->prepare("INSERT INTO domains (client_id, domain, document_root, parent_id) VALUES (?, ?, ?, ?)")->execute([$cid, $dom, "/var/www/clients/$username/domains/$dom/public_html", $parent_id]);
                $dom_id = $pdo->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    throw new Exception("Domain already exists (Database Constraint)");
                }
                throw $e;
            }

            $server_ip = $_SERVER['SERVER_ADDR'];

            if ($parent_id) {
                 // It IS a subdomain of a managed parent. 
                 // We do NOT create a new Zone. We add an A record to the PARENT.
                 $host = str_replace("." . $possible_parent, "", $dom); // e.g. "blog"
                 
                 // Add 'A' record to Parent
                 $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', ?, ?)")->execute([$parent_id, $host, $server_ip]);
                 
                 // Add 'www' CNAME (optional, maybe overkill for subdomains but user expectation varies. Let's start with just A/root of sub)
                 // $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'CNAME', ?, '@')")->execute([$parent_id, "www.$host"]);
                 
                 // Sync Parent DNS
                 cmd("dns-tool sync $parent_id");
                 
                 // Sync VHost (still needed for the sub)
                 cmd("shm-manage add-domain " . escapeshellarg($username) . " " . escapeshellarg($dom));
                 
            } else {
                // Standard Domain Logic
                // Auto DNS
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

                // Add NS Records
                $ns1 = "ns1." . $base_domain;
                $ns2 = "ns2." . $base_domain;
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'NS', '@', ?)")->execute([$dom_id, $ns1]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'NS', '@', ?)")->execute([$dom_id, $ns2]);
                
                // Syncs
                cmd("shm-manage add-domain " . escapeshellarg($username) . " " . escapeshellarg($dom));
                cmd("dns-tool sync $dom_id");
            }

            sendResponse($res);
            exit;
        }

        if ($action == 'delete_domain') {
            $dom_id = (int) $_POST['domain_id'];
            $d = $pdo->prepare("SELECT domain, parent_id FROM domains WHERE id=? AND client_id=?");
            $d->execute([$dom_id, $cid]);
            $dom_info = $d->fetch();
            
            if (!$dom_info)
                throw new Exception("Invalid Domain");
            
            $domain_name = $dom_info['domain'];
            $parent_id = $dom_info['parent_id'];

            if ($parent_id) {
                // Cleanup Parent DNS
                // Find parent name to strip subdomain
                $pd = $pdo->prepare("SELECT domain FROM domains WHERE id=?");
                $pd->execute([$parent_id]);
                $parent_name = $pd->fetchColumn();
                
                if ($parent_name) {
                    $host = str_replace("." . $parent_name, "", $domain_name);
                    $pdo->prepare("DELETE FROM dns_records WHERE domain_id=? AND host=? AND type='A'")->execute([$parent_id, $host]);
                    cmd("dns-tool sync $parent_id");
                }
            } else {
                 $pdo->prepare("DELETE FROM dns_records WHERE domain_id=?")->execute([$dom_id]);
            }
            
            $pdo->prepare("DELETE FROM php_config WHERE domain_id=?")->execute([$dom_id]);
            $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$dom_id]);

            cmd("shm-manage delete-domain " . escapeshellarg($username) . " " . escapeshellarg($domain_name));
            sendResponse($res);
            exit;
        }

        if ($action == 'update_domain_config') {
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
            cmd("vhost-tool sync " . $did);
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
                // Basic domain validation could be added here
                $value = $_POST['value'];
            } elseif ($type == 'TXT') {
                $value = $_POST['value'];
            } elseif ($type == 'MX') {
                $prio = (int) $_POST['priority'];
                $val = $_POST['value']; // Destination
                $value = "$prio $val";
            } elseif ($type == 'SRV') {
                $prio = (int) $_POST['priority'];
                $weight = (int) $_POST['weight'];
                $port = (int) $_POST['port'];
                $target = $_POST['value']; // Target
                $value = "$prio $weight $port $target";
            } elseif ($type == 'SOA') {
                // SOA is complex. Usually managed by system, but if manual:
                // MNAME RNAME SERIAL REFRESH RETRY EXPIRE MIN_TTL
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

            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, ?, ?, ?)")->execute([$dom_id, $type, $host, $value]);

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

        if ($action == 'start_scan') {
            $did = (int) $_POST['domain_id'];
            // Check ownership
            $chk = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $chk->execute([$did, $cid]);
            if (!$chk->fetch())
                throw new Exception("Access Denied");

            cmd("shm-manage malware-scan $did");
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

// Count Total
$total_domains = $pdo->query("SELECT COUNT(*) FROM domains WHERE client_id = $cid")->fetchColumn();
$total_pages = ceil($total_domains / $per_page);

$domains = $pdo->query("
    SELECT d.*, 
    (SELECT bytes_sent FROM domain_traffic WHERE domain_id = d.id ORDER BY date DESC LIMIT 1) as traffic_today,
    (SELECT status FROM malware_scans WHERE domain_id = d.id ORDER BY scanned_at DESC LIMIT 1) as scan_status,
    (SELECT scanned_at FROM malware_scans WHERE domain_id = d.id ORDER BY scanned_at DESC LIMIT 1) as last_scan
    FROM domains d 
    WHERE d.client_id = $cid 
    LIMIT $per_page OFFSET $offset
")->fetchAll();

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
                <!-- Traffic Badge -->
                <div class="absolute top-4 right-4 flex gap-2">
                    <div
                        class="bg-slate-900/80 backdrop-blur border border-slate-700 px-3 py-1 rounded-full text-[10px] font-bold text-slate-400 flex items-center gap-2">
                        <i data-lucide="activity" class="w-3 h-3 text-emerald-400"></i>
                        <?= $d['traffic_today'] ? round($d['traffic_today'] / 1024 / 1024, 2) . ' MB Today' : '0 MB Today' ?>
                    </div>
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
                <?php if ($d['parent_id']): ?>
                    <?php 
                        // Fetch Parent Name
                        $pname = $pdo->query("SELECT domain FROM domains WHERE id={$d['parent_id']}")->fetchColumn();
                    ?>
                    <div class="text-center p-8 bg-slate-900/30 rounded-xl border border-slate-800 border-dashed">
                        <i data-lucide="git-merge" class="w-8 h-8 text-slate-600 mx-auto mb-2"></i>
                        <p class="text-sm font-bold text-slate-400">DNS Managed by Parent Domain</p>
                        <p class="text-xs text-slate-600">This subdomain is a record of <span class="text-blue-400"><?= $pname ?></span></p>
                    </div>
                <?php else: ?>
                <h4 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-6">DNS Zone Management
                </h4>

                <!-- Security Section -->
                <div class="mb-8 p-6 bg-slate-900/30 rounded-xl border border-slate-800 flex justify-between items-center">
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

                <div class="mb-6">
                    <div class="flex flex-wrap gap-2 mb-4" id="dns-tabs-<?= $d['id'] ?>">
                        <?php foreach (['A', 'AAAA', 'MX', 'CNAME', 'NS', 'TXT', 'SRV', 'SOA'] as $t): ?>
                            <button type="button" onclick="setDnsType(<?= $d['id'] ?>, '<?= $t ?>')"
                                id="btn-dns-<?= $t ?>-<?= $d['id'] ?>"
                                class="dns-type-btn px-4 py-2 rounded-lg text-xs font-bold border border-slate-700 transition <?= $t === 'A' ? 'bg-blue-600 text-white border-blue-500' : 'bg-slate-800 text-slate-400 hover:bg-slate-700' ?>">
                                <?= $t ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <form onsubmit="handleGeneric(event, 'add_dns')"
                        class="glass-card p-6 border border-slate-700/50 bg-slate-900/30 rounded-xl relative overflow-hidden">
                        <div class="absolute top-0 left-0 w-1 h-full bg-blue-500"></div>
                        <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                        <input type="hidden" name="type" id="input-dns-type-<?= $d['id'] ?>" value="A">

                        <div id="dns-fields-<?= $d['id'] ?>" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                            <!-- Default A Record Fields -->
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

                        <div class="mt-6 flex justify-end">
                            <button
                                class="bg-blue-600 text-white px-6 py-3 rounded-xl font-bold text-xs uppercase shadow-xl hover:bg-blue-500 transition border border-blue-400 flex items-center gap-2">
                                <i data-lucide="plus-circle" class="w-4 h-4"></i> Add Record
                            </button>
                        </div>
                    </form>
                </div>

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
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

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

    async function startScan(did) {
        if (!confirm("Start a comprehensive malware scan? This may take a few minutes.")) return;
        const fd = new FormData();
        fd.append('ajax_action', 'start_scan');
        fd.append('domain_id', did);

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

    function filterDomains(query) {
        const lower = query.toLowerCase();
        const items = document.querySelectorAll('#domain-list > .glass-card');
        items.forEach(item => {
            const text = item.innerText.toLowerCase();
            item.style.display = text.includes(lower) ? '' : 'none';
        });
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