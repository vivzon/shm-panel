#!/bin/bash

# ==============================================================================
# SHM PANEL - UPDATER (v6.0 to v6.1)
# ==============================================================================
# Run this script as root to upgrade an existing installation.
# It updates code and database schema without losing client data.
# ==============================================================================

if [ "$EUID" -ne 0 ]; then echo "Please run as root"; exit 1; fi

source /etc/shm/config.sh

echo "------------------------------------------------"
echo " Updating SHM Panel..."
echo "------------------------------------------------"

# 1. Update Backend Engine
echo "[1/4] Updating Backend Engine..."
cp shm-manage /usr/local/bin/shm-manage
chmod +x /usr/local/bin/shm-manage

# 2. Update Frontend Code
echo "[2/4] Updating Frontend Files..."
# We overwrite the panel code but PRESERVE user data in /var/www/clients
cp -r cpanel/* /var/www/panel/cpanel/
cp -r whm/* /var/www/panel/whm/
cp -r landing/* /var/www/panel/landing/ 2>/dev/null

# Copy new shared logic but BE CAREFUL with config
# Ideally config.php is generated once.
# If we have new keys, we might need manual handling, but for now we skip config overwrite if it exists
# actually we might need to update helper functions in config.php
if [ -f "shared/config.php" ]; then
    # Backup old config
    cp /var/www/panel/shared/config.php /var/www/panel/shared/config.php.bak
    # We can't just overwrite because it has the DB password.
    # We should only overwrite if we parse the password out, or if the user manages it.
    # Strategy: Do NOT overwrite config.php. Assume updated code works with old config variables.
    # Exception: If we added new helper functions to config.php (like 'cmd'), we need them.
    # The 'cmd' function is indeed in config.php. 
    # Let's try to patch it or warn.
    # Better: Read credentials from /etc/shm/config.sh and RE-GENERATE config.php if needed?
    # No, that's risky.
    # Safest: Let's assume the user hasn't modified shared/config.php logic, only values.
    # We can read values from current config and write new one.
    
    # Simple Patch: Copy the new file but inject the old password.
    OLD_PASS=$(grep "\$db_pass =" /var/www/panel/shared/config.php | cut -d "'" -f 2)
    cp shared/config.php /var/www/panel/shared/config.php
    sed -i "s/SHMPanel_Secure_Pass_2025/$OLD_PASS/" /var/www/panel/shared/config.php
    echo "      (Config.php updated, password preserved)"
fi

# 3. Update Database Schema
echo "[3/4] Updating Database Schema..."

# Add 'parent_id' to domains if missing
mysql $DB_NAME -e "
    SET @exist := (SELECT count(*) FROM information_schema.columns WHERE table_schema='$DB_NAME' AND table_name='domains' AND column_name='parent_id');
    SET @sql := IF(@exist = 0, 'ALTER TABLE domains ADD COLUMN parent_id INT DEFAULT NULL;', 'SELECT \"Column parent_id exists\";');
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
"

# Add 'app_installations' table
mysql $DB_NAME -e "
    CREATE TABLE IF NOT EXISTS app_installations (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        client_id INT, 
        domain_id INT, 
        app_type VARCHAR(20), 
        db_name VARCHAR(64), 
        db_user VARCHAR(32), 
        db_pass VARCHAR(255), 
        status VARCHAR(20), 
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
"

# Add Monitoring Tables
mysql $DB_NAME -e "
    CREATE TABLE IF NOT EXISTS domain_traffic (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, date DATE, bytes_sent BIGINT DEFAULT 0, hits INT DEFAULT 0, UNIQUE KEY (domain_id, date));
    CREATE TABLE IF NOT EXISTS malware_scans (id INT AUTO_INCREMENT PRIMARY KEY, domain_id INT, status ENUM('running','clean','infected','failed'), report TEXT, scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
"

# 4. Finalize
echo "[4/4] Restarting Services..."
systemctl reload nginx php8.2-fpm

echo "------------------------------------------------"
echo " Update Complete!"
echo "------------------------------------------------"
