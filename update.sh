#!/bin/bash

# ==============================================================================
# SHM PANEL - PRODUCTION UPDATE UTILITY (v6.0)
# ==============================================================================
# Run this to safely apply code changes to a live SHM Panel server.
# Features: Safe rollback, zero-downtime updates, database migration
# ==============================================================================

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log() { echo -e "${GREEN}[UPDATE] $1${NC}"; }
warn() { echo -e "${YELLOW}[WARNING] $1${NC}"; }
error() { echo -e "${RED}[ERROR] $1${NC}"; exit 1; }

# --- Pre-flight checks ---
if [ "$EUID" -ne 0 ]; then error "Please run as root (sudo $0)"; fi

if [ ! -f "shm-manage" ]; then
    error "File 'shm-manage' not found in current directory."
fi

# --- Backup current state ---
BACKUP_TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/root/shm-update-backup-$BACKUP_TIMESTAMP"

log "Creating backup at $BACKUP_DIR..."
mkdir -p "$BACKUP_DIR"

# Backup critical files
cp -r /var/www/panel "$BACKUP_DIR/panel" 2>/dev/null || true
cp -r /var/www/apps "$BACKUP_DIR/apps" 2>/dev/null || true
cp /usr/local/bin/shm-manage "$BACKUP_DIR/shm-manage.old" 2>/dev/null || true
cp -r /etc/nginx/sites-available "$BACKUP_DIR/nginx-sites" 2>/dev/null || true
cp -r /etc/shm "$BACKUP_DIR/shm-config" 2>/dev/null || true

# Backup database
if [ -f "/etc/shm/config.sh" ]; then
    source /etc/shm/config.sh
    mysqldump --single-transaction --quick --lock-tables=false "$DB_NAME" > "$BACKUP_DIR/database-backup.sql" 2>/dev/null && \
        gzip "$BACKUP_DIR/database-backup.sql"
    log "Database backup created"
fi

# --- Source configuration ---
if [ -f "/etc/shm/config.sh" ]; then
    source /etc/shm/config.sh
    log "Loaded configuration from /etc/shm/config.sh"
else
    warn "Configuration file /etc/shm/config.sh not found"
    read -p "Continue with defaults? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then exit 1; fi
    DB_NAME="shm_panel"
    DB_USER="shm_admin"
fi

# --- 1. System Dependencies ---
log "Checking system dependencies..."

# Install missing packages (safe mode)
install_if_missing() {
    local pkg="$1"
    if ! dpkg -l | grep -q "^ii.*$pkg"; then
        log "Installing $pkg..."
        apt-get update -qq
        apt-get install -y "$pkg"
    fi
}

# Essential packages
install_if_missing "mariadb-client"
install_if_missing "curl"
install_if_missing "wget"
install_if_missing "zip"
install_if_missing "unzip"

# PHP packages (if missing)
for v in 8.1 8.2 8.3; do
    if [ ! -d "/etc/php/$v" ]; then
        warn "PHP $v not installed. Installing..."
        apt-get install -y php$v-fpm php$v-mysql php$v-common php$v-gd php$v-mbstring \
            php$v-xml php$v-zip php$v-curl php$v-bcmath
    fi
done

# --- 2. Update Backend Engine ---
if [ -f "shm-manage" ]; then
    log "Updating shm-manage..."
    
    # Backup current version
    if [ -f "/usr/local/bin/shm-manage" ]; then
        cp /usr/local/bin/shm-manage "/usr/local/bin/shm-manage.backup-$BACKUP_TIMESTAMP"
    fi
    
    # Install new version
    cp shm-manage /usr/local/bin/shm-manage
    chmod 750 /usr/local/bin/shm-manage
    
    # Verify installation
    if /usr/local/bin/shm-manage --help &>/dev/null; then
        log "shm-manage updated successfully"
    else
        error "shm-manage update failed. Restoring backup..."
        cp "/usr/local/bin/shm-manage.backup-$BACKUP_TIMESTAMP" /usr/local/bin/shm-manage
        exit 1
    fi
