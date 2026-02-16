#!/bin/bash

# ==============================================================================
# SHM PANEL - DATABASE SETUP (STABLE & NON-BLOCKING)
# ==============================================================================

# --- Configuration (Adjust as needed) ---
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
    log "Step 1: Installing MariaDB and Redis..."
    
    # Pre-configure MariaDB to avoid interactive prompts
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y mariadb-server mariadb-client redis-server >/dev/null 2>&1
    
    systemctl enable redis-server mariadb >/dev/null 2>&1
    systemctl start redis-server mariadb

    # Step 2: Set Root Password & Secure (NO-HANG Logic)
    log "Step 2: Securing MariaDB..."
    
    # Create the credential file so root can always log in from CLI
    cat > /root/.my.cnf << EOF
[client]
user=root
password=$MYSQL_ROOT_PASS
EOF
    chmod 600 /root/.my.cnf

    # ATTEMPT 1: Try setting password via Unix Socket (Standard for 10.4+)
    # This works without stopping the service or using mysqld_safe
    if mysql --no-defaults -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;" &>/dev/null; then
        log "Root password set successfully."
    
    # ATTEMPT 2: Already configured?
    elif mysql -e "SELECT 1" &>/dev/null; then
        log "Root access already verified."
        
    # ATTEMPT 3: The Bootstrap Fallback (If logic above fails, use a non-blocking init file)
    else
        warn "Initial access denied. Attempting bootstrap reset..."
        echo "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;" > /tmp/db_init.sql
        systemctl stop mariadb
        # Bootstrap runs the SQL then exits immediately - NO HANGING
        /usr/sbin/mariadbd --user=mysql --bootstrap --init-file=/tmp/db_init.sql >/dev/null 2>&1
        rm /tmp/db_init.sql
        systemctl start mariadb
    fi

    # Clean up anonymous users and test DB
    mysql -e "DELETE FROM mysql.user WHERE User='';"
    mysql -e "DROP DATABASE IF EXISTS test;"
    mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # Step 3: Performance Optimization
    log "Step 3: Applying Optimizations..."
    cat > /etc/mysql/mariadb.conf.d/99-shm-panel.cnf << MYSQL_OPT
[mysqld]
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_file_per_table = 1
innodb_flush_method = O_DIRECT
max_connections = 200
query_cache_size = 64M
query_cache_type = 1
MYSQL_OPT
    
    systemctl restart mariadb
    
    # Step 4: Create Panel Database and User
    log "Step 4: Creating Database: $DB_NAME"
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # Step 5: Import Full Schema
    log "Step 5: Importing Table Schemas..."
    mysql "$DB_NAME" << SQL
-- Core Tables
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) UNIQUE,
    email VARCHAR(255),
    password VARCHAR(255),
    status ENUM('active','suspended') DEFAULT 'active',
    package_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    domain VARCHAR(255) UNIQUE,
    document_root VARCHAR(255),
    php_version VARCHAR(5) DEFAULT '8.2',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50),
    price DECIMAL(10,2) DEFAULT 0.00,
    disk_mb INT,
    max_domains INT,
    max_emails INT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    email VARCHAR(255),
    role ENUM('superadmin','admin','moderator') DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mail_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) UNIQUE,
    client_id INT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ftp_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid VARCHAR(32) UNIQUE,
    passwd VARCHAR(255),
    homedir VARCHAR(255),
    client_id INT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS server_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cpu_percent DECIMAL(5,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Initial Seed Data
INSERT IGNORE INTO packages (name, price, disk_mb, max_domains, max_emails) VALUES 
('Starter', 0.00, 2000, 1, 5),
('Business', 9.99, 10000, 10, 50);

-- Default Admin (Password: password)
INSERT IGNORE INTO admins (username, password, email, role) VALUES 
('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$ADMIN_EMAIL', 'superadmin');

INSERT IGNORE INTO mail_domains (domain) VALUES ('$MAIN_DOMAIN');
SQL

    log "Database setup completed successfully!"
}

# Run the script
setup_database
