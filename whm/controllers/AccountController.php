<?php
require_once __DIR__ . '/../../shared/config.php';

class AccountController
{
    private $pdo;

    public function __construct()
    {
        global $pdo;
        $this->pdo = $pdo;

        if (!isset($_SESSION['admin'])) {
            header("Location: login.php");
            exit;
        }
    }

    public function index()
    {
        // CSRF Token (Admin also needs protection)
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Fetch packages for the "Create Account" modal dropdown
        $packages = $this->pdo->query("SELECT * FROM packages")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'packages' => $packages
        ];
    }
}
