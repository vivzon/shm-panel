#!/bin/bash

# ==============================================================================
# SHM PANEL - PRODUCTION REPAIR
# ==============================================================================
# Use this script to fix "Database Connection" errors after an update.
# It restores the database password from your local credentials file.
# ==============================================================================

if [ "$EUID" -ne 0 ]; then echo "Please run as root"; exit 1; fi

CONFIG_FILE="/var/www/panel/shared/config.php"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: Config file not found at $CONFIG_FILE"
    exit 1
fi

echo "Checking Database Configuration..."

# 1. Look for Default Placeholder
if grep -q "SHMPanel_Secure_Pass_2025" "$CONFIG_FILE"; then
    echo "[!] Found Placeholder Password. Attempting to repair..."
    
    # 2. Find Real Password
    if [ -f "/root/shm-credentials.txt" ]; then
        REAL_PASS=$(grep "DB Pass:" /root/shm-credentials.txt | cut -d: -f2 | xargs)
    elif [ -f "/etc/postfix/mysql-virtual-mailbox-domains.cf" ]; then
        # Fallback: Extract from Postfix Config
        REAL_PASS=$(grep "password =" /etc/postfix/mysql-virtual-mailbox-domains.cf | cut -d= -f2 | xargs)
    fi

    if [ ! -z "$REAL_PASS" ]; then
         sed -i "s/SHMPanel_Secure_Pass_2025/$REAL_PASS/" "$CONFIG_FILE"
         echo "[SUCCESS] Database password restored."
         echo "Trying to restart PHP-FPM..."
         systemctl restart php8.2-fpm
    else
        echo "[ERROR] Could not find database password in /root/shm-credentials.txt or Postfix config."
    fi
else
    echo "[OK] Config does not contain placeholder password."
fi

# 3. Check for File Permissions
chown www-data:www-data "$CONFIG_FILE"
chmod 644 "$CONFIG_FILE"

echo "Repair Complete. Check your admin panel."
