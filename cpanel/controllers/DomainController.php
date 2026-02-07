<?php
require_once __DIR__ . '/../../shared/config.php';

class DomainController
{
    private $pdo;
    private $cid;
    private $username;

    public function __construct()
    {
        global $pdo;
        $this->pdo = $pdo;

        if (!isset($_SESSION['client'])) {
            header("Location: login.php");
            exit;
        }
        $this->cid = $_SESSION['cid'];
        $this->username = $_SESSION['client'];
    }

    public function index()
    {
        // CSRF Token
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Pagination & Search
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        if ($page < 1)
            $page = 1;
        $per_page = 10;
        $offset = ($page - 1) * $per_page;
        $search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

        // Build search condition
        $search_condition = "";
        $search_params = [];
        if ($search_query) {
            $search_condition = "AND d.domain LIKE ?";
            $search_params[] = "%$search_query%";
        }

        // Count Total
        $total_stmt = $this->pdo->prepare("SELECT COUNT(*) FROM domains d WHERE d.client_id = ? $search_condition");
        $total_stmt->execute(array_merge([$this->cid], $search_params));
        $total_domains = $total_stmt->fetchColumn();
        $total_pages = ceil($total_domains / $per_page);

        // Fetch domains
        $domain_stmt = $this->pdo->prepare("
            SELECT d.*, 
            (SELECT bytes_sent FROM domain_traffic WHERE domain_id = d.id ORDER BY date DESC LIMIT 1) as traffic_today,
            (SELECT status FROM malware_scans WHERE domain_id = d.id ORDER BY scanned_at DESC LIMIT 1) as scan_status,
            (SELECT scanned_at FROM malware_scans WHERE domain_id = d.id ORDER BY scanned_at DESC LIMIT 1) as last_scan
            FROM domains d 
            WHERE d.client_id = ? $search_condition
            ORDER BY d.id DESC
            LIMIT $per_page OFFSET $offset
        ");
        $domain_stmt->execute(array_merge([$this->cid], $search_params));
        $domains = $domain_stmt->fetchAll();

        // Fetch ALL domains for subdomain dropdown
        $stmt = $this->pdo->prepare("SELECT id, domain FROM domains WHERE client_id = ? ORDER BY domain ASC");
        $stmt->execute([$this->cid]);
        $all_domains = $stmt->fetchAll();

        return [
            'domains' => $domains,
            'all_domains' => $all_domains,
            'total_pages' => $total_pages,
            'page' => $page,
            'search_query' => $search_query,
            'username' => $this->username
        ];
    }
}
