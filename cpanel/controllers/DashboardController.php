<?php
require_once __DIR__ . '/../../shared/config.php';

class DashboardController
{
    private $pdo;
    private $cid;
    private $username;

    public function __construct($pdo, $cid, $username)
    {
        $this->pdo = $pdo;
        $this->cid = $cid;
        $this->username = $username;
    }

    public function index()
    {
        // 1. Security: CSRF Token
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // 2. Fetch Client Data & Package Info
        $stmt = $this->pdo->prepare("SELECT c.*, p.name as pkg_name, p.max_emails, p.max_databases, p.max_domains, p.disk_mb FROM clients c JOIN packages p ON c.package_id = p.id WHERE c.id = ?");
        $stmt->execute([$this->cid]);
        $clientData = $stmt->fetch();

        $stmt = $this->pdo->prepare("SELECT * FROM domains WHERE client_id = ?");
        $stmt->execute([$this->cid]);
        $domains = $stmt->fetchAll();

        // 3. Fetch Usage Stats
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM client_databases WHERE client_id = ?");
            $stmt->execute([$this->cid]);
            $usage_db = $stmt->fetchColumn();
        } catch (Exception $e) {
            $usage_db = 0;
        }

        $usage_dom = count($domains);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM mail_users WHERE domain_id IN (SELECT id FROM mail_domains WHERE domain IN (SELECT domain FROM domains WHERE client_id = ?))");
        $stmt->execute([$this->cid]);
        $usage_mail = $stmt->fetchColumn();

        // 4. Fetch Traffic Data (Last 7 Days)
        $stmt = $this->pdo->prepare("
            SELECT date, SUM(bytes_sent) as total_bytes, SUM(hits) as total_hits 
            FROM domain_traffic 
            WHERE domain_id IN (SELECT id FROM domains WHERE client_id = ?) 
            AND date >= DATE(NOW() - INTERVAL 7 DAY)
            GROUP BY date 
            ORDER BY date ASC
        ");
        $stmt->execute([$this->cid]);
        $traffic_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Format for JS
        $dates = [];
        $hits = [];
        $bytes = [];

        // Fill missing dates with 0
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $found = false;
            foreach ($traffic_data as $row) {
                if ($row['date'] == $d) {
                    $dates[] = date('M d', strtotime($d));
                    $hits[] = (int) $row['total_hits'];
                    $bytes[] = round($row['total_bytes'] / 1024 / 1024, 2); // MB
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $dates[] = date('M d', strtotime($d));
                $hits[] = 0;
                $bytes[] = 0;
            }
        }

        // 6. Disk Usage Calculation (Mock for now, as in original)
        $user_home = "/var/www/clients/" . $this->username;
        $disk_usage_bytes = 0;
        if (file_exists($user_home)) {
            $disk_usage_bytes = 10 * 1024 * 1024; // 10MB mock
        }
        $disk_limit_bytes = $clientData['disk_mb'] * 1024 * 1024;
        $disk_perc = round(($disk_usage_bytes / max(1, $disk_limit_bytes)) * 100, 1);

        // Return Data needed for View
        return [
            'username' => $this->username,
            'clientData' => $clientData,
            'usage_dom' => $usage_dom,
            'usage_db' => $usage_db,
            'usage_mail' => $usage_mail,
            'disk_mb' => $clientData['disk_mb'],
            'disk_perc' => $disk_perc,
            'dates' => $dates,
            'hits' => $hits
        ];
    }
}