fi

# --- 3. Update Frontend Files ---
log "Updating frontend files..."

# Create directories if they don't exist
mkdir -p /var/www/panel/{whm,cpanel,shared,landing,assets}
mkdir -p /var/www/apps/{filemanager,monitor,backup}
mkdir -p /var/log/shm

# --- 3.1. WHM (Admin Panel) ---
if [ -d "whm" ]; then
    log "Updating WHM files..."
    
    # Backup existing WHM
    if [ -d "/var/www/panel/whm" ]; then
        tar -czf "$BACKUP_DIR/whm-backup.tar.gz" -C /var/www/panel whm
    fi
    
    # Copy new files
    rsync -av --delete --exclude='config.php' --exclude='.env' \
        whm/ /var/www/panel/whm/
    
    # Preserve existing config if exists
    if [ -f "/var/www/panel/whm/config.php" ] && [ -f "whm/config.php" ]; then
        # Merge configuration (preserve DB settings)
        OLD_DB_SETTINGS=$(grep -E "(\$db_host|\$db_name|\$db_user|\$db_pass)" /var/www/panel/whm/config.php)
        cp whm/config.php /var/www/panel/whm/config.php.new
        # Re-insert old DB settings
        echo "$OLD_DB_SETTINGS" | while read line; do
            var=$(echo "$line" | cut -d'=' -f1 | tr -d ' $')
            val=$(echo "$line" | cut -d"'" -f2)
            sed -i "s|^.*$var = .*;|$var = '$val';|" /var/www/panel/whm/config.php.new
        done
        mv /var/www/panel/whm/config.php /var/www/panel/whm/config.php.old
        mv /var/www/panel/whm/config.php.new /var/www/panel/whm/config.php
    fi
fi

# --- 3.2. cPanel (Client Panel) ---
if [ -d "cpanel" ]; then
    log "Updating cPanel files..."
    
    # Backup existing cPanel
    if [ -d "/var/www/panel/cpanel" ]; then
        tar -czf "$BACKUP_DIR/cpanel-backup.tar.gz" -C /var/www/panel cpanel
    fi
    
    # Copy new files
    rsync -av --delete --exclude='config.php' --exclude='.env' \
        cpanel/ /var/www/panel/cpanel/
    
    # Handle config.php (same as WHM)
    if [ -f "/var/www/panel/cpanel/config.php" ] && [ -f "cpanel/config.php" ]; then
        OLD_DB_SETTINGS=$(grep -E "(\$db_host|\$db_name|\$db_user|\$db_pass)" /var/www/panel/cpanel/config.php)
        cp cpanel/config.php /var/www/panel/cpanel/config.php.new
        echo "$OLD_DB_SETTINGS" | while read line; do
            var=$(echo "$line" | cut -d'=' -f1 | tr -d ' $')
            val=$(echo "$line" | cut -d"'" -f2)
            sed -i "s|^.*$var = .*;|$var = '$val';|" /var/www/panel/cpanel/config.php.new
        done
        mv /var/www/panel/cpanel/config.php /var/www/panel/cpanel/config.php.old
        mv /var/www/panel/cpanel/config.php.new /var/www/panel/cpanel/config.php
    fi
fi

# --- 3.3. Shared Configuration ---
if [ -d "shared" ]; then
    log "Updating shared files..."
    
    # Backup existing shared
    if [ -d "/var/www/panel/shared" ]; then
        tar -czf "$BACKUP_DIR/shared-backup.tar.gz" -C /var/www/panel shared
    fi
    
    # Copy new files (excluding config.php)
    rsync -av --delete --exclude='config.php' \
        shared/ /var/www/panel/shared/
    
    # Update config.php intelligently
    if [ -f "shared/config.php" ]; then
        if [ -f "/var/www/panel/shared/config.php" ]; then
            # Extract current database password
            CURRENT_PASS=$(grep "\$db_pass" /var/www/panel/shared/config.php | cut -d"'" -f2)
            if [ -n "$CURRENT_PASS" ] && [ "$CURRENT_PASS" != "SHMPanel_Secure_Pass_2025" ]; then
                log "Preserving existing database password"
                cp shared/config.php /var/www/panel/shared/config.php.new
                sed -i "s/SHMPanel_Secure_Pass_2025/$CURRENT_PASS/g" /var/www/panel/shared/config.php.new
                mv /var/www/panel/shared/config.php /var/www/panel/shared/config.php.old
                mv /var/www/panel/shared/config.php.new /var/www/panel/shared/config.php
            else
                # Use existing config
                warn "Using existing config.php (password is placeholder)"
            fi
        else
            # Fresh install
            cp shared/config.php /var/www/panel/shared/config.php
        fi
    fi
