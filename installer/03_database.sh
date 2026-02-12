#!/bin/bash

# ==============================================================================
# SHM PANEL - DATABASE SETUP
# ==============================================================================

setup_database() {
    log "Setting up Database (MariaDB + Redis)..."
    
    # 1. Install Packages
    apt-get install -y mariadb-server mariadb-client redis-server
    systemctl enable redis-server
    systemctl enable mariadb
    
    # 2. Secure MariaDB
    log "Securing MariaDB..."
    
    # Create .my.cnf for root FIRST (so mysql client uses it)
    cat > /root/.my.cnf << EOF
[client]
user=root
password=$MYSQL_ROOT_PASS
EOF
    chmod 600 /root/.my.cnf

    # Helper to run mysql commands robustly
    mysql_exec() {
        local query="$1"
        # Try default (uses .my.cnf if exists)
        if ! mysql -e "$query" 2>/dev/null; then
            # If that fails, try via socket without password (fresh install case where .my.cnf might be ignored or wrong if password not yet set in DB)
            # But wait, if .my.cnf exists, mysql uses it. 
            # Force ignore .my.cnf for fallback?
            mysql --no-defaults -e "$query"
        fi
    }

    # Set Root Password
    # We use a try-catch approach. 
    # 1. Try setting password assuming we can connect (socket or .my.cnf)
    # Set Root Password
    log "Updating Root Password..."
    
    # Try 1: Standard .my.cnf auth
    if mysql -e "SELECT 1" &>/dev/null; then
        mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS';"
    
    # Try 2: Socket auth (no password)
    elif mysql --no-defaults -e "SELECT 1" &>/dev/null; then
         mysql --no-defaults -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS';"
         
    # Try 3: Nuclear Option (Reset Root Pass)
    else
        warn "Database access denied. Attempting to force reset root password..."
        
        systemctl stop mariadb
        
        # Start in safe mode
        mysqld_safe --skip-grant-tables --skip-networking &
        PID=$!
        sleep 10
        
        # Force update
        mysql --no-defaults -e "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;"
        
        # Kill safe mode and restart
        if ps -p $PID > /dev/null; then
            kill $PID
            wait $PID 2>/dev/null || true
        else
            pkill mysqld
        fi
        
        systemctl start mariadb
        
        # Verify
        if ! mysql -e "SELECT 1" &>/dev/null; then
            error "Failed to reset MariaDB password. Please check logs."
        fi
        
        log "Root password forcibly reset."
    fi
    
    mysql -e "FLUSH PRIVILEGES;"
    
    # Clean up anonymous users & test db
    mysql -e "DELETE FROM mysql.user WHERE User='';" 2>/dev/null || true
    mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');" 2>/dev/null || true
    mysql -e "DROP DATABASE IF EXISTS test;" 2>/dev/null || true
    mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';" 2>/dev/null || true
    mysql -e "FLUSH PRIVILEGES;"
    
    # 3. Optimization
    cat >> /etc/mysql/mariadb.conf.d/50-server.cnf << MYSQL_OPT
[mysqld]
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_file_per_table = 1
innodb_flush_method = O_DIRECT
innodb_buffer_pool_instances = 2
max_connections = 200
max_user_connections = 50
thread_cache_size = 8
query_cache_size = 64M
query_cache_type = 1
query_cache_limit = 2M
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mysql-slow.log
long_query_time = 2
MYSQL_OPT
    
    systemctl restart mariadb
    
    # 4. Create App Database and User
    log "Creating Application Database..."
    mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # 5. Import Schema
    # Check if we have the schema file (it's embedded in original install.sh)
    # Since we are refactoring, we need to create the schema file or embed it here.
    # For now, I will embed the schema creation here directly.
    
    log "Importing Database Schema..."
    mysql $DB_NAME << SQL
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) UNIQUE,
    email VARCHAR(255),
    password VARCHAR(255),
    status ENUM('active','suspended') DEFAULT 'active',
    package_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    disk_used_mb INT DEFAULT 0,
    bandwidth_mb INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    domain VARCHAR(255) UNIQUE,
    document_root VARCHAR(255),
    php_version VARCHAR(5) DEFAULT '8.2',
    ssl_active BOOLEAN DEFAULT 0,
    ssl_expiry DATE NULL,
    parent_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50),
    price DECIMAL(10,2) DEFAULT 0.00,
    disk_mb INT,
    max_domains INT,
    max_emails INT,
    max_databases INT DEFAULT 5,
    max_bandwidth_mb INT DEFAULT 10240,
    features TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    amount DECIMAL(10,2),
    currency VARCHAR(10),
    payment_gateway VARCHAR(20),
    transaction_id VARCHAR(100),
    status VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    email VARCHAR(255),
    role ENUM('superadmin','admin','moderator') DEFAULT 'admin',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) UNIQUE,
    client_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT,
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    quota_mb INT DEFAULT 1024,
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES mail_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ftp_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid VARCHAR(32) UNIQUE,
    passwd VARCHAR(255),
    homedir VARCHAR(255),
    uid INT DEFAULT 33,
    gid INT DEFAULT 33,
    shell VARCHAR(255) DEFAULT '/sbin/nologin',
    client_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_databases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    db_name VARCHAR(64) UNIQUE,
    db_size_mb INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_db_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    db_user VARCHAR(32),
    db_pass VARCHAR(255),
    permissions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dns_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT,
    type VARCHAR(10),
    host VARCHAR(255),
    value VARCHAR(255),
    priority INT DEFAULT NULL,
    ttl INT DEFAULT 86400,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS php_config (
    domain_id INT PRIMARY KEY,
    memory_limit VARCHAR(10) DEFAULT '128M',
    max_execution_time INT DEFAULT 300,
    upload_max_filesize VARCHAR(10) DEFAULT '128M',
    post_max_size VARCHAR(10) DEFAULT '128M',
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domain_traffic (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT,
    date DATE,
    bytes_sent BIGINT DEFAULT 0,
    hits INT DEFAULT 0,
    bandwidth_mb INT DEFAULT 0,
    UNIQUE KEY (domain_id, date),
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS malware_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT,
    status ENUM('running','clean','infected','failed'),
    report TEXT,
    infected_files INT DEFAULT 0,
    scanned_files INT DEFAULT 0,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_installations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    domain_id INT,
    app_type VARCHAR(20),
    db_name VARCHAR(64),
    db_user VARCHAR(32),
    db_pass VARCHAR(255),
    version VARCHAR(20),
    status VARCHAR(20),
    installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS server_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cpu_percent DECIMAL(5,2),
    memory_percent DECIMAL(5,2),
    disk_percent DECIMAL(5,2),
    load_avg DECIMAL(10,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255),
    method VARCHAR(10),
    ip_address VARCHAR(45),
    user_agent TEXT,
    response_time_ms INT,
    status_code INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50),
    severity ENUM('info','warning','critical'),
    source_ip VARCHAR(45),
    user_id INT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    type ENUM('full','database','files'),
    filename VARCHAR(255),
    size_mb INT,
    location VARCHAR(500),
    encrypted BOOLEAN DEFAULT 0,
    status ENUM('completed','failed','in_progress'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Info
INSERT IGNORE INTO packages (name, price, disk_mb, max_domains, max_emails, features) VALUES 
('Starter', 0.00, 2000, 1, 5, 'Basic Support, 1 Domain'),
('Business', 9.99, 10000, 10, 50, 'Priority Support, SSL'),
('Enterprise', 29.99, 50000, 50, 200, '24/7 Support');

INSERT IGNORE INTO admins (username, password, email, role) VALUES 
('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$ADMIN_EMAIL', 'superadmin');

INSERT IGNORE INTO mail_domains (domain) VALUES ('$MAIN_DOMAIN');
SQL

}
