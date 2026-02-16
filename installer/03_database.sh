#!/bin/bash

# ==============================================================================
# SHM PANEL - DATABASE SETUP (FULL STABLE CODE)
# ==============================================================================

# --- Variables ---
# In a production environment, these should be passed as env vars.
# If not set, we generate random ones for security.
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS:-$(openssl rand -hex 12)}"
DB_NAME="${DB_NAME:-shm_panel}"
DB_USER="${DB_USER:-shm_user}"
DB_PASS="${DB_PASS:-$(openssl rand -hex 12)}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
MAIN_DOMAIN="${MAIN_DOMAIN:-$(hostname -f)}"

# --- Logging Helpers ---
log()   { echo -e "\e[32m[INFO] $1\e[0m"; }
warn()  { echo -e "\e[33m[WARN] $1\e[0m"; }
error() { echo -e "\e[31m[ERR] $1\e[0m"; exit 1; }

setup_database() {
    log "Step 3: Database Setup (MariaDB + Redis)..."
    
    # 1. Install Packages
    log "Installing database packages..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y mariadb-server mariadb-client redis-server >/dev/null 2>&1
    
    systemctl enable redis-server mariadb >/dev/null 2>&1
    systemctl start redis-server mariadb
    
    # 2. Secure MariaDB & Set Root Password
    log "Configuring MariaDB Root Security..."
    
    # Create /root/.my.cnf so root user can run mysql commands without password later
    cat > /root/.my.cnf << EOF
[client]
user=root
password=$MYSQL_ROOT_PASS
EOF
    chmod 600 /root/.my.cnf

    # ATTEMPT 1: Try setting password via Unix Socket (Standard for new MariaDB)
    if mysql --no-defaults -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;" &>/dev/null; then
        log "Root password set successfully via Unix Socket."
    
    # ATTEMPT 2: Check if current .my.cnf credentials already work
    elif mysql -e "SELECT 1" &>/dev/null; then
        log "Root password already configured."
        
    # ATTEMPT 3: The Fallback (Resetting if locked out) - Fixed to prevent hanging
    else
        warn "Direct access failed. Attempting safe-mode reset..."
        systemctl stop mariadb
        
        # Start in safe mode, detached, with all output suppressed
        mysqld_safe --skip-grant-tables --skip-networking >/dev/null 2>&1 &
        
        # Wait for the socket to initialize (max 15 seconds)
        RETRIES=0
        while [ ! -S /run/mysqld/mysqld.sock ] && [ $RETRIES -lt 15 ]; do
            sleep 1
            RETRIES=$((RETRIES+1))
        done
        
        if [ -S /run/mysqld/mysqld.sock ]; then
            mysql --no-defaults -e "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;"
            log "Root password reset in safe-mode."
        fi
        
        # Clean shutdown of safe-mode processes
        pkill -f mysqld_safe
        pkill -f mariadbd
        sleep 2
        systemctl start mariadb
    fi

    # Security Cleanup
    mysql -e "DELETE FROM mysql.user WHERE User='';"
    mysql -e "DROP DATABASE IF EXISTS test;"
    mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # 3. Optimization (Using a dedicated config file to avoid duplicate appends)
    log "Applying MariaDB Performance Tunings..."
    cat > /etc/mysql/mariadb.conf.d/99-shm-panel.cnf << MYSQL_OPT
[mysqld]
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_file_per_table = 1
innodb_flush_method = O_DIRECT
innodb_buffer_pool_instances = 2
max_connections = 200
query_cache_size = 64M
query_cache_type = 1
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mysql-slow.log
long_query_time = 2
MYSQL_OPT
    
    systemctl restart mariadb
    
    # 4. Create Panel Database and User
    log "Creating database: $DB_NAME"
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # 5. Import Full Schema
    log "Importing Table Schemas..."
    mysql "$DB_NAME" << SQL
-- Clients & Billing
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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

-- Web Hosting
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
) ENGINE=InnoDB;

-- Email Services
CREATE TABLE IF NOT EXISTS mail_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) UNIQUE,
    client_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mail_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT,
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    quota_mb INT DEFAULT 1024,
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES mail_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- User Databases
CREATE TABLE IF NOT EXISTS client_databases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    db_name VARCHAR(64) UNIQUE,
    db_size_mb INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Admin Management
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    email VARCHAR(255),
    role ENUM('superadmin','admin','moderator') DEFAULT 'admin',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Monitoring
CREATE TABLE IF NOT EXISTS server_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cpu_percent DECIMAL(5,2),
    memory_percent DECIMAL(5,2),
    disk_percent DECIMAL(5,2),
    load_avg DECIMAL(10,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Initial Seed Data
INSERT IGNORE INTO packages (name, price, disk_mb, max_domains, max_emails, features) VALUES 
('Starter', 0.00, 2000, 1, 5, 'Basic Support, 1 Domain'),
('Business', 9.99, 10000, 10, 50, 'Priority Support, SSL'),
('Enterprise', 29.99, 50000, 50, 200, '24/7 Support');

-- Default Admin (Password is 'password' by default in this hash)
INSERT IGNORE INTO admins (username, password, email, role) VALUES 
('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$ADMIN_EMAIL', 'superadmin');

INSERT IGNORE INTO mail_domains (domain) VALUES ('$MAIN_DOMAIN');
SQL

    log "Database setup completed successfully."
}

# --- Run Function ---
setup_database
