#!/bin/bash

# ==============================================================================
# SHM PANEL - PRODUCTION INSTALLER
# ==============================================================================
# This script deploys the local SHM Panel project to your server.
# Run this script as root from the directory containing the project files.
# ==============================================================================

export DEBIAN_FRONTEND=noninteractive

# --- Configuration ---
# --- Configuration ---
if [ -z "$MAIN_DOMAIN" ]; then
    read -p "Enter Main Domain (e.g. example.com): " MAIN_DOMAIN
fi

if [ -z "$ADMIN_EMAIL" ]; then
    read -p "Enter Admin Email: " ADMIN_EMAIL
fi

# Fallback defaults if empty
MAIN_DOMAIN=${MAIN_DOMAIN:-vivzon.cloud}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@$MAIN_DOMAIN}
DB_NAME="shm_panel"
DB_USER="shm_admin"
DB_PASS=$(openssl rand -base64 16)
MYSQL_ROOT_PASS=$(openssl rand -base64 18)

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m'

log() { echo -e "${GREEN}[$(date +'%H:%M:%S')] $1${NC}"; }
error() { echo -e "${RED}[ERROR] $1${NC}"; }

if [ "$EUID" -ne 0 ]; then error "Please run as root"; exit 1; fi

# --- 1. System Prep ---
log "Updating System & Installing Dependencies..."
apt update && apt upgrade -y
apt install -y software-properties-common curl wget git zip unzip ufw fail2ban certbot python3-certbot-nginx acl quota bind9 dovecot-core dovecot-imapd dovecot-pop3d dovecot-mysql postfix postfix-mysql proftpd-basic proftpd-mod-mysql mariadb-server mariadb-client

add-apt-repository ppa:ondrej/php -y
apt update

# Install PHP Versions
for v in 8.1 8.2 8.3; do
    apt install -y php$v-fpm php$v-mysql php$v-common php$v-gd php$v-mbstring php$v-xml php$v-zip php$v-curl php$v-bcmath php$v-intl php$v-imagick php$v-cli
done

# --- 2. Database Setup ---
log "Configuring Database..."
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS';"
cat > /root/.my.cnf << EOF
[client]
user=root
password=$MYSQL_ROOT_PASS
EOF
chmod 600 /root/.my.cnf

mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# Import Schema
log "Importing Schema..."
# (Consolidated Schema from Analysis)
mysql $DB_NAME << EOF
CREATE TABLE IF NOT EXISTS clients (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(32) UNIQUE, email VARCHAR(255), password VARCHAR(255), status ENUM('active','suspended') DEFAULT 'active', package_id INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS domains (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, domain VARCHAR(255) UNIQUE, document_root VARCHAR(255), php_version VARCHAR(5) DEFAULT '8.2', ssl_active BOOLEAN DEFAULT 0, FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS packages (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), disk_mb INT, max_domains INT, max_emails INT, max_databases INT DEFAULT 5);
CREATE TABLE IF NOT EXISTS admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE, password VARCHAR(255));
CREATE TABLE IF NOT EXISTS mail_domains (id INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255) UNIQUE);
CREATE TABLE IF NOT EXISTS mail_users (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, email VARCHAR(255) UNIQUE, password VARCHAR(255));
CREATE TABLE IF NOT EXISTS ftp_users (id INT AUTO_INCREMENT PRIMARY KEY, userid VARCHAR(32) UNIQUE, passwd VARCHAR(255), homedir VARCHAR(255), uid INT DEFAULT 33, gid INT DEFAULT 33, shell VARCHAR(255) DEFAULT '/sbin/nologin');
CREATE TABLE IF NOT EXISTS client_databases (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, db_name VARCHAR(64) UNIQUE);
CREATE TABLE IF NOT EXISTS client_db_users (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, db_user VARCHAR(32));
CREATE TABLE IF NOT EXISTS dns_records (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, type VARCHAR(10), host VARCHAR(255), value VARCHAR(255));
CREATE TABLE IF NOT EXISTS php_config (domain_id INT PRIMARY KEY, memory_limit VARCHAR(10) DEFAULT '128M');

-- Default Data
INSERT IGNORE INTO packages VALUES (1, 'Starter', 2000, 1, 5, 2), (2, 'Business', 10000, 10, 50, 10);
INSERT IGNORE INTO admins (username, password) VALUES ('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'); -- admin / admin123
EOF

# --- 3. Backend Deployment ---
log "Deploying Backend Engine..."
cp shm-manage /usr/local/bin/shm-manage
chmod +x /usr/local/bin/shm-manage
mkdir -p /etc/shm
echo "DB_NAME='$DB_NAME'" > /etc/shm/config.sh
echo "MAIN_DOMAIN='$MAIN_DOMAIN'" >> /etc/shm/config.sh
echo "ADMIN_EMAIL='$ADMIN_EMAIL'" >> /etc/shm/config.sh

