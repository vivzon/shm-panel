#!/bin/bash

# ==============================================================================
# SHM PANEL - PRODUCTION INSTALLER (v5.1 Stable)
# ==============================================================================
# This script deploys the SHM Panel project to your server.
# Run this script as root from the directory containing the project files.
# ==============================================================================

export DEBIAN_FRONTEND=noninteractive

# --- Colors & Logging ---
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() { echo -e "${GREEN}[INSTALLER] $1${NC}"; }
warn() { echo -e "${YELLOW}[WARNING] $1${NC}"; }
error() { echo -e "${RED}[ERROR] $1${NC}"; exit 1; }

# --- 0. Pre-Flight Checks ---
if [ "$EUID" -ne 0 ]; then error "Please run as root (sudo ./install.sh)"; fi

if [ ! -f "shm-manage" ]; then
    error "File 'shm-manage' not found in current directory. Please ensure you are in the project root."
fi

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$NAME
    VER=$VERSION_ID
    if [[ "$OS" != *"Ubuntu"* && "$OS" != *"Debian"* ]]; then
        warn "Detected OS: $OS. This installer is optimized for Ubuntu 20.04+/Debian 11+."
        read -p "Continue anyway? (y/n) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then exit 1; fi
    fi
fi

# --- Configuration ---
clear
echo -e "${BLUE}"
echo "  _____________________________________"
echo " / SHM Panel - Installation Wizard    \\"
echo " \____________________________________/"
echo -e "${NC}"

if [ -z "$MAIN_DOMAIN" ]; then
    read -p "Enter Main Domain (e.g. vivzon.cloud): " MAIN_DOMAIN
fi

if [ -z "$ADMIN_EMAIL" ]; then
    read -p "Enter Admin Email (e.g. admin@vivzon.cloud): " ADMIN_EMAIL
fi

# Fallback defaults
MAIN_DOMAIN=${MAIN_DOMAIN:-vivzon.cloud}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@$MAIN_DOMAIN}
DB_NAME="shm_panel"
DB_USER="shm_admin"
DB_PASS=$(openssl rand -base64 16)
MYSQL_ROOT_PASS=$(openssl rand -base64 18)

cat <<INFO
-----------------------------------------------
Target Domain:  $MAIN_DOMAIN
Admin Email:    $ADMIN_EMAIL
Database Name:  $DB_NAME
Database User:  $DB_USER
-----------------------------------------------
INFO

# --- 1. System Prep ---
log "Updating System & Installing Dependencies..."
apt-get update
apt-get upgrade -y
# Install essential core packages
apt-get install -y software-properties-common curl wget git zip unzip ufw fail2ban acl quota jq clamav clamav-daemon

# Install Web Stack & Mail Stack
apt-get install -y certbot python3-certbot-nginx bind9
apt-get install -y dovecot-core dovecot-imapd dovecot-pop3d dovecot-mysql postfix postfix-mysql
apt-get install -y proftpd-basic proftpd-mod-mysql 
apt-get install -y mariadb-server mariadb-client

# Add PHP Repo
add-apt-repository ppa:ondrej/php -y
apt-get update

# Install PHP Versions (8.1, 8.2, 8.3) - 8.2 is Default
log "Installing PHP Versions..."
for v in 8.1 8.2 8.3; do
    apt-get install -y php$v-fpm php$v-mysql php$v-common php$v-gd php$v-mbstring php$v-xml php$v-zip php$v-curl php$v-bcmath php$v-intl php$v-imagick php$v-cli
    
    # Configure PHP Limits (Global)
    sed -i "s/upload_max_filesize = .*/upload_max_filesize = 2048M/" /etc/php/$v/fpm/php.ini
    sed -i "s/post_max_size = .*/post_max_size = 2048M/" /etc/php/$v/fpm/php.ini
    sed -i "s/memory_limit = .*/memory_limit = 2048M/" /etc/php/$v/fpm/php.ini
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
    apt-get install -y nodejs
fi

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

# --- 2. Database Setup ---
log "Configuring Database (MariaDB)..."

# Secure MariaDB
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS';"
cat > /root/.my.cnf << EOF
[client]
user=root
password=$MYSQL_ROOT_PASS
EOF
chmod 600 /root/.my.cnf