fi

# --- 3.4. Landing Page ---
if [ -d "landing" ]; then
    log "Updating landing page..."
    rsync -av --delete landing/ /var/www/panel/landing/
fi

# --- 3.5. File Manager ---
if [ -f "cpanel/files.php" ]; then
    mkdir -p /var/www/apps/filemanager
    cp cpanel/files.php /var/www/apps/filemanager/index.php
fi
if [ -f "cpanel/login.php" ]; then
    cp cpanel/login.php /var/www/apps/filemanager/login.php
fi

# --- 3.6. Set permissions ---
log "Setting correct permissions..."
chown -R www-data:www-data /var/www/panel /var/www/apps
find /var/www/panel -type d -exec chmod 755 {} \;
find /var/www/panel -type f -exec chmod 644 {} \;
find /var/www/apps -type d -exec chmod 755 {} \;
find /var/www/apps -type f -exec chmod 644 {} \;

# Secure sensitive files
chmod 640 /var/www/panel/shared/config.php 2>/dev/null || true

# --- 4. Database Schema Updates ---
log "Applying database schema updates..."

# Function to check if table exists
table_exists() {
    local table="$1"
    mysql -N -s -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME' AND table_name = '$table'" 2>/dev/null | grep -q 1
}

# Function to check if column exists
column_exists() {
    local table="$1"
    local column="$2"
    mysql -N -s -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$DB_NAME' AND table_name = '$table' AND column_name = '$column'" 2>/dev/null | grep -q 1
}

# Apply migrations
apply_migration() {
    local name="$1"
    local sql="$2"
    
    if mysql "$DB_NAME" -e "$sql" 2>/dev/null; then
        log "Applied migration: $name"
    else
        warn "Migration $name failed (may already be applied)"
    fi
}

# List of migrations to apply
MIGRATIONS=(
    # Create missing tables
    "CREATE TABLE IF NOT EXISTS domain_traffic (
        id INT AUTO_INCREMENT PRIMARY KEY,
        domain_id INT,
        date DATE,
        bytes_sent BIGINT DEFAULT 0,
        hits INT DEFAULT 0,
        bandwidth_mb INT DEFAULT 0,
        UNIQUE KEY (domain_id, date),
        FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    
    "CREATE TABLE IF NOT EXISTS malware_scans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        domain_id INT,
        status ENUM('running','clean','infected','failed'),
        report TEXT,
        infected_files INT DEFAULT 0,
        scanned_files INT DEFAULT 0,
        scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    
    "CREATE TABLE IF NOT EXISTS app_installations (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    
    "CREATE TABLE IF NOT EXISTS php_config (
        domain_id INT PRIMARY KEY,
        memory_limit VARCHAR(10) DEFAULT '128M',
        max_execution_time INT DEFAULT 300,
        upload_max_filesize VARCHAR(10) DEFAULT '128M',
        post_max_size VARCHAR(10) DEFAULT '128M',
        FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    
    # Fix ftp_users table structure
    "ALTER TABLE ftp_users 
     MODIFY COLUMN userid VARCHAR(64) NOT NULL,
     MODIFY COLUMN passwd VARCHAR(255) NOT NULL,
     MODIFY COLUMN homedir VARCHAR(512) NOT NULL,
     MODIFY COLUMN uid INT DEFAULT 33,
     MODIFY COLUMN gid INT DEFAULT 33,
     MODIFY COLUMN shell VARCHAR(255) DEFAULT '/sbin/nologin',
     ADD COLUMN IF NOT EXISTS client_id INT,
     ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
     ADD INDEX IF NOT EXISTS idx_client_id (client_id);"
    
    # Add missing columns to existing tables
    "ALTER TABLE domains 
     ADD COLUMN IF NOT EXISTS ssl_expiry DATE NULL AFTER ssl_active,
     ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER parent_id;"
    
    "ALTER TABLE clients 
     ADD COLUMN IF NOT EXISTS disk_used_mb INT DEFAULT 0 AFTER package_id,
     ADD COLUMN IF NOT EXISTS bandwidth_mb INT DEFAULT 0 AFTER disk_used_mb;"
    
    "ALTER TABLE mail_users 
     ADD COLUMN IF NOT EXISTS quota_mb INT DEFAULT 1024 AFTER password,
     ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT 1 AFTER quota_mb;"
    
    "ALTER TABLE packages 
     ADD COLUMN IF NOT EXISTS max_bandwidth_mb INT DEFAULT 10240 AFTER max_databases,
     ADD COLUMN IF NOT EXISTS features TEXT AFTER max_bandwidth_mb;"
)