# Sudoers
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/shm-manage" > /etc/sudoers.d/shm

# --- 4. Frontend Deployment ---
log "Deploying Frontend Files..."
mkdir -p /var/www/panel/{whm,cpanel,shared,landing}
mkdir -p /var/www/clients
mkdir -p /var/www/apps
ln -sf /var/www/panel/shared /var/www/apps/shared

# File Manager (Native Vivzon FM)
mkdir -p /var/www/apps/filemanager
cp cpanel/files.php /var/www/apps/filemanager/index.php

cp -r whm/* /var/www/panel/whm/
cp -r cpanel/* /var/www/panel/cpanel/
cp -r landing/* /var/www/panel/landing/ 2>/dev/null || echo "<h1>Welcome</h1>" > /var/www/panel/landing/index.html
cp shared_config.php /var/www/panel/shared/config.php

# --- 4a. Install Web Apps ---
log "Installing Web Apps..."

# 1. phpMyAdmin
mkdir -p /var/www/apps/phpmyadmin
wget https://files.phpmyadmin.net/phpMyAdmin/5.2.1/phpMyAdmin-5.2.1-all-languages.zip -O /tmp/pma.zip
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

# 2. Roundcube Webmail
mkdir -p /var/www/apps/webmail
wget https://github.com/roundcube/roundcubemail/releases/download/1.6.6/roundcubemail-1.6.6-complete.tar.gz -O /tmp/rc.tar.gz
tar -xf /tmp/rc.tar.gz -C /tmp/
mv /tmp/roundcubemail-1.6.6/* /var/www/apps/webmail/
rm -rf /tmp/rc*
# Roundcube Config (Auto-create RC DB)
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
\$config['des_key'] = 'rc_secret_key_change_me';
\$config['plugins'] = ['archive', 'zipdownload'];
?>
RC
chown -R www-data:www-data /var/www/apps

# Update Config with Real Password
sed -i "s/SHMPanel_Secure_Pass_2025/$DB_PASS/" /var/www/panel/shared/config.php

# Permissions
chown -R www-data:www-data /var/www/panel
chmod -R 755 /var/www/panel

# --- 4b. Service Configuration (SQL Auth) ---
log "Configuring Services for SQL..."

# ProFTPD SQL Config
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
sed -i 's|#Include /etc/proftpd/sql.conf|Include /etc/proftpd/sql.conf|' /etc/proftpd/proftpd.conf

# Postfix/Dovecot SQL Config
cat > /etc/dovecot/dovecot-sql.conf.ext << EOF
driver = mysql
connect = host=localhost dbname=$DB_NAME user=$DB_USER password=$DB_PASS
default_pass_scheme = BLF-CRYPT
password_query = SELECT email as user, password FROM mail_users WHERE email='%u';
user_query = SELECT 5000 as uid, 5000 as gid, '/var/mail/vhosts/%d/%n' as home;
EOF

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

# --- 5. Nginx VHost Setup ---

declare -A SUBDOMAINS=(
    ["admin.$MAIN_DOMAIN"]="/var/www/panel/whm"
    ["client.$MAIN_DOMAIN"]="/var/www/panel/cpanel"
    ["$MAIN_DOMAIN"]="/var/www/panel/landing"
    ["filemanager.$MAIN_DOMAIN"]="/var/www/apps/filemanager"
    ["webmail.$MAIN_DOMAIN"]="/var/www/apps/webmail"
    ["phpmyadmin.$MAIN_DOMAIN"]="/var/www/apps/phpmyadmin"
)

for sub in "${!SUBDOMAINS[@]}"; do
    cat > /etc/nginx/sites-available/$sub << CONF
server {
    listen 80;
    server_name $sub;
    root ${SUBDOMAINS[$sub]};
    index index.php index.html;
    location / { try_files \$uri \$uri/ /index.php?\$args; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.2-fpm.sock; }
}
CONF
    ln -sf /etc/nginx/sites-available/$sub /etc/nginx/sites-enabled/
done

# Restart Services
systemctl restart nginx mysql php8.2-fpm

log "INSTALLATION COMPLETE"
echo "------------------------------------------------"
echo "Admin Panel: http://admin.$MAIN_DOMAIN"
echo "User Panel:  http://client.$MAIN_DOMAIN"
echo "------------------------------------------------"
echo "Admin Cloud Credentials:"
echo "User: admin"
echo "Pass: admin123"
echo "DB Pass: $DB_PASS"
echo "------------------------------------------------"
