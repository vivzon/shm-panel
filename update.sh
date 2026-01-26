#!/bin/bash

# ==============================================================================
# SHM PANEL - QUICK UPDATE UTILITY
# ==============================================================================
# Run this to apply code changes to the live server.
# ==============================================================================

GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

if [ "$EUID" -ne 0 ]; then echo "Please run as root"; exit 1; fi

echo -e "${BLUE}[UPDATE] Starting SHM Panel Update...${NC}"

# Source Config
if [ -f "/etc/shm/config.sh" ]; then
    source /etc/shm/config.sh
else
    # Fallback/Defaults
    DB_NAME="shm_panel"
    DB_USER="root"
    # DB_PASS should be in env or config
fi

# 0. System Dependencies
echo -e "${GREEN} -> Checking System Dependencies...${NC}"
if ! dpkg -l | grep -q pure-ftpd-mysql; then
    echo "Installing pure-ftpd-mysql..."
    apt-get update -qq
    apt-get install -y pure-ftpd-mysql
fi

# 1. Update Backend Engine
if [ -f "shm-manage" ]; then
    echo -e "${GREEN} -> Updating shm-manage...${NC}"
    cp shm-manage /usr/local/bin/shm-manage
    chmod +x /usr/local/bin/shm-manage
fi

# 2. Update Frontend Files
echo -e "${GREEN} -> Updating Frontend Files...${NC}"

