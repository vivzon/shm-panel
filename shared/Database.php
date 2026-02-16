<?php
/**
 * Database Connection Handler
 * 
 * Singleton pattern PDO wrapper with prepared statement support
 * to prevent SQL injection vulnerabilities.
 * 
 * @package SHM Panel
 * @version 1.0.0
 */

class Database {
    /**
     * @var Database|null Singleton instance
     */
    private static $instance = null;
    
    /**
     * @var PDO Database connection
     */
    private $pdo;
    
    /**
     * Private constructor to prevent direct instantiation
     * 
     * @throws PDOException If connection fails
     */
    private function __construct() {
        // Load configuration
        if (file_exists(__DIR__ . '/config.local.php')) {
            require_once __DIR__ . '/config.local.php';
        } else {
            require_once __DIR__ . '/config.php';
        }
        
        // Build DSN
        $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
        
        // PDO options for security and performance
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        
        try {
            $this->pdo = new PDO($dsn, $db_user, $db_pass, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new PDOException("Database connection failed. Please check your configuration.");
        }
    }
    
    /**
     * Get singleton instance
     * 
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Execute a prepared statement query
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return PDOStatement
     * @throws PDOException If query fails
     * 
     * @example
     * $db = Database::getInstance();
     * $stmt = $db->query("SELECT * FROM clients WHERE username = ?", [$username]);
     * $client = $stmt->fetch();
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query failed: " . $e->getMessage() . " | SQL: " . $sql);
            throw $e;
        }
    }
    
    /**
     * Execute a query and fetch a single row
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return array|false
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Execute a query and fetch all rows
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return array
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Execute an INSERT/UPDATE/DELETE query
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return int Number of affected rows
     */
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Get the last inserted ID
     * 
     * @return string
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Begin a transaction
     * 
     * @return bool
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit a transaction
     * 
     * @return bool
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback a transaction
     * 
     * @return bool
     */
    public function rollback() {
        return $this->pdo->rollBack();
    }
    
    /**
     * Check if currently in a transaction
     * 
     * @return bool
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
    
    /**
     * Get the underlying PDO instance (use sparingly)
     * 
     * @return PDO
     */
    public function getPDO() {
        return $this->pdo;
    }
    
    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
?>