# Apply all migrations
for i in "${!MIGRATIONS[@]}"; do
    apply_migration "Migration_$((i+1))" "${MIGRATIONS[$i]}"
done

# Update package data if needed
mysql "$DB_NAME" << 'SQL_UPDATE'
INSERT IGNORE INTO packages (id, name, price, disk_mb, max_domains, max_emails, max_databases, max_bandwidth_mb, features) VALUES 
(1, 'Starter', 0.00, 2000, 1, 5, 2, 10240, 'Basic Support, 1 Domain, 5 Email Accounts'),
(2, 'Business', 9.99, 10000, 10, 50, 10, 51200, 'Priority Support, 10 Domains, 50 Email Accounts, SSL Included'),
(3, 'Enterprise', 29.99, 50000, 50, 200, 50, 204800, '24/7 Support, 50 Domains, 200 Email Accounts, Advanced Security')
ON DUPLICATE KEY UPDATE 
    max_bandwidth_mb = VALUES(max_bandwidth_mb),
    features = VALUES(features);
SQL_UPDATE

log "Database schema updated successfully"

# --- 5. Fix Nginx Configuration ---
log "Updating Nginx configuration..."

# Ensure default site blocks unwanted access
cat > /etc/nginx/sites-available/000-default << 'DEFAULT'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    
    # Security - reject all traffic to undefined domains
    return 444;
    
    access_log off;
    log_not_found off;
    
    # Minimal root to avoid errors
    root /var/www/html;
    
    # Block all requests
    location / {
        return 444;
    }
}
DEFAULT

# Ensure symlink exists
ln -sf /etc/nginx/sites-available/000-default /etc/nginx/sites-enabled/

