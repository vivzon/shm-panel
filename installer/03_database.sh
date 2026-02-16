#!/bin/bash

# ==============================================================================
# SHM PANEL - DATABASE SETUP (FIXED & OPTIMIZED)
# ==============================================================================

# --- Variables (Ensure these are set in your main script or environment) ---
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS:-$(openssl rand -hex 16)}"
DB_NAME="${DB_NAME:-shm_panel}"
DB_USER="${DB_USER:-shm_user}"
DB_PASS="${DB_PASS:-$(openssl rand -hex 16)}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
MAIN_DOMAIN="${MAIN_DOMAIN:-localhost}"

# --- Logging Helpers ---
log()   { echo -e "\e[32m[INST] $1\e[0m"; }
warn()  { echo -e "\e[33m[WARN] $1\e[0m"; }
error() { echo -e "\e[31m[ERR] $1\e[0m"; exit 1; }

setup_database() {
    log "Setting up Database (MariaDB + Redis)..."
    
    # 1. Install Packages
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y mariadb-server mariadb-client redis-server
    
    systemctl enable redis-server
    systemctl start redis-server
    systemctl enable mariadb
    systemctl start mariadb

    # 2. Secure MariaDB & Set Root Password
    log "Securing MariaDB and setting root password..."
    
    # Create temporary .my.cnf to check connectivity
    cat > /root/.my.cnf << EOF
[client]
user=root
password=$MYSQL_ROOT_PASS
EOF
    chmod 600 /root/.my.cnf

    # Try setting password assuming unix_socket (default on fresh MariaDB)
    if mysql --no-defaults -u root -e "SELECT 1" &>/dev/null; then
        mysql --no-defaults -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;"
    # If that fails, check if the password in .my.cnf already works
    elif ! mysql -e "SELECT 1" &>/dev/null; then
        warn "Database access denied. Attempting force reset root password..."
        systemctl stop mariadb
        mysqld_safe --skip-grant-tables --skip-networking &
        PID=$!
        sleep 5
        mysql --no-defaults -e "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;"
        kill $PID
        sleep 2
        systemctl start mariadb
    fi

    # Final verification
    if ! mysql -e "SELECT 1" &>/dev/null; then
        error "Could not connect to MariaDB. Check root password logic."
    fi
    
    # Clean up standard security risks
    mysql -e "DELETE FROM mysql.user WHERE User='';" 2>/dev/null || true
    mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');" 2>/dev/null || true
    mysql -e "DROP DATABASE IF EXISTS test;" 2>/dev/null || true
    mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';" 2>/dev/null || true
    mysql -e "FLUSH PRIVILEGES;"
    
    # 3. Optimization (Using a separate file to prevent duplicates in 50-server.cnf)
    log "Applying MariaDB optimizations..."
    cat > /etc/mysql/mariadb.conf.d/99-shm-panel.cnf << MYSQL_OPT
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
    log "Creating Application Database: $DB_NAME"
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # 5. Import Schema
    log "Importing Database Schema..."
    mysql "$DB_NAME" << SQL
-- Structure for clients
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Structure for domains
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Structure for packages
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Structure for transactions
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Structure for admins
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    email VARCHAR(255),
    role ENUM('superadmin','admin','moderator') DEFAULT 'admin',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Structure for mail
CREATE TABLE IF NOT EXISTS mail_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) UNIQUE,
    client_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT,
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    quota_mb INT DEFAULT 1024,
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES mail_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Structure for FTP
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Monitoring and Logs
CREATE TABLE IF NOT EXISTS server_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cpu_percent DECIMAL(5,2),
    memory_percent DECIMAL(5,2),
    disk_percent DECIMAL(5,2),
    load_avg DECIMAL(10,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Initial Seed Data
INSERT IGNORE INTO packages (name, price, disk_mb, max_domains, max_emails, features) VALUES 
('Starter', 0.00, 2000, 1, 5, 'Basic Support, 1 Domain'),
('Business', 9.99, 10000, 10, 50, 'Priority Support, SSL'),
('Enterprise', 29.99, 50000, 50, 200, '24/7 Support');

-- Default Admin (Password: password)
INSERT IGNORE INTO admins (username, password, email, role) VALUES 
('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$ADMIN_EMAIL', 'superadmin');

INSERT IGNORE INTO mail_domains (domain) VALUES ('$MAIN_DOMAIN');
SQL

    log "Database setup completed successfully."
}

# Execute function
setup_database