# WHM
if [ -d "whm" ]; then
    cp -r whm/* /var/www/panel/whm/
fi

# cPanel
if [ -d "cpanel" ]; then
    cp -r cpanel/* /var/www/panel/cpanel/
fi

# Landing
if [ -d "landing" ]; then
    cp -r landing/* /var/www/panel/landing/
fi

# Shared (Attempt smart update)
if [ -d "shared" ]; then
    if [ -f "/var/www/panel/shared/config.php" ]; then
        # If config exists, try to preserve DB pass
        OLD_PASS=$(grep "\$db_pass =" /var/www/panel/shared/config.php | cut -d "'" -f 2)
        cp shared/config.php /var/www/panel/shared/config.php
        # Re-inject password if it was a placeholder in repo
        if [ ! -z "$OLD_PASS" ]; then
            sed -i "s/SHMPanel_Secure_Pass_2025/$OLD_PASS/" /var/www/panel/shared/config.php
        fi
    else
        # Fresh copy
        cp shared/config.php /var/www/panel/shared/config.php
    fi
fi

# File Manager Updates (if exists in cpanel folder)
mkdir -p /var/www/apps/filemanager
if [ -f "cpanel/files.php" ]; then
    cp cpanel/files.php /var/www/apps/filemanager/index.php
fi
if [ -f "cpanel/login.php" ]; then
    cp cpanel/login.php /var/www/apps/filemanager/login.php
fi

# 3. Apply DB Schema Changes (Idempotent)
if [ -f "install.php" ]; then
    # We can't easily run php installer via CLI without args, and we don't want to reset DB.
    # So we assume the user might need to run DB migrations manually or via specific SQL updates.
    # For now, let's just create the missing tables if they don't exist.
    DB_NAME="shm_panel" # Assuming default, strictly we should read from config.sh
    if [ -f "/etc/shm/config.sh" ]; then
        source /etc/shm/config.sh
    fi
    
    echo -e "${GREEN} -> Verifying Database Schema...${NC}"
    # Basic Check for tables added in recent updates
    mysql $DB_NAME -e "CREATE TABLE IF NOT EXISTS domain_traffic (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, date DATE, bytes_sent BIGINT DEFAULT 0, hits INT DEFAULT 0, UNIQUE KEY (domain_id, date));" 2>/dev/null
    mysql $DB_NAME -e "CREATE TABLE IF NOT EXISTS malware_scans (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, status ENUM('running','clean','infected','failed'), report TEXT, scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);" 2>/dev/null
    
    # New Tables
    mysql $DB_NAME -e "CREATE TABLE IF NOT EXISTS app_installations (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, domain_id INT, app_type VARCHAR(20), db_name VARCHAR(64), db_user VARCHAR(32), db_pass VARCHAR(255), status VARCHAR(20), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);" 2>/dev/null
    mysql $DB_NAME -e "CREATE TABLE IF NOT EXISTS php_config (domain_id INT PRIMARY KEY, memory_limit VARCHAR(10) DEFAULT '512M');" 2>/dev/null
    mysql $DB_NAME -e "CREATE TABLE IF NOT EXISTS ftp_users (userid VARCHAR(64) PRIMARY KEY, passwd VARCHAR(128), homedir VARCHAR(255), uid INT, gid INT);" 2>/dev/null
    
    # FIX: Ensure missing columns exist (for existing installs)
    # ftp_users: passwd
    COL_EXIST=$(mysql -N -s -e "SELECT count(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='ftp_users' AND COLUMN_NAME='passwd'")
    if [ "$COL_EXIST" -eq 0 ]; then
        echo "Fixing ftp_users schema (adding passwd)..."
        mysql $DB_NAME -e "ALTER TABLE ftp_users ADD COLUMN passwd VARCHAR(128) AFTER userid;"
    fi

    # client_databases: domain_id
    # Create table if not exists first (it was missing from previous update script)
    mysql $DB_NAME -e "CREATE TABLE IF NOT EXISTS client_databases (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, domain_id INT, db_name VARCHAR(64), db_user VARCHAR(32), db_pass VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);" 2>/dev/null
    
    COL_EXIST=$(mysql -N -s -e "SELECT count(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='client_databases' AND COLUMN_NAME='domain_id'")
    if [ "$COL_EXIST" -eq 0 ]; then
        echo "Fixing client_databases schema (adding domain_id)..."
        mysql $DB_NAME -e "ALTER TABLE client_databases ADD COLUMN domain_id INT AFTER client_id;"
    fi

    # 3.1 Configure Pure-FTPd MySQL
    echo -e "${GREEN} -> Configuring FTP Service...${NC}"
    if [ ! -f "/etc/pure-ftpd/db/mysql.conf" ]; then
        echo "Creating Pure-FTPd MySQL config..."
        # Extract DB PASS if not set
        if [ -z "$DB_PASS" ]; then
            # Try to read from /root/.my.cnf if available or config.sh
            # Assume config.sh has it or user has to set it.
            # For now, let's assume standard install has it in config.sh
            :
        fi
        
        cat > /etc/pure-ftpd/db/mysql.conf <<EOF
MYSQLServer     localhost
MYSQLPort       3306
MYSQLUser       $DB_USER
MYSQLPassword   $DB_PASS
MYSQLDatabase   $DB_NAME
MYSQLCrypt      md5
MYSQLGetPW      SELECT passwd FROM ftp_users WHERE userid="\L"
MYSQLGetUID     SELECT uid FROM ftp_users WHERE userid="\L"
MYSQLGetGID     SELECT gid FROM ftp_users WHERE userid="\L"
MYSQLGetDir     SELECT homedir FROM ftp_users WHERE userid="\L"
EOF
    fi
    
    # Link config if not effectively used (Debian specific)
    if [ -d "/etc/pure-ftpd/conf" ]; then
        echo "yes" > /etc/pure-ftpd/conf/ChrootEveryone
        echo "yes" > /etc/pure-ftpd/conf/CreateHomeDir
        echo "no" > /etc/pure-ftpd/conf/PAMAuthentication
        echo "yes" > /etc/pure-ftpd/conf/UnixAuthentication
        # On Debian/Ubuntu pure-ftpd-mysql usually links /etc/pure-ftpd/db/mysql.conf via /etc/pure-ftpd/auth/
        # Check if 60mysql exists in auth
        if [ ! -f "/etc/pure-ftpd/auth/60mysql" ] && [ -d "/etc/pure-ftpd/auth" ]; then
             ln -s /etc/pure-ftpd/conf/MySQLConfigFile /etc/pure-ftpd/auth/60mysql || true
             # Actually typically it's configured in /etc/pure-ftpd/db/mysql.conf and purely enabled by having the package.
             # Let's write valid conf param.
             echo "/etc/pure-ftpd/db/mysql.conf" > /etc/pure-ftpd/conf/MySQLConfigFile
        fi
    fi
fi

# 4. Fix Permissions
echo -e "${GREEN} -> Applying Permissions...${NC}"
chown -R www-data:www-data /var/www/panel /var/www/apps
chmod -R 755 /var/www/panel

# 5. Restart Services
echo -e "${GREEN} -> Reloading Services...${NC}"
systemctl reload nginx
systemctl reload php8.2-fpm
if systemctl is-active --quiet pure-ftpd-mysql; then
    systemctl restart pure-ftpd-mysql
elif systemctl is-active --quiet pure-ftpd; then
    systemctl restart pure-ftpd
fi
systemctl reload php8.2-fpm

echo -e "${GREEN}================================================"
echo -e "   UPDATE COMPLETED SUCCESSFULLY"
echo -e "================================================${NC}"