# Create App DB
mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# Import Schema
log "Importing Schema..."
mysql $DB_NAME << SQL
CREATE TABLE IF NOT EXISTS clients (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(32) UNIQUE, email VARCHAR(255), password VARCHAR(255), status ENUM('active','suspended') DEFAULT 'active', package_id INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS domains (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, domain VARCHAR(255) UNIQUE, document_root VARCHAR(255), php_version VARCHAR(5) DEFAULT '8.2', ssl_active BOOLEAN DEFAULT 0, parent_id INT DEFAULT NULL, FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS packages (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), price DECIMAL(10,2) DEFAULT 0.00, disk_mb INT, max_domains INT, max_emails INT, max_databases INT DEFAULT 5);
CREATE TABLE IF NOT EXISTS transactions (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, amount DECIMAL(10,2), currency VARCHAR(10), payment_gateway VARCHAR(20), transaction_id VARCHAR(100), status VARCHAR(20), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE, password VARCHAR(255));
CREATE TABLE IF NOT EXISTS mail_domains (id INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255) UNIQUE);
CREATE TABLE IF NOT EXISTS mail_users (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, email VARCHAR(255) UNIQUE, password VARCHAR(255));
CREATE TABLE IF NOT EXISTS ftp_users (id INT AUTO_INCREMENT PRIMARY KEY, userid VARCHAR(32) UNIQUE, passwd VARCHAR(255), homedir VARCHAR(255), uid INT DEFAULT 33, gid INT DEFAULT 33, shell VARCHAR(255) DEFAULT '/sbin/nologin');
CREATE TABLE IF NOT EXISTS client_databases (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, db_name VARCHAR(64) UNIQUE);
CREATE TABLE IF NOT EXISTS client_db_users (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, db_user VARCHAR(32));
CREATE TABLE IF NOT EXISTS dns_records (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, type VARCHAR(10), host VARCHAR(255), value VARCHAR(255));
CREATE TABLE IF NOT EXISTS php_config (domain_id INT PRIMARY KEY, memory_limit VARCHAR(10) DEFAULT '128M');
CREATE TABLE IF NOT EXISTS domain_traffic (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, date DATE, bytes_sent BIGINT DEFAULT 0, hits INT DEFAULT 0, UNIQUE KEY (domain_id, date));
CREATE TABLE IF NOT EXISTS domain_traffic (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, date DATE, bytes_sent BIGINT DEFAULT 0, hits INT DEFAULT 0, UNIQUE KEY (domain_id, date));
CREATE TABLE IF NOT EXISTS malware_scans (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, status ENUM('running','clean','infected','failed'), report TEXT, scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS app_installations (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, domain_id INT, app_type VARCHAR(20), db_name VARCHAR(64), db_user VARCHAR(32), db_pass VARCHAR(255), status VARCHAR(20), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);


-- Default Data
INSERT IGNORE INTO packages VALUES (1, 'Starter', 0.00, 2000, 1, 5, 2), (2, 'Business', 9.99, 10000, 10, 50, 10);
-- Admin: admin / admin123 (bcrypt hash)
INSERT IGNORE INTO admins (username, password) VALUES ('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
SQL

# Hardening MariaDB
mysql -e "DELETE FROM mysql.user WHERE User='';"
mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
mysql -e "DROP DATABASE IF EXISTS test;"
mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
mysql -e "FLUSH PRIVILEGES;"

# --- 3. Backend Deployment ---
log "Deploying Backend Engine (shm-manage)..."
cp shm-manage /usr/local/bin/shm-manage
chmod +x /usr/local/bin/shm-manage

mkdir -p /etc/shm
cat > /etc/shm/config.sh << CONFIG
DB_NAME='$DB_NAME'
DB_USER='$DB_USER'
DB_PASS='$DB_PASS'
MAIN_DOMAIN='$MAIN_DOMAIN'
ADMIN_EMAIL='$ADMIN_EMAIL'
CONFIG

# Allow Web Server to run shm-manage via sudo
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/shm-manage" > /etc/sudoers.d/shm
chmod 0440 /etc/sudoers.d/shm

# --- 4. Frontend Deployment ---
log "Deploying Frontend Files..."
# Create Directories
mkdir -p /var/www/panel/{whm,cpanel,shared,landing}
mkdir -p /var/www/clients
mkdir -p /var/www/apps
# Link Shared for auto-updates if needed
ln -sf /var/www/panel/shared /var/www/apps/shared

# Copy Files
# Ensure the source directories exist locally
if [ -d "whm" ]; then cp -r whm/* /var/www/panel/whm/; fi
if [ -d "cpanel" ]; then cp -r cpanel/* /var/www/panel/cpanel/; fi
if [ -d "landing" ]; then cp -r landing/* /var/www/panel/landing/ 2>/dev/null || echo "<h1>Welcome to SHM</h1>" > /var/www/panel/landing/index.html; fi
if [ -d "shared" ]; then cp shared/config.php /var/www/panel/shared/config.php; fi

# File Manager Setup
mkdir -p /var/www/apps/filemanager
if [ -f "cpanel/files.php" ]; then cp cpanel/files.php /var/www/apps/filemanager/index.php; fi
if [ -f "cpanel/login.php" ]; then cp cpanel/login.php /var/www/apps/filemanager/login.php; fi

# Update Config with Real Password
sed -i "s/SHMPanel_Secure_Pass_2025/$DB_PASS/" /var/www/panel/shared/config.php

# --- 4a. Install Web Apps (PMA, Roundcube) ---
log "Installing Web Apps (phpMyAdmin, Roundcube)..."

# 1. phpMyAdmin
if [ ! -d "/var/www/apps/phpmyadmin" ]; then
    mkdir -p /var/www/apps/phpmyadmin
    wget -q https://files.phpmyadmin.net/phpMyAdmin/5.2.1/phpMyAdmin-5.2.1-all-languages.zip -O /tmp/pma.zip
    unzip -q /tmp/pma.zip -d /tmp/
    mv /tmp/phpMyAdmin-5.2.1-all-languages/* /var/www/apps/phpmyadmin/
    rm -rf /tmp/pma*
    
    # PMA Config
    cat > /var/www/apps/phpmyadmin/config.inc.php << PMA
<?php
\$i = 0;
\$i++;
\$cfg['Servers'][\$i]['auth_type'] = 'cookie';
\$cfg['Servers'][\$i]['host'] = 'localhost';
\$cfg['Servers'][\$i]['compress'] = false;
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;
\$cfg['UploadDir'] = '';
\$cfg['SaveDir'] = '';
?>
PMA
fi

# 2. Roundcube Webmail
if [ ! -d "/var/www/apps/webmail" ]; then
    mkdir -p /var/www/apps/webmail
    wget -q https://github.com/roundcube/roundcubemail/releases/download/1.6.6/roundcubemail-1.6.6-complete.tar.gz -O /tmp/rc.tar.gz
    tar -xf /tmp/rc.tar.gz -C /tmp/
    mv /tmp/roundcubemail-1.6.6/* /var/www/apps/webmail/
    rm -rf /tmp/rc*
    
    # DB for Roundcube
    mysql -e "CREATE DATABASE IF NOT EXISTS roundcube;"
    mysql -e "GRANT ALL PRIVILEGES ON roundcube.* TO '$DB_USER'@'localhost';"
    mysql roundcube < /var/www/apps/webmail/SQL/mysql.initial.sql
    
    cat > /var/www/apps/webmail/config/config.inc.php << RC
<?php
\$config['db_dsnw'] = 'mysql://$DB_USER:$DB_PASS@localhost/roundcube';
\$config['default_host'] = 'localhost';
\$config['smtp_server'] = 'localhost';
\$config['smtp_port'] = 25;
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';
\$config['support_url'] = '';
\$config['product_name'] = 'SHM Webmail';
\$config['des_key'] = '$(openssl rand -hex 12)';
\$config['plugins'] = ['archive', 'zipdownload'];
?>
RC
fi

# Set Permissions
chown -R www-data:www-data /var/www/panel /var/www/apps
chmod -R 755 /var/www/panel

# --- 5. Service Configuration (SQL Auth) ---
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
if ! grep -q "Include /etc/proftpd/sql.conf" /etc/proftpd/proftpd.conf; then
    echo "Include /etc/proftpd/sql.conf" >> /etc/proftpd/proftpd.conf
fi

# Postfix/Dovecot
cat > /etc/dovecot/dovecot-sql.conf.ext << EOF
driver = mysql
connect = host=localhost dbname=$DB_NAME user=$DB_USER password=$DB_PASS
default_pass_scheme = BLF-CRYPT
password_query = SELECT email as user, password FROM mail_users WHERE email='%u';
user_query = SELECT 5000 as uid, 5000 as gid, '/var/mail/vhosts/%d/%n' as home;
EOF

# Fix Dovecot Auth
sed -i 's/!include auth-system.conf.ext/#!include auth-system.conf.ext/' /etc/dovecot/conf.d/10-auth.conf
sed -i 's/#!include auth-sql.conf.ext/!include auth-sql.conf.ext/' /etc/dovecot/conf.d/10-auth.conf

# Postfix SQL Maps
cat > /etc/postfix/mysql-virtual-mailbox-domains.cf << EOF
user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT 1 FROM mail_domains WHERE domain='%s'
EOF

cat > /etc/postfix/mysql-virtual-mailbox-maps.cf << EOF
user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT 1 FROM mail_users WHERE email='%s'
EOF

# Configure Postfix Main.cf (Basic)
postconf -e "virtual_mailbox_domains = mysql:/etc/postfix/mysql-virtual-mailbox-domains.cf"
postconf -e "virtual_mailbox_maps = mysql:/etc/postfix/mysql-virtual-mailbox-maps.cf"

# --- 6. Nginx VHost Setup ---
log "Configuring Web Server..."

declare -A SUBDOMAINS=(
    ["admin.$MAIN_DOMAIN"]="/var/www/panel/whm"
    ["client.$MAIN_DOMAIN"]="/var/www/panel/cpanel"
    ["$MAIN_DOMAIN"]="/var/www/panel/landing"
    ["filemanager.$MAIN_DOMAIN"]="/var/www/apps/filemanager"
    ["webmail.$MAIN_DOMAIN"]="/var/www/apps/webmail"
    ["phpmyadmin.$MAIN_DOMAIN"]="/var/www/apps/phpmyadmin"
)

# Remove Default
rm -f /etc/nginx/sites-enabled/default

for sub in "${!SUBDOMAINS[@]}"; do
    cat > /etc/nginx/sites-available/$sub << CONF
server {
    listen 80;
    server_name $sub;
    root ${SUBDOMAINS[$sub]};
    index index.php index.html;
    
    client_max_body_size 2048M;
    
    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny dotfiles
    location ~ /\. { deny all; }
}
CONF
    ln -sf /etc/nginx/sites-available/$sub /etc/nginx/sites-enabled/
    ln -sf /etc/nginx/sites-available/$sub /etc/nginx/sites-enabled/
done

# --- 7. Security & Firewall (UFW) ---
log "Configuring Firewall (UFW)..."
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp         # SSH
ufw allow 80/tcp         # HTTP
ufw allow 443/tcp        # HTTPS
ufw allow 53             # DNS
ufw allow 21/tcp         # FTP
ufw allow 25/tcp         # SMTP
ufw allow 587/tcp        # SMTP Submission
ufw allow 465/tcp        # SMTPS
ufw allow 110/tcp        # POP3
ufw allow 143/tcp        # IMAP
ufw allow 993/tcp        # IMAPS
ufw allow 995/tcp        # POP3S
# ProFTPD Passive Ports (Match config if set, usually defaults need range)
# Assuming non-passive or existing config handles it, but opening range is safe:
ufw allow 49152:65534/tcp

# Enable Non-Interactive
echo "y" | ufw enable

# --- 8. Auto Backup Cron ---
log "Setting up Daily Backups..."
cat > /etc/cron.daily/shm-backup << CRON
#!/bin/bash
# Backup all clients
mysql -N -s -e "SELECT username FROM clients" $DB_NAME | while read USER; do
  /usr/local/bin/shm-manage backup create \$USER
  # Delete backups older than 7 days
  find /var/www/clients/\$USER/backups -type f -name "*.tar.gz" -mtime +7 -delete
done

# Update Traffic Stats
/usr/local/bin/shm-manage update-traffic-stats
CRON
chmod +x /etc/cron.daily/shm-backup

# --- 9. Finalize ---
log "Restarting Services..."
systemctl restart nginx mysql php8.2-fpm proftpd postfix dovecot

if systemctl is-active --quiet nginx; then
    log "Nginx is RUNNING."
else
    error "Nginx failed to start. Check /var/log/nginx/error.log"
fi

echo -e "${GREEN}"
echo "================================================"
echo "   SHM PANEL INSTALLED SUCCESSFULLY"
echo "================================================"
echo "Admin Panel:   http://admin.$MAIN_DOMAIN"
echo "Client Panel:  http://client.$MAIN_DOMAIN"
echo "Webmail:       http://webmail.$MAIN_DOMAIN"
echo "------------------------------------------------"
echo "Admin Cloud User: admin"
echo "Admin Cloud Pass: admin123"
echo "------------------------------------------------"
echo "DB Password:      $DB_PASS"
echo "Root SQL Pass:    $MYSQL_ROOT_PASS"
echo "------------------------------------------------"
echo "SAVE THESE CREDENTIALS!"
echo -e "${NC}"
