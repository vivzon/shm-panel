#!/bin/bash

# ==============================================================================
# SHM PANEL - PRODUCTION INSTALLER (v6.0 Stable)
# ==============================================================================
# This script deploys the SHM Panel project to your server.
# Run this script as root from the directory containing the project files.
# Features: Security Hardened, SSL Auto-Config, Monitoring, Backups
# ==============================================================================

export DEBIAN_FRONTEND=noninteractive

# --- Colors & Logging ---
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log() { echo -e "${GREEN}[INSTALLER] $1${NC}"; }
warn() { echo -e "${YELLOW}[WARNING] $1${NC}"; }
error() { echo -e "${RED}[ERROR] $1${NC}"; exit 1; }
info() { echo -e "${CYAN}[INFO] $1${NC}"; }

# --- Pre-Flight Validation ---
validate_domain() {
    local domain="$1"
    if [[ ! "$domain" =~ ^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
        error "Invalid domain format: $domain"
    fi
}

validate_email() {
    local email="$1"
    if [[ ! "$email" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
        error "Invalid email format: $email"
    fi
}

check_root() {
    if [ "$EUID" -ne 0 ]; then 
        error "Please run as root (sudo ./install.sh)"
    fi
}

check_files() {
    if [ ! -f "shm-manage" ]; then
        error "File 'shm-manage' not found in current directory. Please ensure you are in the project root."
    fi
    
    if [ ! -d "whm" ] || [ ! -d "cpanel" ] || [ ! -f "shared/config.php" ]; then
        warn "Some frontend files may be missing. Continuing anyway..."
    fi
}

check_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$NAME
        VER=$VERSION_ID
        
        if [[ "$OS" != *"Ubuntu"* && "$OS" != *"Debian"* ]]; then
            warn "Detected OS: $OS $VER. This installer is optimized for Ubuntu 20.04+/Debian 11+."
            read -p "Continue anyway? (y/n) " -n 1 -r
            echo
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then 
                exit 1
            fi
        fi
        
        if [[ "$VER" < "20.04" ]] && [[ "$OS" == *"Ubuntu"* ]]; then
            warn "Ubuntu version $VER is older than recommended 20.04+"
        fi
    fi
}

# --- 0. Pre-Flight Checks ---
clear
echo -e "${BLUE}"
echo "  _____________________________________"
echo " / SHM Panel - Production Installer    \\"
echo " \____________________________________/"
echo -e "${NC}"

check_root
check_files
check_os

# Backup existing critical files
log "Creating backup of existing configurations..."
BACKUP_DIR="/root/shm-backup-$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
cp -r /etc/nginx/sites-available/* "$BACKUP_DIR/" 2>/dev/null || true
cp -r /etc/nginx/sites-enabled/* "$BACKUP_DIR/" 2>/dev/null || true
cp -r /etc/mysql "$BACKUP_DIR/" 2>/dev/null || true

# --- Configuration ---
if [ -z "$MAIN_DOMAIN" ]; then
    read -p "Enter Main Domain (e.g. example.com): " MAIN_DOMAIN
fi
validate_domain "$MAIN_DOMAIN"

if [ -z "$ADMIN_EMAIL" ]; then
    read -p "Enter Admin Email (e.g. admin@example.com): " ADMIN_EMAIL
fi
validate_email "$ADMIN_EMAIL"

# Server IP
SERVER_IP=$(hostname -I | awk '{print $1}')

# Fallback defaults
MAIN_DOMAIN=${MAIN_DOMAIN:-example.com}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@$MAIN_DOMAIN}
DB_NAME="shm_panel"
DB_USER="shm_admin"
DB_PASS=$(openssl rand -base64 16)
MYSQL_ROOT_PASS=$(openssl rand -base64 18)
ADMIN_PASS=$(openssl rand -base64 12)

cat <<INFO
===============================================
           INSTALLATION SUMMARY
===============================================
Target Domain:    $MAIN_DOMAIN
Server IP:        $SERVER_IP
Admin Email:      $ADMIN_EMAIL
Database Name:    $DB_NAME
Database User:    $DB_USER
===============================================
INFO

read -p "Proceed with installation? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    exit 1
fi

# --- 1. System Preparation ---
log "Updating System & Installing Dependencies..."
apt-get update
apt-get upgrade -y

# Install essential core packages
apt-get install -y software-properties-common curl wget git zip unzip ufw fail2ban acl quota jq \
    clamav clamav-daemon clamav-freshclam htop nmon net-tools bmon iftop nethogs \
    rclone rsync screen tmux

# Install Web Stack & Mail Stack
apt-get install -y certbot python3-certbot-nginx bind9 bind9utils
apt-get install -y dovecot-core dovecot-imapd dovecot-pop3d dovecot-mysql postfix postfix-mysql
apt-get install -y proftpd-basic proftpd-mod-mysql 
apt-get install -y mariadb-server mariadb-client

# Add PHP Repository
add-apt-repository ppa:ondrej/php -y
apt-get update

# Install PHP Versions (8.1, 8.2, 8.3) - 8.2 is Default
log "Installing PHP Versions..."
for v in 8.1 8.2 8.3; do
    apt-get install -y php$v-fpm php$v-mysql php$v-common php$v-gd php$v-mbstring \
        php$v-xml php$v-zip php$v-curl php$v-bcmath php$v-intl php$v-imagick php$v-cli \
        php$v-redis php$v-opcache php$v-soap
    
    # Configure PHP Limits (Global)
    sed -i "s/upload_max_filesize = .*/upload_max_filesize = 2048M/" /etc/php/$v/fpm/php.ini
    sed -i "s/post_max_size = .*/post_max_size = 2048M/" /etc/php/$v/fpm/php.ini
    sed -i "s/memory_limit = .*/memory_limit = 2048M/" /etc/php/$v/fpm/php.ini
    sed -i "s/max_execution_time = .*/max_execution_time = 300/" /etc/php/$v/fpm/php.ini
    sed -i "s/max_input_time = .*/max_input_time = 300/" /etc/php/$v/fpm/php.ini
    
    # Enable OPCache for performance
    cat >> /etc/php/$v/fpm/conf.d/10-opcache.ini << OPCACHE
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
OPCACHE
    
    # Configure PHP-FPM socket permissions
    cat >> /etc/php/$v/fpm/pool.d/www.conf << PHP_SOCKET
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
PHP_SOCKET
done

# Install Composer
if ! command -v composer &> /dev/null; then
    log "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Install Node.js & NPM (LTS v20)
if ! command -v node &> /dev/null; then
    log "Installing Node.js..."
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs npm
fi

# Install Redis
apt-get install -y redis-server
systemctl enable redis-server

# --- 1a. Optimization & Swap ---
# Create 2GB Swap if none exists (Prevents OOM Kills)
if [ $(swapon --show | wc -l) -eq 0 ]; then
    log "Allocating 2GB Swap File..."
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' | tee -a /etc/fstab
    
    # Tuning Swap
    sysctl vm.swappiness=10
    echo 'vm.swappiness=10' >> /etc/sysctl.conf
fi

# --- Kernel Optimization ---
log "Optimizing Kernel Parameters..."
cat >> /etc/sysctl.conf << SYSCTL_TUNE
# Network tuning
net.core.rmem_max = 134217728
net.core.wmem_max = 134217728
net.ipv4.tcp_rmem = 4096 87380 134217728
net.ipv4.tcp_wmem = 4096 65536 134217728
net.ipv4.tcp_congestion_control = bbr
net.ipv4.tcp_fastopen = 3

# Security hardening
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.accept_source_route = 0
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.icmp_ignore_bogus_error_responses = 1

# File descriptor limits
fs.file-max = 2097152
fs.nr_open = 2097152
kernel.pid_max = 4194303

# Memory
vm.vfs_cache_pressure = 50
vm.dirty_ratio = 10
vm.dirty_background_ratio = 5
SYSCTL_TUNE
sysctl -p

# --- 2. Database Setup ---
log "Configuring Database (MariaDB)..."

# Secure MariaDB with debconf
debconf-set-selections <<< "mysql-server mysql-server/root_password password $MYSQL_ROOT_PASS"
debconf-set-selections <<< "mysql-server mysql-server/root_password_again password $MYSQL_ROOT_PASS"

# Create root credentials file
cat > /root/.my.cnf << EOF
[client]
user=root
password=$MYSQL_ROOT_PASS
EOF
chmod 600 /root/.my.cnf

# Create App DB
mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# Import Schema
log "Importing Schema..."
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
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_client_id (client_id),
    INDEX idx_domain (domain)
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
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    INDEX idx_domain_id (domain_id)
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
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255),
    method VARCHAR(10),
    ip_address VARCHAR(45),
    user_agent TEXT,
    response_time_ms INT,
    status_code INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_address (ip_address),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50),
    severity ENUM('info','warning','critical'),
    source_ip VARCHAR(45),
    user_id INT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Default Data
INSERT IGNORE INTO packages (id, name, price, disk_mb, max_domains, max_emails, max_databases, max_bandwidth_mb, features) VALUES 
(1, 'Starter', 0.00, 2000, 1, 5, 2, 10240, 'Basic Support, 1 Domain, 5 Email Accounts'),
(2, 'Business', 9.99, 10000, 10, 50, 10, 51200, 'Priority Support, 10 Domains, 50 Email Accounts, SSL Included'),
(3, 'Enterprise', 29.99, 50000, 50, 200, 50, 204800, '24/7 Support, 50 Domains, 200 Email Accounts, Advanced Security');

-- Admin: admin / password from ADMIN_PASS (bcrypt hash)
INSERT IGNORE INTO admins (username, password, email, role) VALUES 
('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$ADMIN_EMAIL', 'superadmin');

-- Insert default DNS records for main domain
INSERT IGNORE INTO mail_domains (domain) VALUES ('$MAIN_DOMAIN');
SQL

# Optimize MariaDB Configuration
log "Optimizing MariaDB configuration..."
cat >> /etc/mysql/mariadb.conf.d/50-server.cnf << MYSQL_OPT
[mysqld]
# Performance
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_file_per_table = 1
innodb_flush_method = O_DIRECT
innodb_buffer_pool_instances = 2

# Connections
max_connections = 200
max_user_connections = 50
thread_cache_size = 8

# Caching
query_cache_size = 64M
query_cache_type = 1
query_cache_limit = 2M

# Logging
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mysql-slow.log
long_query_time = 2
log_queries_not_using_indexes = 0

# Binary Logging (for replication/backups)
# server_id = 1
# log_bin = /var/log/mysql/mariadb-bin
# expire_logs_days = 10
# max_binlog_size = 100M
MYSQL_OPT

# Hardening MariaDB
mysql -e "DELETE FROM mysql.user WHERE User='';"
mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
mysql -e "DROP DATABASE IF EXISTS test;"
mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
mysql -e "FLUSH PRIVILEGES;"

# --- 3. Backend Deployment ---
log "Deploying Backend Engine (shm-manage)..."
cp shm-manage /usr/local/bin/shm-manage
chmod 750 /usr/local/bin/shm-manage

# Create configuration directory
mkdir -p /etc/shm
cat > /etc/shm/config.sh << CONFIG
#!/bin/bash
# SHM Panel Configuration
DB_NAME='$DB_NAME'
DB_USER='$DB_USER'
DB_PASS='$DB_PASS'
MAIN_DOMAIN='$MAIN_DOMAIN'
ADMIN_EMAIL='$ADMIN_EMAIL'
SERVER_IP='$SERVER_IP'
BACKUP_DIR='/var/backups/shm'
ENCRYPTION_KEY='$(openssl rand -base64 32)'
API_KEY='$(openssl rand -hex 32)'
CONFIG
chmod 600 /etc/shm/config.sh

# Create backup directory
mkdir -p /var/backups/shm
chmod 700 /var/backups/shm

# Allow Web Server to run shm-manage via sudo
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/shm-manage" > /etc/sudoers.d/shm
chmod 0440 /etc/sudoers.d/shm

# --- 4. Frontend Deployment ---
log "Deploying Frontend Files..."

# Create Directories Structure
mkdir -p /var/www/panel/{whm,cpanel,shared,landing,assets}
mkdir -p /var/www/clients
mkdir -p /var/www/apps/{filemanager,monitor,backup}
mkdir -p /var/log/shm
mkdir -p /var/mail/vhosts
mkdir -p /etc/shm/hooks

# Set Permissions
chown -R www-data:www-data /var/www/panel /var/www/apps /var/log/shm
chmod -R 755 /var/www/panel
chmod -R 750 /var/www/clients

# Copy Files
if [ -d "whm" ]; then 
    cp -r whm/* /var/www/panel/whm/
fi
if [ -d "cpanel" ]; then 
    cp -r cpanel/* /var/www/panel/cpanel/
    log "Deployed Client Panel MVC (Controllers, Views, API)"
fi

# Create Landing Page if none exists
if [ ! -f "landing/index.html" ]; then
    cat > /var/www/panel/landing/index.html << LANDING
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHM Panel - Professional Hosting</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 0; background: #f8fafc; color: #1e293b; }
        .container { max-width: 800px; margin: 50px auto; padding: 40px; background: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center; }
        h1 { color: #3b82f6; margin-bottom: 20px; }
        .status { background: #10b981; color: white; padding: 10px 20px; border-radius: 20px; display: inline-block; margin: 20px 0; }
        .links { margin-top: 30px; }
        .links a { display: inline-block; margin: 10px; padding: 12px 24px; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; }
        .links a:hover { background: #2563eb; }
        .info { background: #f1f5f9; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 SHM Panel</h1>
        <p>Professional Web Hosting Control Panel</p>
        <div class="status">System Online</div>
        
        <div class="info">
            <strong>Installation Details:</strong><br>
            Domain: $MAIN_DOMAIN<br>
            Server IP: $SERVER_IP<br>
            Admin Panel: <a href="https://admin.$MAIN_DOMAIN">admin.$MAIN_DOMAIN</a>
        </div>
        
        <div class="links">
            <a href="https://admin.$MAIN_DOMAIN">Admin Panel</a>
            <a href="https://client.$MAIN_DOMAIN">Client Area</a>
            <a href="https://webmail.$MAIN_DOMAIN">Webmail</a>
        </div>
        
        <p style="margin-top: 40px; color: #64748b; font-size: 0.9em;">
            Powered by SHM Panel v6.0 | <a href="https://github.com" style="color: #3b82f6;">Documentation</a>
        </p>
    </div>
</body>
</html>
LANDING
else
    cp -r landing/* /var/www/panel/landing/
fi

# Copy Shared Config
if [ -f "shared/config.php" ]; then
    cp shared/config.php /var/www/panel/shared/config.php
    # Update Config with Real Password
    sed -i "s/SHMPanel_Secure_Pass_2025/$DB_PASS/g" /var/www/panel/shared/config.php
    sed -i "s/yourdomain.com/$MAIN_DOMAIN/g" /var/www/panel/shared/config.php
fi

# File Manager Setup
if [ -f "cpanel/files.php" ]; then 
    cp cpanel/files.php /var/www/apps/filemanager/index.php
fi

# --- 4a. Install Web Apps (PMA, Roundcube) ---
log "Installing Web Applications..."

# 1. phpMyAdmin (Secured)
if [ ! -d "/var/www/apps/phpmyadmin" ]; then
    log "Installing phpMyAdmin..."
    mkdir -p /var/www/apps/phpmyadmin
    PMA_VERSION="5.2.1"
    wget -q https://files.phpmyadmin.net/phpMyAdmin/$PMA_VERSION/phpMyAdmin-${PMA_VERSION}-all-languages.zip -O /tmp/pma.zip
    unzip -q /tmp/pma.zip -d /tmp/
    mv /tmp/phpMyAdmin-$PMA_VERSION-all-languages/* /var/www/apps/phpmyadmin/
    rm -rf /tmp/pma* /tmp/phpMyAdmin*
    
    # PMA Configuration with security
    cat > /var/www/apps/phpmyadmin/config.inc.php << PMA
<?php
declare(strict_types=1);

\$i = 0;
\$i++;
\$cfg['Servers'][\$i]['auth_type'] = 'cookie';
\$cfg['Servers'][\$i]['host'] = 'localhost';
\$cfg['Servers'][\$i]['compress'] = false;
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;
\$cfg['Servers'][\$i]['AllowRoot'] = false;

// Security
\$cfg['ForceSSL'] = true;
\$cfg['AllowArbitraryServer'] = false;
\$cfg['LoginCookieValidity'] = 14400;
\$cfg['blowfish_secret'] = '$(openssl rand -base64 32)';

// UI
\$cfg['MaxNavigationItems'] = 100;
\$cfg['NavigationTreeEnableGrouping'] = false;
\$cfg['ShowDatabasesNavigationAsTree'] = false;

// Uploads disabled for security
\$cfg['UploadDir'] = '';
\$cfg['SaveDir'] = '';
\$cfg['TempDir'] = '/tmp';

// Features
\$cfg['ShowPhpInfo'] = false;
\$cfg['ShowChgPassword'] = false;
\$cfg['ShowCreateDb'] = false;
?>
PMA
    
    # Create .htaccess for additional security
    cat > /var/www/apps/phpmyadmin/.htaccess << HTACCESS
Order Deny,Allow
Deny from all
Allow from 127.0.0.1
Allow from ::1
# Allow from your IP if needed
# Allow from 192.168.1.0/24

AuthType Basic
AuthName "Restricted Access"
AuthUserFile /etc/nginx/.htpasswd
Require valid-user

# Security Headers
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set X-XSS-Protection "1; mode=block"
HTACCESS
    
    # Create htpasswd file
    echo "admin:$(openssl passwd -apr1 '$ADMIN_PASS')" > /etc/nginx/.htpasswd
    chmod 644 /etc/nginx/.htpasswd
fi

# 2. Roundcube Webmail
if [ ! -d "/var/www/apps/webmail" ]; then
    log "Installing Roundcube Webmail..."
    mkdir -p /var/www/apps/webmail
    RC_VERSION="1.6.6"
    wget -q https://github.com/roundcube/roundcubemail/releases/download/$RC_VERSION/roundcubemail-$RC_VERSION-complete.tar.gz -O /tmp/rc.tar.gz
    tar -xf /tmp/rc.tar.gz -C /tmp/
    mv /tmp/roundcubemail-$RC_VERSION/* /var/www/apps/webmail/
    rm -rf /tmp/rc* /tmp/roundcubemail*
    
    # Create Roundcube Database
    mysql -e "CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "GRANT ALL PRIVILEGES ON roundcube.* TO '$DB_USER'@'localhost';"
    mysql roundcube < /var/www/apps/webmail/SQL/mysql.initial.sql
    
    # Roundcube Configuration
    cat > /var/www/apps/webmail/config/config.inc.php << RC
<?php
\$config = [];

// Database
\$config['db_dsnw'] = 'mysql://$DB_USER:$DB_PASS@localhost/roundcube';

// IMAP
\$config['default_host'] = 'localhost';
\$config['default_port'] = 143;
\$config['imap_conn_options'] = [
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
];

// SMTP
\$config['smtp_server'] = 'localhost';
\$config['smtp_port'] = 587;
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';
\$config['smtp_conn_options'] = [
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
];

// Security
\$config['des_key'] = '$(openssl rand -hex 24)';
\$config['cipher_method'] = 'AES-256-CBC';
\$config['force_https'] = true;

// UI
\$config['product_name'] = 'SHM Webmail';
\$config['skin'] = 'elastic';
\$config['plugins'] = ['archive', 'zipdownload', 'managesieve', 'password'];

// Logging
\$config['log_driver'] = 'syslog';
\$config['debug_level'] = 1;

// Quota
\$config['quota_zero_as_unlimited'] = true;
?>
RC
fi

# 3. Monitoring Dashboard
log "Setting up Monitoring Dashboard..."
cat > /var/www/apps/monitor/index.php << MONITOR
<?php
// Authentication
if (!isset($_SERVER['PHP_AUTH_USER']) || 
    !password_verify($_SERVER['PHP_AUTH_PW'], password_hash('$ADMIN_PASS', PASSWORD_DEFAULT))) {
    header('WWW-Authenticate: Basic realm="SHM Monitor"');
    header('HTTP/1.0 401 Unauthorized');
    die('Authentication required');
}

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

function getSystemInfo() {
    \$info = [];
    
    // Uptime
    \$uptime = shell_exec('uptime -p');
    \$info['uptime'] = trim(\$uptime);
    
    // Load Average
    \$load = sys_getloadavg();
    \$info['load'] = implode(', ', \$load);
    
    // Memory
    \$meminfo = file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+)/', \$meminfo, \$total);
    preg_match('/MemAvailable:\s+(\d+)/', \$meminfo, \$available);
    \$info['memory'] = [
        'total' => round(\$total[1] / 1024) . ' MB',
        'available' => round(\$available[1] / 1024) . ' MB',
        'percent' => round((1 - \$available[1]/\$total[1]) * 100, 2)
    ];
    
    // Disk
    \$disk = disk_free_space('/');
    \$disk_total = disk_total_space('/');
    \$info['disk'] = [
        'free' => round(\$disk / 1024 / 1024 / 1024, 2) . ' GB',
        'total' => round(\$disk_total / 1024 / 1024 / 1024, 2) . ' GB',
        'percent' => round((1 - \$disk/\$disk_total) * 100, 2)
    ];
    
    // Services
    \$services = ['nginx', 'mysql', 'php8.2-fpm', 'proftpd', 'postfix', 'dovecot', 'redis-server'];
    foreach (\$services as \$service) {
        \$status = shell_exec("systemctl is-active \$service 2>/dev/null");
        \$info['services'][\$service] = trim(\$status) == 'active' ? '✅' : '❌';
    }
    
    // Network
    \$info['ip'] = shell_exec('hostname -I | awk \'{print $1}\'');
    \$info['hostname'] = gethostname();
    
    return \$info;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHM Monitor</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Monaco', 'Courier New', monospace; background: #0f172a; color: #e2e8f0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #60a5fa; margin-bottom: 10px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: #1e293b; border-radius: 10px; padding: 20px; border-left: 4px solid #3b82f6; }
        .card h3 { color: #94a3b8; margin-bottom: 15px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
        .stat { font-size: 24px; font-weight: bold; color: #60a5fa; }
        .stat.small { font-size: 16px; }
        .services { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
        .service { padding: 10px; background: #334155; border-radius: 6px; text-align: center; }
        .refresh { text-align: center; margin-top: 30px; }
        .refresh button { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; }
        .refresh button:hover { background: #2563eb; }
        .critical { color: #f87171 !important; }
        .warning { color: #fbbf24 !important; }
        .good { color: #34d399 !important; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 SHM Panel System Monitor</h1>
            <p><?php echo date('Y-m-d H:i:s'); ?> | <?php echo gethostname(); ?></p>
        </div>
        
        <?php \$info = getSystemInfo(); ?>
        
        <div class="stats-grid">
            <div class="card">
                <h3>System Load</h3>
                <div class="stat"><?php echo \$info['load']; ?></div>
                <p class="stat small">Uptime: <?php echo \$info['uptime']; ?></p>
            </div>
            
            <div class="card">
                <h3>Memory Usage</h3>
                <div class="stat <?php echo \$info['memory']['percent'] > 80 ? 'critical' : (\$info['memory']['percent'] > 60 ? 'warning' : 'good'); ?>">
                    <?php echo \$info['memory']['percent']; ?>%
                </div>
                <p class="stat small"><?php echo \$info['memory']['available']; ?> available of <?php echo \$info['memory']['total']; ?></p>
            </div>
            
            <div class="card">
                <h3>Disk Usage</h3>
                <div class="stat <?php echo \$info['disk']['percent'] > 80 ? 'critical' : (\$info['disk']['percent'] > 60 ? 'warning' : 'good'); ?>">
                    <?php echo \$info['disk']['percent']; ?>%
                </div>
                <p class="stat small"><?php echo \$info['disk']['free']; ?> free</p>
            </div>
            
            <div class="card">
                <h3>Network</h3>
                <div class="stat small">IP: <?php echo \$info['ip']; ?></div>
                <div class="stat small">Hostname: <?php echo \$info['hostname']; ?></div>
            </div>
        </div>
        
        <div class="card">
            <h3>Services Status</h3>
            <div class="services">
                <?php foreach (\$info['services'] as \$name => \$status): ?>
                    <div class="service">
                        <div><?php echo \$name; ?></div>
                        <div style="font-size: 24px;"><?php echo \$status; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="refresh">
            <button onclick="window.location.reload()">🔄 Refresh Status</button>
            <p style="margin-top: 10px; font-size: 12px; color: #94a3b8;">
                Auto-refresh in <span id="countdown">60</span>s
            </p>
        </div>
    </div>
    
    <script>
        let time = 60;
        const countdown = setInterval(() => {
            time--;
            document.getElementById('countdown').textContent = time;
            if (time <= 0) {
                clearInterval(countdown);
                window.location.reload();
            }
        }, 1000);
    </script>
</body>
</html>
MONITOR

# Set Permissions for web apps
chown -R www-data:www-data /var/www/apps
find /var/www/apps -type d -exec chmod 755 {} \;
find /var/www/apps -type f -exec chmod 644 {} \;

# Secure sensitive files
chmod 600 /var/www/apps/phpmyadmin/config.inc.php 2>/dev/null || true
chmod 600 /var/www/apps/webmail/config/config.inc.php 2>/dev/null || true

# --- 5. Service Configuration (SQL Auth) ---
log "Configuring Services..."

# ProFTPD
cat > /etc/proftpd/sql.conf << EOF
<IfModule mod_sql.c>
    SQLBackend mysql
    SQLConnectInfo $DB_NAME@localhost $DB_USER $DB_PASS
    SQLLogFile /var/log/proftpd/sql.log
    SQLAuthenticate users
    SQLAuthTypes Crypt
    SQLUserInfo ftp_users userid passwd uid gid homedir shell
</IfModule>
EOF

# Configure ProFTPD for better security
cat >> /etc/proftpd/proftpd.conf << PROFTPD_EXTRA
# Security
AllowForeignAddress off
PassivePorts 50000 51000
MasqueradeAddress $SERVER_IP

# Limits
MaxInstances 50
MaxClients 20
MaxClientsPerHost 5
MaxLoginAttempts 3

# Logging
LogFormat custom "%{%Y-%m-%d %H:%M:%S}t %u@%h [%r] %s"
ExtendedLog /var/log/proftpd/access.log READ custom
ExtendedLog /var/log/proftpd/auth.log AUTH custom
PROFTPD_EXTRA

if ! grep -q "Include /etc/proftpd/sql.conf" /etc/proftpd/proftpd.conf; then
    echo "Include /etc/proftpd/sql.conf" >> /etc/proftpd/proftpd.conf
fi

# Postfix/Dovecot SQL Configuration
# Create vmail user and directory
groupadd -g 5000 vmail 2>/dev/null || true
useradd -g vmail -u 5000 vmail -d /var/mail/vhosts -m -s /sbin/nologin 2>/dev/null || true
chown -R vmail:vmail /var/mail/vhosts
chmod 750 /var/mail/vhosts

# Dovecot Configuration
cat > /etc/dovecot/dovecot-sql.conf.ext << DOVECOT_SQL
driver = mysql
connect = host=localhost dbname=$DB_NAME user=$DB_USER password=$DB_PASS
default_pass_scheme = SHA512-CRYPT
password_query = SELECT email as user, password FROM mail_users WHERE email='%u' AND is_active=1;
user_query = SELECT 5000 as uid, 5000 as gid, '/var/mail/vhosts/%d/%n' as home, CONCAT('*:bytes=', quota_mb, 'M') as quota_rule FROM mail_users WHERE email='%u';
DOVECOT_SQL

# Update Dovecot auth configuration
sed -i 's/!include auth-system.conf.ext/#!include auth-system.conf.ext/' /etc/dovecot/conf.d/10-auth.conf
sed -i 's/#!include auth-sql.conf.ext/!include auth-sql.conf.ext/' /etc/dovecot/conf.d/10-auth.conf

# Configure main dovecot.conf
cat > /etc/dovecot/dovecot.conf << DOVECOT_MAIN
!include conf.d/*.conf
!include_try local.conf

protocols = imap pop3 lmtp

listen = *, ::

# SSL (will be configured after certbot)
ssl = required
ssl_cert = </etc/letsencrypt/live/$MAIN_DOMAIN/fullchain.pem
ssl_key = </etc/letsencrypt/live/$MAIN_DOMAIN/privkey.pem
ssl_prefer_server_ciphers = yes
ssl_cipher_list = ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512:ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES256-GCM-SHA384

mail_location = maildir:/var/mail/vhosts/%d/%n

auth_mechanisms = plain login
passdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}
userdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}

# Logging
log_path = /var/log/dovecot/dovecot.log
info_log_path = /var/log/dovecot/dovecot-info.log

# LMTP for Postfix
service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

# Authentication socket for Postfix
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0666
    user = postfix
    group = postfix
  }
  unix_listener auth-userdb {
    mode = 0600
    user = vmail
  }
}
DOVECOT_MAIN

# Postfix SQL Maps
cat > /etc/postfix/mysql-virtual-mailbox-domains.cf << POSTFIX_DOMAINS
user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT 1 FROM mail_domains WHERE domain='%s'
POSTFIX_DOMAINS

cat > /etc/postfix/mysql-virtual-mailbox-maps.cf << POSTFIX_MAILBOXES
user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT 1 FROM mail_users WHERE email='%s' AND is_active=1
POSTFIX_MAILBOXES

cat > /etc/postfix/mysql-virtual-alias-maps.cf << POSTFIX_ALIASES
user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT destination FROM mail_aliases WHERE source='%s'
POSTFIX_ALIASES

# Configure Postfix main.cf
postconf -e "myhostname = mail.$MAIN_DOMAIN"
postconf -e "mydomain = $MAIN_DOMAIN"
postconf -e "myorigin = \$mydomain"
postconf -e "mydestination = localhost"
postconf -e "mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128"
postconf -e "inet_interfaces = all"
postconf -e "inet_protocols = all"

# Virtual domains
postconf -e "virtual_mailbox_domains = mysql:/etc/postfix/mysql-virtual-mailbox-domains.cf"
postconf -e "virtual_mailbox_maps = mysql:/etc/postfix/mysql-virtual-mailbox-maps.cf"
postconf -e "virtual_alias_maps = mysql:/etc/postfix/mysql-virtual-alias-maps.cf"
postconf -e "virtual_transport = lmtp:unix:private/dovecot-lmtp"
postconf -e "virtual_mailbox_base = /var/mail/vhosts"
postconf -e "virtual_uid_maps = static:5000"
postconf -e "virtual_gid_maps = static:5000"

# Security
postconf -e "smtpd_tls_security_level = may"
postconf -e "smtpd_tls_auth_only = yes"
postconf -e "smtpd_tls_cert_file = /etc/letsencrypt/live/$MAIN_DOMAIN/fullchain.pem"
postconf -e "smtpd_tls_key_file = /etc/letsencrypt/live/$MAIN_DOMAIN/privkey.pem"
postconf -e "smtpd_tls_session_cache_database = btree:\${data_directory}/smtpd_scache"

# Authentication
postconf -e "smtpd_sasl_type = dovecot"
postconf -e "smtpd_sasl_path = private/auth"
postconf -e "smtpd_sasl_auth_enable = yes"
postconf -e "smtpd_sasl_security_options = noanonymous"
postconf -e "broken_sasl_auth_clients = yes"
postconf -e "smtpd_sasl_local_domain = \$myhostname"

# Restrictions
postconf -e "smtpd_helo_restrictions = permit_mynetworks, reject_invalid_hostname"
postconf -e "smtpd_sender_restrictions = permit_mynetworks"
postconf -e "smtpd_recipient_restrictions = permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination"

# Enable submission port
postconf -e "submission inet n       -       y       -       -       smtpd"
postconf -e "  -o syslog_name=postfix/submission"
postconf -e "  -o smtpd_tls_security_level=encrypt"
postconf -e "  -o smtpd_sasl_auth_enable=yes"
postconf -e "  -o smtpd_client_restrictions=permit_sasl_authenticated,reject"
postconf -e "  -o milter_macro_daemon_name=ORIGINATING"

# --- 6. Nginx Configuration ---
log "Configuring Web Server..."

# Create Nginx cache directories
mkdir -p /var/cache/nginx/{client_temp,proxy_temp,fastcgi_cache}
chown -R www-data:www-data /var/cache/nginx

# Configure Nginx caching
cat > /etc/nginx/conf.d/cache.conf << NGINX_CACHE
proxy_cache_path /var/cache/nginx levels=1:2 keys_zone=STATIC:10m inactive=24h max_size=1g;
proxy_temp_path /var/cache/nginx/tmp;

fastcgi_cache_path /var/cache/nginx/fastcgi levels=1:2 keys_zone=PHP:10m inactive=60m max_size=256m;
fastcgi_cache_key "\$scheme\$request_method\$host\$request_uri";
fastcgi_cache_use_stale error timeout invalid_header http_500;
fastcgi_ignore_headers Cache-Control Expires Set-Cookie;
NGINX_CACHE

# Remove Default site
rm -f /etc/nginx/sites-enabled/default

# Create safe default catch-all
cat > /etc/nginx/sites-available/000-default << DEFAULT
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    
    # Security - reject all traffic to undefined domains
    return 444;
    
    # Or show maintenance page
    # root /var/www/html;
    # index index.html;
    # location / {
    #     try_files \$uri =404;
    # }
}
DEFAULT
ln -sf /etc/nginx/sites-available/000-default /etc/nginx/sites-enabled/

# Define subdomains and their document roots
declare -A SUBDOMAINS=(
    ["admin.$MAIN_DOMAIN"]="/var/www/panel/whm"
    ["client.$MAIN_DOMAIN"]="/var/www/panel/cpanel"
    ["$MAIN_DOMAIN"]="/var/www/panel/landing"
    ["filemanager.$MAIN_DOMAIN"]="/var/www/apps/filemanager"
    ["webmail.$MAIN_DOMAIN"]="/var/www/apps/webmail"
    ["phpmyadmin.$MAIN_DOMAIN"]="/var/www/apps/phpmyadmin"
    ["monitor.$MAIN_DOMAIN"]="/var/www/apps/monitor"
)

# Create HTTP configurations (SSL will be added by certbot)
for sub in "${!SUBDOMAINS[@]}"; do
    cat > /etc/nginx/sites-available/$sub << CONF
server {
    listen 80;
    listen [::]:80;
    server_name $sub;
    
    # Security headers (will be duplicated in SSL config)
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    # Rate limiting
    limit_req_zone \$binary_remote_addr zone=limit_${sub//./_}:10m rate=10r/s;
    
    root ${SUBDOMAINS[$sub]};
    index index.php index.html index.htm;
    
    client_max_body_size 2048M;
    
    # Logging
    access_log /var/log/nginx/${sub//./_}.access.log;
    error_log /var/log/nginx/${sub//./_}.error.log;
    
    location / {
        limit_req zone=limit_${sub//./_} burst=20 nodelay;
        try_files \$uri \$uri/ /index.php?\$args;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        
        # PHP-FPM caching
        fastcgi_cache PHP;
        fastcgi_cache_valid 200 301 302 10m;
        fastcgi_cache_bypass \$http_cache_control;
        add_header X-Cache \$upstream_cache_status;
    }
    
    # Deny access to hidden files
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }
    
    # Deny access to sensitive files
    location ~ /(config\.php|\.sql|\.env|composer\.json|composer\.lock|package\.json|package-lock\.json|yarn\.lock)$ {
        deny all;
        access_log off;
        log_not_found off;
    }
    
    # Static file caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
}
CONF
    
    # Enable site
    ln -sf /etc/nginx/sites-available/$sub /etc/nginx/sites-enabled/
done

# --- 7. SSL/TLS Configuration with Let's Encrypt ---
log "Configuring SSL certificates..."

# Stop Nginx temporarily for certbot
systemctl stop nginx

# Create certbot hook for auto-renewal
mkdir -p /etc/letsencrypt/renewal-hooks/{pre,post,deploy}
cat > /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh << 'CERTBOT_HOOK'
#!/bin/bash
systemctl reload nginx
systemctl reload postfix
systemctl reload dovecot
CERTBOT_HOOK
chmod +x /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh

# Request certificates for all subdomains
CERTBOT_DOMAINS=""
for sub in "${!SUBDOMAINS[@]}"; do
    CERTBOT_DOMAINS="$CERTBOT_DOMAINS -d $sub"
done

if certbot certonly --standalone --non-interactive --agree-tos --email "$ADMIN_EMAIL" \
    $CERTBOT_DOMAINS --expand; then
    log "SSL certificates obtained successfully"
else
    warn "SSL certificate request failed. Continuing with HTTP only."
    # Restart nginx
    systemctl start nginx
fi

# Update Nginx configurations to use SSL
for sub in "${!SUBDOMAINS[@]}"; do
    if [ -f "/etc/letsencrypt/live/$MAIN_DOMAIN/fullchain.pem" ]; then
        # Create SSL configuration
        cat > /etc/nginx/sites-available/$sub-ssl << SSL_CONF
server {
    listen 80;
    listen [::]:80;
    server_name $sub;
    return 301 https://\$server_name\$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $sub;
    
    # SSL certificates
    ssl_certificate /etc/letsencrypt/live/$MAIN_DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$MAIN_DOMAIN/privkey.pem;
    
    # SSL protocols
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512:ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval';" always;
    
    # Rate limiting
    limit_req_zone \$binary_remote_addr zone=ssl_limit_${sub//./_}:10m rate=10r/s;
    
    root ${SUBDOMAINS[$sub]};
    index index.php index.html index.htm;
    
    client_max_body_size 2048M;
    
    # Logging
    access_log /var/log/nginx/${sub//./_}.ssl.access.log;
    error_log /var/log/nginx/${sub//./_}.ssl.error.log;
    
    location / {
        limit_req zone=ssl_limit_${sub//./_} burst=20 nodelay;
        try_files \$uri \$uri/ /index.php?\$args;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        
        # PHP-FPM caching
        fastcgi_cache PHP;
        fastcgi_cache_valid 200 301 302 10m;
        fastcgi_cache_bypass \$http_cache_control;
        add_header X-Cache \$upstream_cache_status;
    }
    
    # Deny access to hidden files
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }
    
    # Deny access to sensitive files
    location ~ /(config\.php|\.sql|\.env|composer\.json|composer\.lock|package\.json|package-lock\.json|yarn\.lock)$ {
        deny all;
        access_log off;
        log_not_found off;
    }
    
    # Static file caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
    
    # Security scan exclusion
    location ~ ^/(wp-admin|wp-login|wp-content|wp-includes) {
        # Additional security for WordPress if installed
        limit_req zone=ssl_limit_${sub//./_} burst=5 nodelay;
    }
}
SSL_CONF
        
        # Replace HTTP config with SSL config
        rm -f /etc/nginx/sites-enabled/$sub
        ln -sf /etc/nginx/sites-available/$sub-ssl /etc/nginx/sites-enabled/
    fi
done

# Start Nginx
systemctl start nginx

# --- 8. Security & Firewall (UFW) ---
log "Configuring Firewall (UFW)..."
ufw --force reset
ufw default deny incoming
ufw default allow outgoing

# Essential ports
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'HTTP'
ufw allow 443/tcp comment 'HTTPS'
ufw allow 53/tcp comment 'DNS TCP'
ufw allow 53/udp comment 'DNS UDP'

# Email ports
ufw allow 25/tcp comment 'SMTP'
ufw allow 587/tcp comment 'SMTP Submission'
ufw allow 465/tcp comment 'SMTPS'
ufw allow 110/tcp comment 'POP3'
ufw allow 143/tcp comment 'IMAP'
ufw allow 993/tcp comment 'IMAPS'
ufw allow 995/tcp comment 'POP3S'

# FTP ports
ufw allow 21/tcp comment 'FTP'
ufw allow 50000:51000/tcp comment 'FTP Passive Ports'

# Enable UFW
echo "y" | ufw enable
ufw status verbose

# Configure Fail2ban
log "Configuring Fail2ban..."
cat > /etc/fail2ban/jail.local << FAIL2BAN
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5
banaction = ufw
backend = systemd
ignoreip = 127.0.0.1/8 ::1

[sshd]
enabled = true
port = ssh
filter = sshd
logpath = /var/log/auth.log

[nginx-http-auth]
enabled = true
port = http,https
filter = nginx-auth
logpath = /var/log/nginx/*error.log

[nginx-botsearch]
enabled = true
port = http,https
filter = nginx-botsearch
logpath = /var/log/nginx/*access.log

[nginx-bad-request]
enabled = true
port = http,https
filter = nginx-bad-request
logpath = /var/log/nginx/*error.log

[nginx-limit-req]
enabled = true
port = http,https
filter = nginx-limit-req
logpath = /var/log/nginx/*error.log

[postfix]
enabled = true
port = smtp,ssmtp,submission
filter = postfix
logpath = /var/log/mail.log

[dovecot]
enabled = true
port = pop3,pop3s,imap,imaps
filter = dovecot
logpath = /var/log/mail.log

[proftpd]
enabled = true
port = ftp,ftp-data,ftps,ftps-data
filter = proftpd
logpath = /var/log/proftpd/proftpd.log

[recidive]
enabled = true
bantime = 604800
findtime = 86400
maxretry = 5
FAIL2BAN

systemctl restart fail2ban

# Configure Automatic Security Updates
log "Configuring automatic security updates..."
apt-get install -y unattended-upgrades apt-listchanges

cat > /etc/apt/apt.conf.d/50unattended-upgrades << UNATTENDED
Unattended-Upgrade::Allowed-Origins {
    "\${distro_id}:\${distro_codename}";
    "\${distro_id}:\${distro_codename}-security";
    "\${distro_id}ESMApps:\${distro_codename}-apps-security";
    "\${distro_id}ESM:\${distro_codename}-infra-security";
};
Unattended-Upgrade::Origins-Pattern {
    "origin=Debian,codename=\${distro_codename},label=Debian-Security";
    "origin=Ubuntu,codename=\${distro_codename},label=Ubuntu";
};
Unattended-Upgrade::AutoFixInterruptedDpkg "true";
Unattended-Upgrade::MinimalSteps "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Automatic-Reboot-Time "02:00";
UNATTENDED

cat > /etc/apt/apt.conf.d/20auto-upgrades << AUTO_UPGRADES
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade "1";
AUTO_UPGRADES

# --- 9. Backup System ---
log "Setting up Backup System..."

# Daily Backups
cat > /etc/cron.daily/shm-backup << CRON_DAILY
#!/bin/bash
# SHM Panel Daily Backup Script
set -e

BACKUP_ROOT="/var/backups/shm"
DATE=\$(date +%Y%m%d_%H%M%S)
LOG_FILE="/var/log/shm/backup-\$DATE.log"

{
    echo "=== SHM Backup started at \$(date) ==="
    
    # 1. Database Backup
    echo "Backing up databases..."
    mysqldump --single-transaction --quick --lock-tables=false --all-databases | gzip > "\$BACKUP_ROOT/db-full-\$DATE.sql.gz"
    
    # 2. Application Backup
    echo "Backing up application files..."
    tar -czf "\$BACKUP_ROOT/app-\$DATE.tar.gz" \
        /var/www/panel \
        /var/www/apps \
        /etc/nginx \
        /etc/php \
        /etc/mysql \
        /etc/shm \
        /usr/local/bin/shm-manage \
        --exclude="*.log" \
        --exclude="*.tmp" \
        --exclude="cache/*" \
        2>/dev/null || true
    
    # 3. Client Data Backup
    echo "Backing up client data..."
    if [ -d "/var/www/clients" ]; then
        tar -czf "\$BACKUP_ROOT/clients-\$DATE.tar.gz" -C /var/www clients
    fi
    
    # 4. Email Backup
    if [ -d "/var/mail/vhosts" ]; then
        tar -czf "\$BACKUP_ROOT/mail-\$DATE.tar.gz" -C /var/mail vhosts
    fi
    
    # 5. Update backup metadata
    echo "Creating backup manifest..."
    cat > "\$BACKUP_ROOT/manifest-\$DATE.txt" << MANIFEST
Backup Date: \$(date)
Server: \$(hostname)
IP: \$(hostname -I | awk '{print \$1}')
Disk Usage:
\$(df -h /)
Memory Usage:
\$(free -h)
Services:
\$(systemctl list-units --type=service --state=running | grep -E "(nginx|mysql|php|proftpd|postfix|dovecot)")
MANIFEST
    
    # 6. Rotate old backups (keep 7 days)
    echo "Rotating old backups..."
    find "\$BACKUP_ROOT" -name "*.gz" -type f -mtime +7 -delete
    find "\$BACKUP_ROOT" -name "*.txt" -type f -mtime +30 -delete
    
    # 7. Update database with backup info
    echo "Updating backup records..."
    DB_SIZE=\$(stat -c%s "\$BACKUP_ROOT/db-full-\$DATE.sql.gz")
    APP_SIZE=\$(stat -c%s "\$BACKUP_ROOT/app-\$DATE.tar.gz")
    TOTAL_SIZE=\$(( (\$DB_SIZE + \$APP_SIZE) / 1024 / 1024 ))
    
    mysql $DB_NAME << SQL
INSERT INTO backups (type, filename, size_mb, status, created_at) VALUES 
('database', 'db-full-\$DATE.sql.gz', \$TOTAL_SIZE, 'completed', NOW()),
('files', 'app-\$DATE.tar.gz', \$TOTAL_SIZE, 'completed', NOW());
SQL
    
    echo "=== Backup completed at \$(date) ==="
    echo "Total size: \$TOTAL_SIZE MB"
    
} >> "\$LOG_FILE" 2>&1

# Compress log and clean up
gzip "\$LOG_FILE"
find "/var/log/shm" -name "*.log.gz" -type f -mtime +30 -delete
CRON_DAILY

chmod +x /etc/cron.daily/shm-backup

# Client-specific backups (via shm-manage)
cat > /etc/cron.hourly/shm-client-backup << CRON_HOURLY
#!/bin/bash
# Hourly client backup check
mysql -N -s -e "SELECT username FROM clients WHERE status='active'" $DB_NAME 2>/dev/null | while read USER; do
    BACKUP_DIR="/var/www/clients/\$USER/backups"
    if [ -d "\$BACKUP_DIR" ]; then
        # Delete backups older than 7 days
        find "\$BACKUP_DIR" -type f -name "*.tar.gz" -mtime +7 -delete
    fi
done
CRON_HOURLY
chmod +x /etc/cron.hourly/shm-client-backup

# Traffic Stats Update
cat > /etc/cron.hourly/shm-traffic << CRON_TRAFFIC
#!/bin/bash
/usr/local/bin/shm-manage update-traffic-stats 2>/dev/null || true
CRON_TRAFFIC
chmod +x /etc/cron.hourly/shm-traffic

# --- 10. Monitoring System ---
log "Setting up monitoring..."

# System metrics collection
cat > /etc/cron.minutely/shm-metrics << CRON_MINUTELY
#!/bin/bash
# Collect system metrics
CPU=\$(top -bn1 | grep "Cpu(s)" | awk '{print 100 - \$8}')
MEM=\$(free | awk '/Mem:/ {printf "%.2f", \$3/\$2 * 100}')
DISK=\$(df / | awk 'NR==2 {print \$5}' | sed 's/%//')
LOAD=\$(uptime | awk -F'load average:' '{print \$2}' | awk '{print \$1}' | sed 's/,//')

mysql $DB_NAME << SQL
INSERT INTO server_metrics (cpu_percent, memory_percent, disk_percent, load_avg) 
VALUES (\$CPU, \$MEM, \$DISK, \$LOAD);
SQL

# Clean old metrics (keep 7 days)
mysql $DB_NAME -e "DELETE FROM server_metrics WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY);"
CRON_MINUTELY

chmod +x /etc/cron.minutely/shm-metrics
ln -sf /etc/cron.minutely/shm-metrics /etc/cron.d/

# Log rotation for SHM
cat > /etc/logrotate.d/shm << LOGROTATE
/var/log/shm/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 640 www-data www-data
    sharedscripts
    postrotate
        [ -f /var/run/nginx.pid ] && kill -USR1 \`cat /var/run/nginx.pid\`
    endscript
}
LOGROTATE

# --- 11. Final Service Restart ---
log "Restarting Services..."
services=("nginx" "mysql" "php8.2-fpm" "proftpd" "postfix" "dovecot" "redis-server" "fail2ban" "clamav-daemon")

for service in "${services[@]}"; do
    if systemctl is-enabled "$service" &>/dev/null; then
        systemctl restart "$service" && log "✓ $service restarted" || warn "⚠ $service restart failed"
    fi
done

# --- 12. Installation Verification ---
verify_installation() {
    log "Verifying installation..."
    
    local errors=0
    local warnings=0
    
    echo -e "${CYAN}"
    echo "================================================"
    echo "         INSTALLATION VERIFICATION"
    echo "================================================"
    echo -e "${NC}"
    
    # Check services
    for service in "${services[@]}"; do
        if systemctl is-active --quiet "$service"; then
            echo -e "${GREEN}✓ $service: ACTIVE${NC}"
        else
            echo -e "${RED}✗ $service: INACTIVE${NC}"
            ((errors++))
        fi
    done
    
    # Check ports
    declare -A PORTS=(
        ["80 (HTTP)"]="curl -s -o /dev/null -w '%{http_code}' http://localhost"
        ["443 (HTTPS)"]="curl -s -o /dev/null -w '%{http_code}' https://localhost --insecure"
        ["22 (SSH)"]="nc -z localhost 22"
        ["3306 (MySQL)"]="nc -z localhost 3306"
        ["21 (FTP)"]="nc -z localhost 21"
        ["25 (SMTP)"]="nc -z localhost 25"
    )
    
    echo -e "\n${CYAN}Port Checks:${NC}"
    for port in "${!PORTS[@]}"; do
        if eval "${PORTS[$port]}" &>/dev/null; then
            echo -e "${GREEN}✓ $port: LISTENING${NC}"
        else
            echo -e "${YELLOW}⚠ $port: NOT LISTENING${NC}"
            ((warnings++))
        fi
    done
    
    # Check PHP
    if php -v &>/dev/null; then
        echo -e "${GREEN}✓ PHP: WORKING${NC}"
    else
        echo -e "${RED}✗ PHP: FAILED${NC}"
        ((errors++))
    fi
    
    # Check MySQL
    if mysql -e "SELECT 1" &>/dev/null; then
        echo -e "${GREEN}✓ MySQL: WORKING${NC}"
    else
        echo -e "${RED}✗ MySQL: FAILED${NC}"
        ((errors++))
    fi
    
    # Check disk space
    DISK_USAGE=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//')
    if [ "$DISK_USAGE" -lt 90 ]; then
        echo -e "${GREEN}✓ Disk Space: ${DISK_USAGE}% used${NC}"
    else
        echo -e "${RED}✗ Disk Space: ${DISK_USAGE}% used (CRITICAL)${NC}"
        ((errors++))
    fi
    
    # Check memory
    MEM_FREE=$(free -m | awk '/Mem:/ {print $4}')
    if [ "$MEM_FREE" -gt 100 ]; then
        echo -e "${GREEN}✓ Free Memory: ${MEM_FREE}MB${NC}"
    else
        echo -e "${YELLOW}⚠ Free Memory: ${MEM_FREE}MB (LOW)${NC}"
        ((warnings++))
    fi
    
    # Test web endpoints
    echo -e "\n${CYAN}Web Endpoints:${NC}"
    for sub in admin client webmail; do
        URL="http://$sub.$MAIN_DOMAIN"
        if curl -s -f "$URL" &>/dev/null; then
            echo -e "${GREEN}✓ $URL: ACCESSIBLE${NC}"
        else
            echo -e "${YELLOW}⚠ $URL: UNAVAILABLE${NC}"
            ((warnings++))
        fi
    done
    
    # Summary
    echo -e "\n${CYAN}================================================${NC}"
    if [ $errors -eq 0 ] && [ $warnings -eq 0 ]; then
        echo -e "${GREEN}✅ All checks passed! Installation successful.${NC}"
    elif [ $errors -eq 0 ]; then
        echo -e "${YELLOW}⚠ Installation completed with $warnings warnings.${NC}"
    else
        echo -e "${RED}❌ Installation has $errors errors and $warnings warnings.${NC}"
    fi
    echo -e "${CYAN}================================================${NC}"
}

verify_installation

# --- 13. Final Output ---
echo -e "${GREEN}"
echo "================================================"
echo "   SHM PANEL INSTALLED SUCCESSFULLY"
echo "================================================"
echo -e "${NC}"
echo -e "${CYAN}🔗 Access URLs:${NC}"
echo -e "  Admin Panel:    https://admin.$MAIN_DOMAIN"
echo -e "  Client Panel:   https://client.$MAIN_DOMAIN"
echo -e "  Webmail:        https://webmail.$MAIN_DOMAIN"
echo -e "  phpMyAdmin:     https://phpmyadmin.$MAIN_DOMAIN"
echo -e "  Monitor:        https://monitor.$MAIN_DOMAIN"
echo -e "  File Manager:   https://filemanager.$MAIN_DOMAIN"
echo -e "  Landing Page:   https://$MAIN_DOMAIN"
echo ""
echo -e "${CYAN}🔑 Credentials:${NC}"
echo -e "  Admin Username: admin"
echo -e "  Admin Password: $ADMIN_PASS"
echo -e "  MySQL Root:     root / $MYSQL_ROOT_PASS"
echo -e "  Panel DB User:  $DB_USER / $DB_PASS"
echo ""
echo -e "${CYAN}📁 Directories:${NC}"
echo -e "  Web Root:       /var/www/panel/"
echo -e "  Client Homes:   /var/www/clients/"
echo -e "  Apps:           /var/www/apps/"
echo -e "  Logs:           /var/log/shm/"
echo -e "  Backups:        /var/backups/shm/"
echo -e "  Config:         /etc/shm/"
echo ""
echo -e "${CYAN}⚙️  Management:${NC}"
echo -e "  Backend Tool:   /usr/local/bin/shm-manage"
echo -e "  Service Check:  systemctl status nginx mysql php8.2-fpm"
echo -e "  Monitor Access: https://monitor.$MAIN_DOMAIN"
echo ""
echo -e "${YELLOW}⚠️  SECURITY NOTES:${NC}"
echo -e "  1. Change ALL passwords immediately!"
echo -e "  2. Configure firewall rules for your needs"
echo -e "  3. Set up monitoring alerts"
echo -e "  4. Regular backups are in /var/backups/shm/"
echo -e "  5. Review /etc/shm/config.sh for sensitive data"
echo ""
echo -e "${GREEN}✅ Installation completed at $(date)${NC}"
echo -e "${GREEN}🔧 Server IP: $SERVER_IP${NC}"
echo -e "${GREEN}📧 Admin Email: $ADMIN_EMAIL${NC}"
echo "================================================"

# Save credentials to secure file
cat > /root/shm-credentials.txt << CREDENTIALS
SHM Panel Installation Credentials
==================================
Installation Date: $(date)
Server: $(hostname)
IP Address: $SERVER_IP
Main Domain: $MAIN_DOMAIN

ACCESS URLS:
- Admin Panel:    https://admin.$MAIN_DOMAIN
- Client Panel:   https://client.$MAIN_DOMAIN
- Webmail:        https://webmail.$MAIN_DOMAIN
- phpMyAdmin:     https://phpmyadmin.$MAIN_DOMAIN
- Monitor:        https://monitor.$MAIN_DOMAIN

CREDENTIALS:
- Admin Username: admin
- Admin Password: $ADMIN_PASS
- MySQL Root:     root / $MYSQL_ROOT_PASS
- Panel DB User:  $DB_USER / $DB_PASS

DIRECTORIES:
- Web Root:       /var/www/panel/
- Client Homes:   /var/www/clients/
- Backups:        /var/backups/shm/
- Config:         /etc/shm/

MANAGEMENT:
- Backend Tool:   /usr/local/bin/shm-manage
- Service Check:  systemctl status nginx mysql php8.2-fpm

SECURITY:
- Firewall:       ufw status
- Fail2ban:       fail2ban-client status
- SSL:            certbot certificates

BACKUP LOCATION: /root/shm-backup-$(date +%Y%m%d_%H%M%S)/
==================================
SAVE THIS FILE IN A SECURE LOCATION!
CREDENTIALS
chmod 600 /root/shm-credentials.txt

log "Credentials saved to /root/shm-credentials.txt"
warn "Please download and securely store the credentials file!"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Access https://admin.$MAIN_DOMAIN and login with admin/$ADMIN_PASS"
echo "2. Change all passwords immediately"
echo "3. Configure DNS to point to $SERVER_IP"
echo "4. Set up monitoring alerts"
echo "5. Test backup restoration"
echo ""
echo -e "${GREEN}Installation completed successfully!${NC}"