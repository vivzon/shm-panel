<?php
require_once __DIR__ . '/../../shared/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['client'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$cid = $_SESSION['cid'];
$username = $_SESSION['client'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; // Use 'action' key consistently, adapted from old 'ajax_action'

    // Fallback for old JS calls using 'ajax_action'
    if (empty($action) && isset($_POST['ajax_action'])) {
        $action = $_POST['ajax_action'];
    }

    $res = ['status' => 'success', 'msg' => 'Applied Successfully'];

    try {
        // CSRF Validation
        if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Security token mismatch. Please refresh.");
        }

        // --- Action: Add Domain ---
        if ($action == 'add_domain') {
            $stmt = $pdo->prepare("SELECT p.* FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = ?");
            $stmt->execute([$cid]);
            $limits = $stmt->fetch();

            $dom = strtolower(trim($_POST['domain']));
            if (!preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/', $dom))
                throw new Exception("Invalid Domain Name Format");

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM domains WHERE client_id = ?");
            $stmt->execute([$cid]);
            $curr = $stmt->fetchColumn();
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

            // Subdomain Checks
            $dom_for_validation = preg_replace('/^www\./', '', $dom);
            $dom_parts = explode('.', $dom_for_validation);
            $is_subdomain = (count($dom_parts) > 2);
            $parent_id = null;
            $explicit_parent = isset($_POST['parent_id']) ? trim($_POST['parent_id']) : '';

            if ($has_parent_id && $explicit_parent) {
                // Subdomain mode
                $get_p = $pdo->prepare("SELECT id FROM domains WHERE domain = ? AND client_id = ?");
                $get_p->execute([$explicit_parent, $cid]);
                $pid = $get_p->fetchColumn();
                if ($pid)
                    $parent_id = $pid;
            } elseif ($is_subdomain && !$explicit_parent) {
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
                if ($e->getCode() == 23000)
                    throw new Exception("Domain already exists (Database Constraint)");
                throw $e;
            }

            $server_ip = $_SERVER['SERVER_ADDR'];

            if ($has_parent_id && $parent_id) {
                // Subdomain Logic
                $host = str_replace("." . $explicit_parent, "", $dom);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', ?, ?)")->execute([$parent_id, $host, $server_ip]);
                cmd("dns-tool sync $parent_id");
                cmd("shm-manage add-domain " . escapeshellarg($username) . " " . escapeshellarg($dom));
                cmd("shm-manage vhost-tool sync $dom_id");
            } else {
                // Standard Domain Logic
                $host_parts = explode('.', $_SERVER['HTTP_HOST']);
                $base_domain = implode('.', array_slice($host_parts, -2));
                $mail_host = "mail." . $base_domain;

                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', '@', ?)")->execute([$dom_id, $server_ip]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'CNAME', 'www', '@')")->execute([$dom_id]);
                $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, 'A', 'mail', ?)")->execute([$dom_id, $server_ip]);
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

                // Syncs
                cmd("shm-manage add-domain " . escapeshellarg($username) . " " . escapeshellarg($dom));
                cmd("shm-manage vhost-tool sync $dom_id");
                cmd("dns-tool sync $dom_id");
            }

            echo json_encode($res);
            exit;
        }

        // --- Action: Delete Domain ---
        if ($action == 'delete_domain') {
            $dom_id = (int) $_POST['domain_id'];

            // Check parent_id col
            $has_parent_id = false;
            try {
                $check_col = $pdo->query("SHOW COLUMNS FROM domains LIKE 'parent_id'");
                $has_parent_id = ($check_col->rowCount() > 0);
            } catch (Exception $e) {
                $has_parent_id = false;
            }

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

            $pdo->beginTransaction();
            try {
                if ($parent_id) {
                    // Cleanup Parent DNS
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
                    if ($has_parent_id) {
                        $pdo->prepare("DELETE FROM dns_records WHERE domain_id IN (SELECT id FROM domains WHERE parent_id=?)")->execute([$dom_id]);
                    }
                }

                // Delete related records
                $pdo->prepare("DELETE FROM php_config WHERE domain_id=?")->execute([$dom_id]);
                $pdo->prepare("DELETE FROM domain_traffic WHERE domain_id=?")->execute([$dom_id]);
                $pdo->prepare("DELETE FROM malware_scans WHERE domain_id=?")->execute([$dom_id]);

                // Recursive Delete Subdomains
                if ($has_parent_id) {
                    $subdomains = $pdo->prepare("SELECT id FROM domains WHERE parent_id=?");
                    $subdomains->execute([$dom_id]);
                    while ($sub = $subdomains->fetch()) {
                        $sub_id = $sub['id'];
                        $pdo->prepare("DELETE FROM php_config WHERE domain_id=?")->execute([$sub_id]);
                        $pdo->prepare("DELETE FROM domain_traffic WHERE domain_id=?")->execute([$sub_id]);
                        $pdo->prepare("DELETE FROM malware_scans WHERE domain_id=?")->execute([$sub_id]);
                    }
                    $pdo->prepare("DELETE FROM domains WHERE parent_id=?")->execute([$dom_id]);
                }

                $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$dom_id]);
                $pdo->commit();

                cmd("shm-manage delete-domain " . escapeshellarg($username) . " " . escapeshellarg($domain_name));

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            echo json_encode($res);
            exit;
        }

        // --- Action: Update Config (PHP, SSL) ---
        if ($action == 'update_domain_config') {
            set_time_limit(300);
            $did = (int) $_POST['domain_id'];

            $chk = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $chk->execute([$did, $cid]);
            if (!$chk->fetch())
                throw new Exception("Invalid Domain ID");

            $pdo->prepare("UPDATE domains SET php_version = ?, ssl_active = ? WHERE id = ?")->execute([$_POST['php_version'], isset($_POST['ssl']) ? 1 : 0, $did]);

            $exists = $pdo->prepare("SELECT 1 FROM php_config WHERE domain_id = ?");
            $exists->execute([$did]);
            if ($exists->fetch()) {
                $pdo->prepare("UPDATE php_config SET memory_limit = ? WHERE domain_id = ?")->execute([$_POST['mem'], $did]);
            } else {
                try {
                    $pdo->prepare("INSERT INTO php_config (domain_id, memory_limit) VALUES (?, ?)")->execute([$did, $_POST['mem']]);
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $pdo->prepare("UPDATE php_config SET memory_limit = ? WHERE domain_id = ?")->execute([$_POST['mem'], $did]);
                    } else {
                        throw $e;
                    }
                }
            }

            if (function_exists('cmd')) {
                cmd("vhost-tool sync " . $did . " > /dev/null 2>&1 &");
            }
            echo json_encode($res);
            exit;
        }

        // --- Action: Add DNS ---
        if ($action == 'add_dns') {
            $dom_id = $_POST['domain_id'];
            $check = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $check->execute([$dom_id, $cid]);
            if (!$check->fetch())
                throw new Exception("Access Denied");

            $type = $_POST['type'];
            $host = $_POST['host'];
            $value = '';

            // Validation logic simplified for brevity but kept generally the same
            if ($type == 'A' && !filter_var($_POST['value'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
                throw new Exception("Invalid IPv4");
            if ($type == 'AAAA' && !filter_var($_POST['value'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
                throw new Exception("Invalid IPv6");

            $value = $_POST['value']; // Default
            if ($type == 'MX')
                $value = (int) $_POST['priority'] . " " . $_POST['value'];
            if ($type == 'SRV')
                $value = (int) $_POST['priority'] . " " . (int) $_POST['weight'] . " " . (int) $_POST['port'] . " " . $_POST['value'];
            if ($type == 'SOA')
                $value = "{$_POST['mname']} {$_POST['rname']} {$_POST['serial']} {$_POST['refresh']} {$_POST['retry']} {$_POST['expire']} {$_POST['ttl']}";

            $pdo->prepare("INSERT INTO dns_records (domain_id, type, host, value) VALUES (?, ?, ?, ?)")->execute([$dom_id, $type, $host, $value]);

            cmd("dns-tool sync " . (int) $dom_id);
            echo json_encode($res);
            exit;
        }

        // --- Action: Delete DNS ---
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

        // --- Action: Start Scan ---
        if ($action == 'start_scan') {
            $did = (int) $_POST['domain_id'];
            $chk = $pdo->prepare("SELECT id FROM domains WHERE id = ? AND client_id = ?");
            $chk->execute([$did, $cid]);
            if (!$chk->fetch())
                throw new Exception("Access Denied");

            cmd("shm-manage malware-scan $did");
            echo json_encode($res);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}
