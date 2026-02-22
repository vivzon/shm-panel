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
    log "Configuring Nginx Domains..."

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

    # Clean legacy defaults
    rm -f /etc/nginx/sites-enabled/default

    for DOM in "${!DOMAINS[@]}"; do
        ROOT_DIR="${DOMAINS[$DOM]}"
        
        # Ensure root exists (mkdir if missing to prevent Nginx crash)
        if [ ! -d "$ROOT_DIR" ]; then
            mkdir -p "$ROOT_DIR"
            # Create dummy index if empty
            if [ -z "$(ls -A $ROOT_DIR)" ]; then
                 echo "<h1>$DOM</h1>" > "$ROOT_DIR/index.html"
            fi
        fi

        log "Creating VHost for: $DOM -> $ROOT_DIR"

        cat > "/etc/nginx/sites-available/$DOM" <<EOF
server {
    listen 80;
    server_name $DOM;
    root $ROOT_DIR;
    index index.php index.html;

    access_log /var/log/nginx/${DOM}_access.log;
    error_log /var/log/nginx/${DOM}_error.log;

    client_max_body_size 100M;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF
        # Enable Site
        ln -sf "/etc/nginx/sites-available/$DOM" "/etc/nginx/sites-enabled/"
    done

    # 3. Fix Permissions
    log "Fixing Web Permissions..."
    chown -R www-data:www-data /var/www
    chmod -R 755 /var/www

    # 4. Validate Nginx
    if ! nginx -t; then
        error "Nginx configuration failed. Check logs."
    fi

    # 5. Reload Nginx
    systemctl reload nginx
    log "Nginx Reloaded."

    # 6. Health Check (Local)
    log "Running Health Checks..."
    for DOM in "${!DOMAINS[@]}"; do
        # Use curl with resolving to localhost to bypass DNS propagation issues during install
        if curl -s -I -H "Host: $DOM" "http://127.0.0.1" | grep -q "200 OK"; then
             log "[OK] $DOM is reachable."
        else
             warn "[FAIL] $DOM returned non-200 status."
        fi
    done
}
