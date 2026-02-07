<?php
require_once __DIR__ . '/../../shared/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

/**
 * HELPER: Fetch Clients
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

    $stCount = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM clients c LEFT JOIN domains d ON c.id = d.client_id $where");
    $stCount->execute($params);
    $total = $stCount->fetchColumn();

    $sql = "SELECT c.*, d.domain, d.id as domain_id, p.name as pkg_name 
            FROM clients c 
            LEFT JOIN domains d ON d.id = (SELECT id FROM domains WHERE client_id = c.id ORDER BY d.id ASC LIMIT 1)
            LEFT JOIN packages p ON c.package_id = p.id 
            $where 
            GROUP BY c.id 
            ORDER BY c.id DESC LIMIT $limit OFFSET $offset";

    $stData = $pdo->prepare($sql);
    $stData->execute($params);
    $rows = $stData->fetchAll(PDO::FETCH_ASSOC);

    return ['rows' => $rows, 'total' => (int) $total, 'pages' => ceil($total / $limit)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (empty($action) && isset($_POST['ajax_action'])) {
        $action = $_POST['ajax_action'];
    }

    $res = ['status' => 'success', 'msg' => 'Action processed'];

    try {
        // --- Search Clients ---
        if ($action == 'search_clients') {
            echo json_encode(getClientsData($pdo, $_POST['query'] ?? '', (int) ($_POST['page'] ?? 1)));
            exit;
        }

        // --- Save Account (Create/Edit) ---
        if ($action == 'save_account') {
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $u = trim($_POST['user']);
            $d = trim($_POST['dom']);
            $e = trim($_POST['email']);
            $pkg = (int) $_POST['package_id'];

            if ($id) {
                // Update
                $oldSt = $pdo->prepare("SELECT c.*, d.domain, d.id as domain_id FROM clients c LEFT JOIN domains d ON d.id = (SELECT id FROM domains WHERE client_id = c.id LIMIT 1) WHERE c.id = ?");
                $oldSt->execute([$id]);
                $curr = $oldSt->fetch(PDO::FETCH_ASSOC);

                if ($curr['email'] !== $e || (int) $curr['package_id'] !== $pkg) {
                    $pdo->prepare("UPDATE clients SET email=?, package_id=? WHERE id=?")->execute([$e, $pkg, $id]);
                }
                if ($curr['domain'] !== $d) {
                    $pdo->prepare("UPDATE domains SET domain=? WHERE id=?")->execute([$d, $curr['domain_id']]);
                    $pdo->prepare("UPDATE mail_domains SET domain=? WHERE domain=?")->execute([$d, $curr['domain']]);
                }
                if (!empty($_POST['pass'])) {
                    $hash = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE clients SET password=? WHERE id=?")->execute([$hash, $id]);
                }
            } else {
                // Create
                $pdo->beginTransaction();
                $hash = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO clients (username, email, password, package_id, status) VALUES (?,?,?,?, 'active')")->execute([$u, $e, $hash, $pkg]);
                $cid = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO domains (client_id, domain, document_root) VALUES (?,?,?)")->execute([$cid, $d, "/var/www/clients/$u/public_html"]);
                $dom_id = $pdo->lastInsertId();

                $pdo->prepare("INSERT INTO mail_domains (domain) VALUES (?)")->execute([$d]);

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

        // --- Delete Account ---
        if ($action == 'delete_account') {
            $id = (int) $_POST['id'];
            $user = $_POST['user'];

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

        // --- Suspend Account ---
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

        // --- Reset Account ---
        if ($action == 'reset_account') {
            $user = $_POST['user'];
            echo json_encode($res);
            if (function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();
            cmd("reset-account " . escapeshellarg($user));
            exit;
        }

        // --- Login As Client ---
        if ($action == 'login_as_client') {
            $_SESSION['client'] = $_POST['user'];
            $_SESSION['cid'] = $_POST['cid'];
            $host = str_replace('admin.', 'client.', $_SERVER['HTTP_HOST']);
            // If already on client/admin split by subdir vs subdomain, adjust accordingly. 
            // Assuming config-based redirect.
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
