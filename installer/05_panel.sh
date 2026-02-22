#!/bin/bash

# ==============================================================================
# SHM PANEL - PANEL DEPLOYMENT
# ==============================================================================

deploy_panel() {
    log "Deploying SHM Panel Components..."
    
    # 1. Backend Engine
    log "Deploying Backend Engine..."
    if [ -f "shm-manage" ]; then
        cp shm-manage /usr/local/bin/shm-manage
        chmod 750 /usr/local/bin/shm-manage
    else
        error "shm-manage binary not found!"
    fi
    
    # Config Dir
    mkdir -p /etc/shm
    cat > /etc/shm/config.sh << CONFIG
#!/bin/bash
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
    
    # Sudoers
    echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/shm-manage" > /etc/sudoers.d/shm
    chmod 0440 /etc/sudoers.d/shm
    
    # 2. Frontend Files
    log "Deploying Frontend..."
    mkdir -p /var/www/panel/{whm,cpanel,shared,landing,assets}
    mkdir -p /var/www/clients
    mkdir -p /var/www/apps/{filemanager,monitor,backup}
    mkdir -p /var/log/shm
    
    chown -R www-data:www-data /var/www/panel /var/www/apps /var/log/shm
    chmod -R 755 /var/www/panel
    
    # Copy Files
    [ -d "whm" ] && cp -r whm/* /var/www/panel/whm/
    [ -d "cpanel" ] && cp -r cpanel/* /var/www/panel/cpanel/
    [ -d "landing" ] && cp -r landing/* /var/www/panel/landing/
    
    # Landing Page Fallback
    if [ ! -f "/var/www/panel/landing/index.html" ]; then
        echo "<h1>SHM Panel Installed</h1>" > /var/www/panel/landing/index.html
    fi
    
    # Shared Config & Helpers
    if [ -d "shared" ]; then
        cp -r shared/* /var/www/panel/shared/
        sed -i "s|bKp/8MLv5tC7fRo356UXS14Vp0MMDcZT|$DB_PASS|g" /var/www/panel/shared/config.php
        sed -i "s|yourdomain.com|$MAIN_DOMAIN|g" /var/www/panel/shared/config.php
    fi
    
    # 2.5. Install Sub-Applications (phpMyAdmin & Roundcube)
    log "Installing phpMyAdmin and Roundcube..."
    export DEBIAN_FRONTEND=noninteractive
    
    # Pre-cleanup dummy directories from previous failed runs
    [ -f "/usr/share/phpmyadmin/index.html" ] && rm -rf /usr/share/phpmyadmin
    [ -f "/var/lib/roundcube/index.html" ] && rm -rf /var/lib/roundcube
    
    # phpMyAdmin (No DBConfig to avoid root socket auth issues)
    echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect none" | debconf-set-selections
    echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
    apt-get install -y phpmyadmin
    
    # Roundcube
    echo "roundcube-core roundcube/dbconfig-install boolean false" | debconf-set-selections
    apt-get install -y roundcube roundcube-mysql
    
    # Manual Roundcube DB Setup
    log "Configuring Roundcube Database manually..."
    RC_DB_PASS=$(openssl rand -hex 16)
    mysql -e "CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS 'roundcube'@'localhost' IDENTIFIED BY '${RC_DB_PASS}';"
    mysql -e "GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # Initialize Roundcube Schema if empty
    if [ -f "/usr/share/roundcube/SQL/mysql.initial.sql" ]; then
        if ! mysql roundcube -e "SELECT 1 FROM users LIMIT 1;" >/dev/null 2>&1; then
            mysql roundcube < /usr/share/roundcube/SQL/mysql.initial.sql
        fi
    fi
    
    # Generate Roundcube Config
    cat > /etc/roundcube/config.inc.php << RC_CONF
<?php
\$config = [];
\$config['db_dsnw'] = 'mysql://roundcube:${RC_DB_PASS}@localhost/roundcube';
\$config['default_host'] = 'localhost';
\$config['default_port'] = 143;
\$config['smtp_server'] = 'localhost';
\$config['smtp_port'] = 587;
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';
\$config['support_url'] = '';
\$config['product_name'] = 'SHM Webmail';
\$config['des_key'] = '$(openssl rand -base64 24)';
\$config['plugins'] = ['archive', 'zipdownload'];
\$config['skin'] = 'elastic';
RC_CONF
    chmod 640 /etc/roundcube/config.inc.php
    chown root:www-data /etc/roundcube/config.inc.php
    
    # 3. FTP Setup (ProFTPD)
    apt-get install -y proftpd-basic proftpd-mod-mysql 
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
    systemctl restart proftpd
    
    # 4. Cron Jobs
    log "Configuring Cron Jobs..."
    
    # Traffic Stats
    cat > /etc/cron.hourly/shm-traffic << CRON
#!/bin/bash
/usr/local/bin/shm-manage update-traffic-stats 2>/dev/null || true
CRON
    chmod +x /etc/cron.hourly/shm-traffic
    
    # Metrics (Minutely)
    cat > /etc/cron.d/shm-metrics << CRON_M
* * * * * root /usr/local/bin/shm-manage metrics 2>/dev/null || true
CRON_M
    chmod 644 /etc/cron.d/shm-metrics
    
    # 5. Fail2ban Jails
    log "Configuring Fail2ban..."
    cat > /etc/fail2ban/jail.local << FAIL2BAN
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5
banaction = ufw
backend = systemd

[sshd]
enabled = true

[proftpd]
enabled = true
FAIL2BAN
    # 6. Nginx Panel Config (Robust Setup)
    setup_nginx_domains
}

setup_nginx_domains() {
    log "Configuring Nginx Domains (Hardened)..."

    # Define Domain => Root Path Mapping
    declare -A DOMAINS
    DOMAINS=(
        ["$MAIN_DOMAIN"]="/var/www/panel/landing"
        ["admin.$MAIN_DOMAIN"]="/var/www/panel/whm"
        ["client.$MAIN_DOMAIN"]="/var/www/panel/cpanel"
        ["filemanager.$MAIN_DOMAIN"]="/var/www/apps/filemanager"
        ["phpmyadmin.$MAIN_DOMAIN"]="/usr/share/phpmyadmin"
        ["webmail.$MAIN_DOMAIN"]="/var/lib/roundcube"
        ["monitor.$MAIN_DOMAIN"]="/var/www/apps/monitor"
    )

    # 1. Block Unknown Domains (Return 444)
    create_default_block_server() {
        log "Configuring Default Block Server..."
        rm -f /etc/nginx/sites-enabled/default
        rm -f /etc/nginx/sites-available/default
        
        # Needs a dummy cert to reject SNI requests cleanly
        if [ ! -f /etc/ssl/certs/ssl-cert-snakeoil.pem ]; then
            apt-get install -y ssl-cert
            make-ssl-cert generate-default-snakeoil --force-overwrite
        fi

        cat > /etc/nginx/sites-available/00-default-block << 'EOF'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444; # Connection Closed Without Response
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;
    ssl_certificate /etc/ssl/certs/ssl-cert-snakeoil.pem;
    ssl_certificate_key /etc/ssl/private/ssl-cert-snakeoil.key;
    return 444; # Connection Closed Without Response
}
EOF
        ln -sf /etc/nginx/sites-available/00-default-block /etc/nginx/sites-enabled/
    }

    # 2. Ensure Snippets Exist
    create_safe_snippets() {
        log "Verifying Nginx Snippets..."
        mkdir -p /etc/nginx/snippets
        
        if [ ! -f "/etc/nginx/snippets/fastcgi-php.conf" ]; then
            cat > /etc/nginx/snippets/fastcgi-php.conf << 'EOF'
fastcgi_split_path_info ^(.+\.php)(/.+)$;
try_files $fastcgi_script_name =404;
set $path_info $fastcgi_path_info;
fastcgi_param PATH_INFO $path_info;
fastcgi_index index.php;
include fastcgi.conf;
EOF
        fi
    }

    # 3. Secure VHost Generator
    create_vhost() {
        local DOMAIN=$1
        local ROOT=$2
        local ALLOW_IP=$3

        log "Generating Secure VHost for: $DOMAIN"

        if [ ! -d "$ROOT" ]; then
            mkdir -p "$ROOT"
            log "Created missing directory: $ROOT"
        fi

        cat > "/etc/nginx/sites-available/$DOMAIN" <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $ROOT;
    index index.php index.html index.htm;

    access_log /var/log/nginx/${DOMAIN}_access.log;
    error_log /var/log/nginx/${DOMAIN}_error.log;

    client_max_body_size 100M;
    server_tokens off;

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

    location ~ /\.ht { deny all; }
    location ~ /\.git { deny all; }
    location ~ /\.env { deny all; }
}
EOF
        ln -sf "/etc/nginx/sites-available/$DOMAIN" "/etc/nginx/sites-enabled/"
    }

    # 4. Safe Reload (SSL handled in step 06_finalize.sh for modular installs)
    safe_nginx_reload() {
        log "Validating Nginx Configuration..."
        if ! nginx -t; then
            error "CRITICAL: Nginx configuration is invalid! Aborting reload."
            return 1
        fi
        systemctl reload nginx
        log "Nginx reloaded successfully."
    }

    # Admin IP
    ADMIN_IP=$(echo $SSH_CLIENT | awk '{print $1}')
    log "Restricting Admin Panels to IP: $ADMIN_IP"

    # Execute
    create_default_block_server
    create_safe_snippets

    # Iterate over domains map to generate VHosts
    for DOMAIN in "${!DOMAINS[@]}"; do
        ROOT_DIR="${DOMAINS[$DOMAIN]}"
        
        # Determine if it requires an IP lock
        case "$DOMAIN" in
            "admin.$MAIN_DOMAIN"|"phpmyadmin.$MAIN_DOMAIN")
                create_vhost "$DOMAIN" "$ROOT_DIR" "$ADMIN_IP"
                ;;
            *)
                create_vhost "$DOMAIN" "$ROOT_DIR"
                ;;
        esac
    done

    # Fix Web Permissions
    log "Fixing Web Permissions..."
    chown -R www-data:www-data /var/www
    chmod -R 755 /var/www

    safe_nginx_reload

    # Health Check (Local)
    log "Running Health Checks..."
    for DOMAIN in "${!DOMAINS[@]}"; do
        if curl -s -I -H "Host: $DOMAIN" "http://127.0.0.1" | grep -q "200 OK"; then
             log "[OK] $DOMAIN is reachable."
        else
             warn "[FAIL] $DOMAIN returned non-200 status."
        fi
    done
}