# Update existing site configurations with security headers
for site in /etc/nginx/sites-available/*; do
    if [ "$site" != "/etc/nginx/sites-available/000-default" ]; then
        # Add security headers if not present
        if ! grep -q "X-Frame-Options" "$site"; then
            sed -i '/server_name/a\
    # Security headers\
    add_header X-Frame-Options "SAMEORIGIN" always;\
    add_header X-Content-Type-Options "nosniff" always;\
    add_header X-XSS-Protection "1; mode=block" always;\
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;' "$site"
        fi
    fi
done

# --- 6. Fix Client Permissions ---
log "Fixing client permissions..."

# Find all client directories
find /var/www/clients -maxdepth 1 -type d | tail -n +2 | while read CLIENT_DIR; do
    USER=$(basename "$CLIENT_DIR")
    
    if id "$USER" &>/dev/null; then
        log "Fixing permissions for user: $USER"
        
        # Fix log directory
        LOG_DIR="$CLIENT_DIR/logs"
        mkdir -p "$LOG_DIR"
        chown "$USER:$USER" "$LOG_DIR"
        chmod 755 "$LOG_DIR"
        
        # Fix web directories
        if [ -d "$CLIENT_DIR/domains" ]; then
            find "$CLIENT_DIR/domains" -mindepth 2 -maxdepth 2 -name "public_html" -type d | while read WEBROOT; do
                # Set proper ownership
                chown -R "$USER:www-data" "$WEBROOT"
                
                # Set directory permissions
                find "$WEBROOT" -type d -exec chmod 775 {} \;
                find "$WEBROOT" -type d -exec chmod g+s {} \;  # Set SGID
                
                # Set file permissions
                find "$WEBROOT" -type f -exec chmod 664 {} \;
                
                # Special permissions for WordPress
                if [ -f "$WEBROOT/wp-config.php" ]; then
                    chmod 640 "$WEBROOT/wp-config.php"
                    chown "$USER:www-data" "$WEBROOT/wp-config.php"
                fi
                
                # Ensure uploads directory is writable
                if [ -d "$WEBROOT/wp-content/uploads" ]; then
                    chown -R "$USER:www-data" "$WEBROOT/wp-content/uploads"
                    find "$WEBROOT/wp-content/uploads" -type d -exec chmod 775 {} \;
                fi
            done
        fi
        
        # Ensure www-data is in user's group
        usermod -a -G "$USER" www-data 2>/dev/null || true
    fi
done

# --- 7. Update Services Configuration ---
log "Updating service configurations..."

# Update shm-manage sudoers entry
if [ ! -f /etc/sudoers.d/shm ]; then
    echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/shm-manage" > /etc/sudoers.d/shm
    chmod 0440 /etc/sudoers.d/shm
fi

# Update config.sh if new variables are needed
if [ -f "/etc/shm/config.sh" ] && ! grep -q "SERVER_IP" /etc/shm/config.sh; then
    echo "SERVER_IP='$(hostname -I | awk "{print \$1}')'" >> /etc/shm/config.sh
    echo "BACKUP_DIR='/var/backups/shm'" >> /etc/shm/config.sh
fi

# Create backup directory
mkdir -p /var/backups/shm
chmod 700 /var/backups/shm

# --- 8. Service Management ---
log "Restarting services..."

# Function to safely restart service
safe_service_restart() {
    local service="$1"
    
    log "Restarting $service..."
    
    if systemctl is-active --quiet "$service"; then
        # Reload if supported, else restart
        if systemctl reload "$service" 2>/dev/null; then
            log "$service reloaded successfully"
        else
            systemctl restart "$service"
        fi
        
        # Verify service is running
        sleep 2
        if systemctl is-active --quiet "$service"; then
            log "$service is running"
            return 0
        else
            warn "$service failed to start"
            return 1
        fi
    else
        warn "$service is not active"
        return 0
    fi
}

# Restart services in order
ERRORS=0

# Test Nginx configuration first
if ! nginx -t; then
    error "Nginx configuration test failed. Please fix before continuing."
fi

# Restart services
safe_service_restart "nginx" || ERRORS=$((ERRORS+1))
safe_service_restart "php8.2-fpm" || ERRORS=$((ERRORS+1))

# Restart other PHP versions if installed
for v in 8.1 8.3; do
    if systemctl list-units --full -all | grep -q "php$v-fpm"; then
        safe_service_restart "php$v-fpm" || true
    fi
done

# Restart database if needed
if mysql -e "SELECT 1" &>/dev/null; then
    log "MySQL is running"
else
    warn "MySQL is not responding"
    safe_service_restart "mysql" || ERRORS=$((ERRORS+1))
fi

# Restart FTP (ProFTPD)
if systemctl list-units --full -all | grep -q "proftpd"; then
    safe_service_restart "proftpd" || true
fi

# Restart mail services
for service in postfix dovecot; do
    if systemctl list-units --full -all | grep -q "$service"; then
        safe_service_restart "$service" || true
    fi
done

# --- 9. Verification ---
log "Verifying update..."

# Test backend
if /usr/local/bin/shm-manage --help &>/dev/null; then
    log "Backend: OK"
else
    error "Backend test failed"
fi

# Test web access
if curl -s -f "http://localhost" &>/dev/null || curl -s -f "https://localhost" --insecure &>/dev/null; then
    log "Web server: OK"
else
    warn "Web server test failed (may be expected)"
fi

# Test database
if mysql -e "SELECT 1" &>/dev/null; then
    log "Database: OK"
else
    error "Database test failed"
fi

# --- 10. Cleanup ---
log "Cleaning up..."

# Remove old backups (keep last 5)
find /root -name "shm-update-backup-*" -type d | sort -r | tail -n +6 | xargs rm -rf 2>/dev/null || true

# Clear PHP opcache
for v in 8.1 8.2 8.3; do
    if [ -S "/run/php/php$v-fpm.sock" ]; then
        echo "opcache_reset();" | php$v -a 2>/dev/null || true
    fi
done

# --- 11. Final Status ---
echo -e "${GREEN}"
echo "================================================"
echo "   UPDATE COMPLETED SUCCESSFULLY"
echo "================================================"
echo -e "${NC}"

if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}✅ All services restarted successfully${NC}"
else
    echo -e "${YELLOW}⚠️  Update completed with $ERRORS errors${NC}"
fi

echo ""
echo -e "${BLUE}📊 Update Summary:${NC}"
echo "  • Backend updated: /usr/local/bin/shm-manage"
echo "  • Frontend updated: /var/www/panel/"
echo "  • Database migrated: $DB_NAME"
echo "  • Permissions fixed for all clients"
echo "  • Nginx configuration secured"
echo "  • Backup created: $BACKUP_DIR"
echo ""
echo -e "${BLUE}🔧 Next Steps:${NC}"
echo "  1. Test admin panel: https://admin.yourdomain.com"
echo "  2. Test client panel: https://client.yourdomain.com"
echo "  3. Verify backups in /var/backups/shm/"
echo "  4. Monitor logs: tail -f /var/log/nginx/error.log"
echo ""
echo -e "${YELLOW}⚠️  If you encounter issues:${NC}"
echo "  • Rollback: Restore from $BACKUP_DIR"
echo "  • Check logs: /var/log/shm-manage.log"
echo "  • Verify services: systemctl status nginx mysql php8.2-fpm"
echo ""
echo -e "${GREEN}Update completed at $(date)${NC}"
echo "================================================"

# Create update log
cat > "/var/log/shm/update-$BACKUP_TIMESTAMP.log" << UPDATE_LOG
SHM Panel Update Report
=======================
Update Time: $(date)
Backup Location: $BACKUP_DIR
Errors: $ERRORS

Services Restarted:
- Nginx: $(systemctl is-active nginx)
- PHP-FPM 8.2: $(systemctl is-active php8.2-fpm)
- MySQL: $(systemctl is-active mysql)

Database Changes Applied:
- Added missing tables
- Updated existing tables
- Fixed schema inconsistencies

File Updates:
- Backend: /usr/local/bin/shm-manage
- Frontend: /var/www/panel/
- Configuration: /etc/shm/

Verification:
- Backend Test: PASS
- Database Test: PASS
- Web Server Test: $(curl -s -f http://localhost >/dev/null && echo "PASS" || echo "FAIL")

Next Steps:
1. Monitor system logs for 24 hours
2. Test all control panel functions
3. Verify client access
4. Check backup integrity

Rollback Instructions:
1. Stop services: systemctl stop nginx php8.2-fpm
2. Restore files: cp -r $BACKUP_DIR/panel/* /var/www/panel/
3. Restore database: mysql $DB_NAME < $BACKUP_DIR/database-backup.sql.gz
4. Restore backend: cp $BACKUP_DIR/shm-manage.old /usr/local/bin/shm-manage
5. Start services: systemctl start nginx php8.2-fpm mysql
UPDATE_LOG

log "Update log saved to /var/log/shm/update-$BACKUP_TIMESTAMP.log"