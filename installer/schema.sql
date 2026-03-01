-- ============================================================================
-- SHM PANEL - UNIFIED DATABASE SCHEMA
-- ============================================================================
-- This script contains the complete, authoritative database schema for SHM Panel

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) UNIQUE NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    status ENUM('active','suspended') DEFAULT 'active',
    package_id INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    role ENUM('superadmin','admin','support') DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) DEFAULT 0.00,
    disk_mb INT DEFAULT 1000,
    max_domains INT DEFAULT 1,
    max_emails INT DEFAULT 10,
    max_databases INT DEFAULT 5,
    features TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_gateway VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(255) UNIQUE NOT NULL,
    status ENUM('pending', 'paid', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_transaction (transaction_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    domain VARCHAR(255) UNIQUE NOT NULL,
    parent_id INT,
    document_root VARCHAR(500),
    php_version VARCHAR(10) DEFAULT '8.2',
    ssl_active BOOLEAN DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain (domain),
    INDEX idx_parent_id (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_databases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    domain_id INT,
    db_name VARCHAR(64) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain_id (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_db_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    db_user VARCHAR(32) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    UNIQUE KEY unique_client_user (client_id, db_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    domain_id INT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    quota_mb INT DEFAULT 100,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain_id (domain_id),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    domain VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dns_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    type ENUM('A','AAAA','CNAME','MX','TXT','NS','SOA','SRV') NOT NULL,
    name VARCHAR(255) NOT NULL,
    value TEXT NOT NULL,
    ttl INT DEFAULT 3600,
    priority INT DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_domain_id (domain_id),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS php_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    domain_id INT,
    memory_limit VARCHAR(20) DEFAULT '128M',
    max_execution_time INT DEFAULT 30,
    upload_max_filesize VARCHAR(20) DEFAULT '64M',
    post_max_size VARCHAR(20) DEFAULT '64M',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain_id (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    command TEXT NOT NULL,
    schedule VARCHAR(100) NOT NULL,
    enabled BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    backup_type ENUM('full','files','database') NOT NULL,
    file_path VARCHAR(500),
    size_mb DECIMAL(10,2),
    status ENUM('pending','completed','failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(255) NOT NULL,
    severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
    ip VARCHAR(45),
    user VARCHAR(50),
    user_agent VARCHAR(500),
    context TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_severity (severity),
    INDEX idx_user (user),
    INDEX idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS error_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    file VARCHAR(500),
    line INT,
    user VARCHAR(50),
    trace TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_type (type),
    INDEX idx_user (user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    ip VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500),
    success BOOLEAN DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip),
    INDEX idx_username (username),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS active_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) UNIQUE NOT NULL,
    user_id INT,
    user_type ENUM('client', 'admin') NOT NULL,
    ip VARCHAR(45),
    user_agent VARCHAR(500),
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, user_type),
    INDEX idx_session (session_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_installations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    domain_id INT NOT NULL,
    app_type VARCHAR(50) NOT NULL,
    db_name VARCHAR(64),
    db_user VARCHAR(32),
    db_pass VARCHAR(255),
    install_path VARCHAR(500),
    status ENUM('installing', 'installed', 'failed') DEFAULT 'installing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain_id (domain_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ftp_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    userid VARCHAR(32) UNIQUE NOT NULL,
    passwd VARCHAR(255) NOT NULL,
    homedir VARCHAR(500) NOT NULL,
    uid INT DEFAULT 33,
    gid INT DEFAULT 33,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_userid (userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domain_traffic (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    date DATE NOT NULL,
    hits INT DEFAULT 0,
    bytes_sent BIGINT DEFAULT 0,
    unique_visitors INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain_id (domain_id),
    INDEX idx_date (date),
    UNIQUE KEY unique_domain_date (domain_id, date),
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS malware_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    status ENUM('clean', 'infected', 'running', 'failed') DEFAULT 'clean',
    threats_found INT DEFAULT 0,
    files_scanned INT DEFAULT 0,
    scan_duration INT DEFAULT 0,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    details TEXT,
    INDEX idx_domain_id (domain_id),
    INDEX idx_status (status),
    INDEX idx_scanned_at (scanned_at),
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Server Metrics Table
CREATE TABLE IF NOT EXISTS server_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cpu_percent DECIMAL(5,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Seed Data
INSERT IGNORE INTO packages (id, name, price, disk_mb, max_domains, max_emails, max_databases, features) VALUES 
(1, 'Basic Plan', 49.00, 1000, 1, 2, 1, 'Standard Speed, Beginner Friendly'),
(2, 'Smart Plan', 149.00, 5000, 3, 10, 5, 'Faster Performance, Priority Support'),
(3, 'Pro Plan', 249.00, 15000, 10, 25, 10, 'Free Backup, High Performance, Developer Friendly'),
(4, 'Agency Plan', 399.00, 40000, 9999, 100, 9999, 'Free Backup, Priority Resources, 24/7 Support');
