#!/bin/bash

# ==============================================================================
# SHM PANEL - UPGRADE SCRIPT (v4 -> v5 Production)
# ==============================================================================
# Run this as root to upgrade an existing SHM Panel installation.
# It preserves client data while updating the Core Engine, UI, and Schema.
# ==============================================================================

if [ "$EUID" -ne 0 ]; then echo "Please run as root"; exit 1; fi

source /etc/shm/config.sh 2>/dev/null || echo "Config not found. Is SHM installed?"

log() { echo -e "\033[0;32m[Upgrade] $1\033[0m"; }

# --- 1. Backup ---
log "Backing up Database..."
DATE=$(date +%F_%H-%M)
mysqldump $DB_NAME > /root/shm_backup_$DATE.sql
log "Backup saved to: /root/shm_backup_$DATE.sql"

# --- 2. Install New Dependencies ---
log "Installing System Dependencies..."
apt update
apt install -y ufw fail2ban certbot python3-certbot-nginx acl quota bind9 dovecot-core dovecot-imapd dovecot-pop3d dovecot-mysql postfix postfix-mysql proftpd-basic proftpd-mod-mysql mariadb-client zip unzip

# Check Composer
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Check Node.js
if ! command -v node &> /dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt install -y nodejs
fi

# --- 3. Schema Update ---
log "Updating Database Schema..."
mysql $DB_NAME <<EOF
-- Add 'status' to clients
ALTER TABLE clients ADD COLUMN status ENUM('active','suspended') DEFAULT 'active';

-- Add 'ssl_active' and 'php_version' to domains
ALTER TABLE domains ADD COLUMN php_version VARCHAR(5) DEFAULT '8.2';
ALTER TABLE domains ADD COLUMN ssl_active BOOLEAN DEFAULT 0;

-- Create Mail/FTP Tables if missing (from v4)
CREATE TABLE IF NOT EXISTS mail_domains (id INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255) UNIQUE);
CREATE TABLE IF NOT EXISTS mail_users (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, email VARCHAR(255) UNIQUE, password VARCHAR(255));
CREATE TABLE IF NOT EXISTS client_databases (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, db_name VARCHAR(64) UNIQUE);
CREATE TABLE IF NOT EXISTS client_db_users (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, db_user VARCHAR(32));
CREATE TABLE IF NOT EXISTS dns_records (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, type VARCHAR(10), host VARCHAR(255), value VARCHAR(255));
CREATE TABLE IF NOT EXISTS php_config (domain_id INT PRIMARY KEY, memory_limit VARCHAR(10) DEFAULT '128M');
EOF

# --- 4. Deploy Core Engine ---
log "Updating Core Engine..."
cp shm-manage /usr/local/bin/shm-manage
chmod +x /usr/local/bin/shm-manage

# --- 5. Deploy Frontend ---
# --- 5. Deploy Frontend ---
log "Updating UI Files..."

# 5a. Preserve Credentials
if [ -f "/var/www/panel/shared/config.php" ]; then
    DB_PASS_OLD=$(grep "db_pass =" /var/www/panel/shared/config.php | cut -d"'" -f2)
else
    DB_PASS_OLD=""
fi

mkdir -p /var/www/panel/{whm,cpanel,shared,landing}
cp -r whm/* /var/www/panel/whm/
cp -r cpanel/* /var/www/panel/cpanel/
cp -r landing/* /var/www/panel/landing/
cp shared_config.php /var/www/panel/shared/config.php

# Remove old index.html if using PHP now
if [ -f "/var/www/panel/landing/index.php" ]; then
    rm -f /var/www/panel/landing/index.html
fi

# 5b. Restore Credentials
if [ ! -z "$DB_PASS_OLD" ]; then
    log "Restoring Database Password to Config..."
    sed -i "s/SHMPanel_Secure_Pass_2025/$DB_PASS_OLD/" /var/www/panel/shared/config.php
else
    log "WARNING: Could not find old DB Password. Please update /var/www/panel/shared/config.php manually."
fi

# 5c. Update File Manager
log "Updating File Manager..."
mkdir -p /var/www/apps/filemanager
cp cpanel/files.php /var/www/apps/filemanager/index.php
cp cpanel/login.php /var/www/apps/filemanager/login.php

# Fix Permissions
chown -R www-data:www-data /var/www/panel /var/www/apps
chmod -R 755 /var/www/panel

# --- 6. Configure Apps (Webmail/PMA) ---
# If they don't exist, install them.
if [ ! -d "/var/www/apps/phpmyadmin" ]; then
    log "Installing phpMyAdmin..."
    mkdir -p /var/www/apps/phpmyadmin
    wget https://files.phpmyadmin.net/phpMyAdmin/5.2.1/phpMyAdmin-5.2.1-all-languages.zip -O /tmp/pma.zip
    unzip -q /tmp/pma.zip -d /tmp/
    mv /tmp/phpMyAdmin-5.2.1-all-languages/* /var/www/apps/phpmyadmin/
    rm -f /tmp/pma.zip
    
    # Simple Config
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
    chown -R www-data:www-data /var/www/apps/phpmyadmin
fi

# --- 7. Service Configuration (SQL Auth) ---
log "Configuring Services for SQL Auth..."

# Get Configs
source /etc/shm/config.sh

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

# Enable SQL Auth in Dovecot
sed -i 's/!include auth-system.conf.ext/#!include auth-system.conf.ext/' /etc/dovecot/conf.d/10-auth.conf
sed -i 's/#!include auth-sql.conf.ext/!include auth-sql.conf.ext/' /etc/dovecot/conf.d/10-auth.conf

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

# --- 8. Nginx Panel VHosts ---
log "Updating Panel VHosts..."
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
systemctl restart nginx mysql php8.2-fpm postfix dovecot proftpd

log "UPGRADE COMPLETE. Version 5.0 is active."
