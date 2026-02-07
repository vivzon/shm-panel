<?php
require_once __DIR__ . '/../../shared/config.php';

class DatabaseController
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

        // Pagination
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        if ($page < 1)
            $page = 1;
        $per_page = 10;
        $offset = ($page - 1) * $per_page;

        // Count Total
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM client_databases WHERE client_id = ?");
        $stmt->execute([$this->cid]);
        $total_dbs = $stmt->fetchColumn();
        $total_pages = ceil($total_dbs / $per_page);

        // Fetch Databases
        $stmt = $this->pdo->prepare("SELECT cd.*, d.domain FROM client_databases cd LEFT JOIN domains d ON cd.domain_id = d.id WHERE cd.client_id = ? ORDER BY cd.id DESC LIMIT $per_page OFFSET $offset");
        $stmt->execute([$this->cid]);
        $my_dbs = $stmt->fetchAll();

        // Fetch DB Users
        $db_users_stmt = $this->pdo->prepare("SELECT * FROM client_db_users WHERE client_id = ?");
        $db_users_stmt->execute([$this->cid]);
        $db_users = $db_users_stmt->fetchAll();

        // Fetch Domains (for dropdown)
        $domain_stmt = $this->pdo->prepare("SELECT * FROM domains WHERE client_id = ?");
        $domain_stmt->execute([$this->cid]);
        $domains = $domain_stmt->fetchAll();

        $base_domain = implode('.', array_slice(explode('.', $_SERVER['HTTP_HOST']), -2));

        return [
            'my_dbs' => $my_dbs,
            'db_users' => $db_users,
            'domains' => $domains,
            'total_pages' => $total_pages,
            'page' => $page,
            'base_domain' => $base_domain,
            'username' => $this->username
        ];
    }
}
