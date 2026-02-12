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
    
    # Shared Config
    if [ -f "shared/config.php" ]; then
        cp shared/config.php /var/www/panel/shared/config.php
        sed -i "s/SHMPanel_Secure_Pass_2025/$DB_PASS/g" /var/www/panel/shared/config.php
        sed -i "s/yourdomain.com/$MAIN_DOMAIN/g" /var/www/panel/shared/config.php
    fi
    
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
    
    # Metrics
    cat > /etc/cron.minutely/shm-metrics << CRON_M
#!/bin/bash
# Logic to collect metrics would go here (simplified for refactor)
CRON_M
    chmod +x /etc/cron.minutely/shm-metrics
    
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
    systemctl restart fail2ban
}
