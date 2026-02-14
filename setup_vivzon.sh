#!/bin/bash

# ==============================================================================
# SHM PANEL - VIVZON.CLOUD INSTALLER
# ==============================================================================

# Configuration
MAIN_DOMAIN="vivzon.cloud"
ADMIN_DOMAIN="admin.vivzon.cloud"
CLIENT_DOMAIN="client.vivzon.cloud"
FILEMANAGER_DOMAIN="filemanager.vivzon.cloud"
PHPMYADMIN_DOMAIN="phpmyadmin.vivzon.cloud"

# Paths
PANEL_ROOT="/var/www/panel" # Core panel files
CLIENTS_ROOT="/var/www/clients" # Client websites
APPS_ROOT="/var/www/apps"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() { echo -e "${GREEN}[INFO]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# ==============================================================================
# 1. SYSTEM PREPARATION
# ==============================================================================
log "Step 1: System Preparation..."

export DEBIAN_FRONTEND=noninteractive

# Auto-detect IP
SERVER_IP=$(curl -s https://api.ipify.org || hostname -I | awk '{print $1}')
log "Detected Server IP: $SERVER_IP"

# Update & Install Dependencies
apt-get update
apt-get upgrade -y
apt-get install -y software-properties-common curl wget git zip unzip ufw fail2ban \
    acl quota jq clamav clamav-daemon htop net-tools \
    mariadb-server mariadb-client redis-server \
    nginx certbot python3-certbot-nginx

# PHP Repository (if needed for Ubuntu/Debian compatibility)
if ! command -v php8.2 &> /dev/null; then
    add-apt-repository ppa:ondrej/php -y
    apt-get update
fi

# Install PHP 8.2 & Extensions
apt-get install -y php8.2-fpm php8.2-mysql php8.2-common php8.2-gd php8.2-mbstring \
    php8.2-xml php8.2-zip php8.2-curl php8.2-bcmath php8.2-intl php8.2-imagick php8.2-cli \
    php8.2-redis php8.2-opcache php8.2-soap

# Start Services
systemctl enable nginx php8.2-fpm mariadb fail2ban redis-server
systemctl start nginx php8.2-fpm mariadb fail2ban redis-server

# ==============================================================================
# 2. DIRECTORIES & PERMISSIONS
# ==============================================================================
log "Step 2: Directory Structure..."

# Core Paths
mkdir -p $PANEL_ROOT/{landing,whm,cpanel,shared,assets}
mkdir -p $CLIENTS_ROOT
mkdir -p $APPS_ROOT/filemanager

# Verify /var/www exists (it should by now)
if [ ! -d "/var/www" ]; then
    error "/var/www missing! Critical failure."
fi

# Permissions
chown -R www-data:www-data /var/www
find /var/www -type d -exec chmod 755 {} \;
find /var/www -type f -exec chmod 644 {} \;

# ==============================================================================
# 3. DATABASE SETUP
# ==============================================================================
log "Step 3: Database Setup..."

# Secure MariaDB
MYSQL_ROOT_PASS=$(openssl rand -base64 24)
DB_NAME="shm_panel"
DB_USER="shm_user"
DB_PASS=$(openssl rand -base64 24)

# Create .my.cnf for root
cat > /root/.my.cnf << EOF
[client]
user=root
password=$MYSQL_ROOT_PASS
EOF
chmod 600 /root/.my.cnf

# Reset Root Password & Secure
systemctl stop mariadb
mysqld_safe --skip-grant-tables --skip-networking &
PID=$!
sleep 10

mysql --no-defaults -e "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;"
kill $PID
wait $PID 2>/dev/null || true
systemctl start mariadb

# Fix debian-sys-maint if it exists/user exists
if [ -f /etc/mysql/debian.cnf ]; then
    SYS_MAINT_PASS=$(grep "password =" /etc/mysql/debian.cnf | head -1 | awk '{print $3}')
    mysql -e "ALTER USER 'debian-sys-maint'@'localhost' IDENTIFIED BY '$SYS_MAINT_PASS';" 2>/dev/null || true
fi

# Create Panel Database & User
mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# Import Schema (Embedded)
mysql $DB_NAME << SQL
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) UNIQUE,
    email VARCHAR(255),
    password VARCHAR(255),
    status ENUM('active','suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    email VARCHAR(255),
    role ENUM('superadmin','admin') DEFAULT 'admin'
);
-- Insert default admin (user: admin, pass: admin123)
INSERT IGNORE INTO admins (username, password, email, role) VALUES 
('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@vivzon.cloud', 'superadmin');
SQL

# ==============================================================================
# 4. PANEL DEPLOYMENT
# ==============================================================================
log "Step 4: Deploying Panel..."

# Copy Project Files (Assuming script is run from project root)
if [ -d "landing" ]; then cp -r landing/* $PANEL_ROOT/landing/; else echo "<h1>Landing</h1>" > $PANEL_ROOT/landing/index.html; fi
if [ -d "whm" ]; then cp -r whm/* $PANEL_ROOT/whm/; else echo "<h1>WHM</h1>" > $PANEL_ROOT/whm/index.php; fi
if [ -d "cpanel" ]; then cp -r cpanel/* $PANEL_ROOT/cpanel/; else echo "<h1>cPanel</h1>" > $PANEL_ROOT/cpanel/index.php; fi
if [ -f "shm-manage" ]; then cp shm-manage /usr/local/bin/shm-manage; chmod +x /usr/local/bin/shm-manage; fi

# Config File
mkdir -p /etc/shm
cat > /etc/shm/config.sh << CONFIG
DB_NAME='$DB_NAME'
DB_USER='$DB_USER'
DB_PASS='$DB_PASS'
MAIN_DOMAIN='$MAIN_DOMAIN'
SERVER_IP='$SERVER_IP'
CONFIG
chmod 600 /etc/shm/config.sh

# Update PHP Config
if [ -f "$PANEL_ROOT/shared/config.php" ]; then
    sed -i "s/'shm_panel'/'$DB_NAME'/g" $PANEL_ROOT/shared/config.php
    sed -i "s/'shm_admin'/'$DB_USER'/g" $PANEL_ROOT/shared/config.php
    sed -i "s/QwErTyUiOp@1/$DB_PASS/g" $PANEL_ROOT/shared/config.php
    sed -i "s/localhost/127.0.0.1/g" $PANEL_ROOT/shared/config.php
fi

# ==============================================================================
# 5. PHPMyAdmin
# ==============================================================================
log "Step 5: Installing PHPMyAdmin..."

# Install without web server autopconfig to avoid apache2
echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect none" | debconf-set-selections
echo "phpmyadmin phpmyadmin/dbconfig-install boolean true" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/admin-pass password $MYSQL_ROOT_PASS" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/app-pass password $DB_PASS" | debconf-set-selections
echo "phpmyadmin phpmyadmin/app-password-confirm password $DB_PASS" | debconf-set-selections

apt-get install -y phpmyadmin

# ==============================================================================
# 6. NGINX CONFIGURATION
# ==============================================================================
log "Step 6: Nginx Configuration..."

# Remove default
rm -f /etc/nginx/sites-enabled/default

# Function to create vhost
create_vhost() {
    local DOMAIN=$1
    local ROOT=$2
    local ALLOW_IP=$3 # Optional: IP restriction

    cat > /etc/nginx/sites-available/$DOMAIN << EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $ROOT;
    index index.php index.html;

    access_log /var/log/nginx/${DOMAIN}_access.log;
    error_log /var/log/nginx/${DOMAIN}_error.log;

    client_max_body_size 100M;

    $(if [ ! -z "$ALLOW_IP" ]; then
        echo "
    location / {
        allow $ALLOW_IP;
        deny all;
        try_files \$uri \$uri/ /index.php?\$args;
    }
        "
    else
        echo "
    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }
        "
    fi)

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF
    ln -sf /etc/nginx/sites-available/$DOMAIN /etc/nginx/sites-enabled/
}

# Admin IP (Current Connection IP)
ADMIN_IP=$(echo $SSH_CLIENT | awk '{print $1}')
log "Restricting Admin Panels to IP: $ADMIN_IP"

# Create Server Blocks
create_vhost "$MAIN_DOMAIN" "$PANEL_ROOT/landing"
create_vhost "$ADMIN_DOMAIN" "$PANEL_ROOT/whm" "$ADMIN_IP"
create_vhost "$CLIENT_DOMAIN" "$PANEL_ROOT/cpanel"
create_vhost "$FILEMANAGER_DOMAIN" "$APPS_ROOT/filemanager"
create_vhost "$PHPMYADMIN_DOMAIN" "/usr/share/phpmyadmin" "$ADMIN_IP"

# Validate
nginx -t && systemctl reload nginx

# ==============================================================================
# 7. DNS SERVER (BIND9)
# ==============================================================================
log "Step 7: Configuring DNS Server (Bind9)..."

apt-get install -y bind9 bind9utils bind9-doc

# Create zones directory
mkdir -p /etc/bind/zones

# Configure Options
cat > /etc/bind/named.conf.options << BIND_OPTS
options {
    directory "/var/cache/bind";
    recursion no;
    allow-transfer { none; };
    
    dnssec-validation auto;
    listen-on-v6 { any; };
};
BIND_OPTS

# Restart Bind9
systemctl restart bind9

# ==============================================================================
# 8. MAIL SERVER (POSTFIX + DOVECOT + ROUNDCUBE)
# ==============================================================================
log "Step 8: Configuring Mail Server..."

# 1. Install Packages
debconf-set-selections <<< "postfix postfix/mailname string mail.$MAIN_DOMAIN"
debconf-set-selections <<< "postfix postfix/main_mailer_type string 'Internet Site'"
apt-get install -y postfix postfix-mysql dovecot-core dovecot-imapd dovecot-pop3d dovecot-mysql dovecot-lmtpd

# 2. SSL Certs (Snakeoil first, Certbot later)
if [ ! -f /etc/ssl/certs/ssl-cert-snakeoil.pem ]; then
    make-ssl-cert generate-default-snakeoil --force-overwrite
fi

# 3. Create vmail user
groupadd -g 5000 vmail 2>/dev/null || true
useradd -g vmail -u 5000 vmail -d /var/mail/vhosts -m -s /sbin/nologin 2>/dev/null || true
mkdir -p /var/mail/vhosts
chown -R vmail:vmail /var/mail/vhosts
chmod 750 /var/mail/vhosts

# 4. Configure Postfix
cat > /etc/postfix/main.cf << POSTFIX_MAIN
smtpd_banner = \$myhostname ESMTP \$mail_name (Ubuntu)
biff = no
append_dot_mydomain = no
readme_directory = no
compatibility_level = 2

# TLS parameters
smtpd_tls_cert_file=/etc/ssl/certs/ssl-cert-snakeoil.pem
smtpd_tls_key_file=/etc/ssl/private/ssl-cert-snakeoil.key
smtpd_use_tls=yes
smtpd_tls_session_cache_database = btree:\${data_directory}/smtpd_scache
smtp_tls_session_cache_database = btree:\${data_directory}/smtp_scache

# Authentication
smtpd_sasl_type = dovecot
smtpd_sasl_path = private/auth
smtpd_sasl_auth_enable = yes
smtpd_recipient_restrictions = permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination

# Network
myhostname = mail.$MAIN_DOMAIN
alias_maps = hash:/etc/aliases
alias_database = hash:/etc/aliases
myorigin = /etc/mailname
mydestination = localhost
relayhost = 
mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128
mailbox_size_limit = 0
recipient_delimiter = +
inet_interfaces = all
inet_protocols = all

# Virtual Mailbox (MySQL)
virtual_transport = lmtp:unix:private/dovecot-lmtp
virtual_uid_maps = static:5000
virtual_gid_maps = static:5000
virtual_mailbox_domains = mysql:/etc/postfix/mysql-virtual-mailbox-domains.cf
virtual_mailbox_maps = mysql:/etc/postfix/mysql-virtual-mailbox-maps.cf
virtual_alias_maps = mysql:/etc/postfix/mysql-virtual-alias-maps.cf
POSTFIX_MAIN

# MySQL Maps for Postfix
echo "user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT 1 FROM mail_domains WHERE domain='%s'" > /etc/postfix/mysql-virtual-mailbox-domains.cf

echo "user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT 1 FROM mail_users WHERE email='%s' AND is_active=1" > /etc/postfix/mysql-virtual-mailbox-maps.cf

echo "user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT destination FROM mail_aliases WHERE source='%s'" > /etc/postfix/mysql-virtual-alias-maps.cf

chmod 600 /etc/postfix/mysql-*.cf

# 5. Configure Dovecot
cat > /etc/dovecot/dovecot-sql.conf.ext << DOVECOT_SQL
driver = mysql
connect = host=127.0.0.1 dbname=$DB_NAME user=$DB_USER password=$DB_PASS
default_pass_scheme = SHA512-CRYPT
password_query = SELECT email as user, password FROM mail_users WHERE email='%u' AND is_active=1;
user_query = SELECT 5000 as uid, 5000 as gid, '/var/mail/vhosts/%d/%n' as home FROM mail_users WHERE email='%u';
DOVECOT_SQL
chmod 600 /etc/dovecot/dovecot-sql.conf.ext

cat > /etc/dovecot/dovecot.conf << DOVECOT_MAIN
!include_try /usr/share/dovecot/protocols.d/*.protocol
protocols = imap pop3 lmtp

listen = *, ::

ssl = yes
ssl_cert = </etc/ssl/certs/ssl-cert-snakeoil.pem
ssl_key = </etc/ssl/private/ssl-cert-snakeoil.key

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

service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0666
    user = postfix
    group = postfix
  }
}
DOVECOT_MAIN

systemctl restart dovecot postfix

# 6. Install Roundcube
log "Installing Roundcube Webmail..."
apt-get install -y roundcube roundcube-mysql roundcube-plugins

# Configure Roundcube DB (Automated)
# We assume dbconfig-common handled the DB creation during install with default headers
# If not, we would need to manually create roundcubemail db.
# For robustness, we link Nginx to Roundcube
create_vhost "webmail.$MAIN_DOMAIN" "/var/lib/roundcube"

# ==============================================================================
# 9. FIREWALL (UFW)
# ==============================================================================
log "Step 9: Configuring Firewall..."

ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw allow 3306
ufw allow 53          # DNS
ufw allow 25          # SMTP
ufw allow 465         # SMTPS
ufw allow 587         # SUBMISSION
ufw allow 110         # POP3
ufw allow 995         # POP3S
ufw allow 143         # IMAP
ufw allow 993         # IMAPS
ufw --force enable

# ==============================================================================
# 8. FAIL2BAN
# ==============================================================================
log "Step 8: Configuring Fail2Ban..."

# Hard Reset Runtime
rm -rf /var/run/fail2ban
mkdir -p /var/run/fail2ban

# Configuration
cat > /etc/fail2ban/jail.local << EOF
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 3

[sshd]
enabled = true
EOF

# Backend Polling
sed -i "s/backend = .*/backend = polling/" /etc/fail2ban/fail2ban.conf || echo "backend = polling" >> /etc/fail2ban/fail2ban.conf

# Restart
systemctl restart fail2ban

# ==============================================================================
# 10. SSL SETUP (AUTOMATED)
# ==============================================================================
log "Step 10: Automated SSL Setup..."

# Stop Nginx to allow standalone certbot or just use --nginx plugin
# Using --nginx plugin is safer as it handles config
if systemctl is-active --quiet nginx; then
    log "Requesting SSL Certificates via Let's Encrypt..."
    
    # Attempt to get certificates non-interactively
    # We use --register-unsafely-without-email if no email provided, or use the admin email
    
    CERT_DOMAINS="-d $MAIN_DOMAIN -d $ADMIN_DOMAIN -d $CLIENT_DOMAIN -d $FILEMANAGER_DOMAIN -d $PHPMYADMIN_DOMAIN"
    
    if certbot --nginx $CERT_DOMAINS --non-interactive --agree-tos -m admin@vivzon.cloud --redirect; then
        log "SSL Certificates installed successfully!"
    else
        warn "SSL Generation Failed! DNS might not be propagated yet."
        warn "You can retry manually later with: certbot --nginx $CERT_DOMAINS"
    fi
else
    warn "Nginx is not running. Skipping SSL setup."
fi

# ==============================================================================
# 11. CLEANUP & FINALIZATION
# ==============================================================================
log "Step 11: Cleanup & Finalization..."

# Fix Permissions One Last Time
chown -R www-data:www-data /var/www
find /var/www -type d -exec chmod 755 {} \;
find /var/www -type f -exec chmod 644 {} \;

# Make sure shm-manage is executable
chmod +x /usr/local/bin/shm-manage

# Restart all services to ensure config persistence
systemctl restart nginx php8.2-fpm mariadb fail2ban redis-server

# ==============================================================================
# 12. FINAL VERIFICATION
# ==============================================================================
log "============================================================"
log "INSTALLATION COMPLETE - SYSTEM READY"
log "============================================================"

echo "Panel URLs (HTTPS enabled if successful):"
echo " - Landing:      https://$MAIN_DOMAIN"
echo " - Admin (WHM):  https://$ADMIN_DOMAIN"
echo " - Client:       https://$CLIENT_DOMAIN"
echo " - FileManager:  https://$FILEMANAGER_DOMAIN"
echo " - PHPMyAdmin:   https://$PHPMYADMIN_DOMAIN"
echo ""
echo "Database Credentials:"
echo " - Root Pass:    $MYSQL_ROOT_PASS"
echo " - App User:     $DB_USER"
echo " - App Pass:     $DB_PASS"
echo ""
echo "Service Status:"
systemctl is-active nginx php8.2-fpm mariadb fail2ban
echo ""
echo "Fail2Ban Status:"
fail2ban-client status sshd
echo ""
echo "System Stats:"
uptime
free -h
df -h /

exit 0
