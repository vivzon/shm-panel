#!/bin/bash

# ==============================================================================
# SHM PANEL - UPDATE SCRIPT
# ==============================================================================
# Use this script to deploy changes to PHP/HTML/JS files WITHOUT resetting 
# database passwords or configurations.
# ==============================================================================

if [ "$EUID" -ne 0 ]; then 
    echo -e "\033[0;31mPlease run as root (sudo ./update.sh)\033[0m"
    exit 1
fi

echo -e "\033[0;34m[UPDATE] Deploying SHM Panel Updates...\033[0m"

# 1. Update WHM
echo "[+] Updating WHM (Admin Panel)..."
if [ -d "whm" ]; then
    cp -r whm/* /var/www/panel/whm/
fi

# 1b. Update Landing Page
echo "[+] Updating Landing Page..."
if [ -d "landing" ]; then
    cp -r landing/* /var/www/panel/landing/
fi

# 2. Update CPanel
echo "[+] Updating CPanel (Client Panel)..."
if [ -d "cpanel" ]; then
    cp -r cpanel/* /var/www/panel/cpanel/
    
    # 2a. Update File Manager (Standalone)
    echo "[+] Updating File Manager..."
    if [ -f "cpanel/files.php" ]; then
        cp cpanel/files.php /var/www/apps/filemanager/index.php
    fi
    if [ -f "cpanel/login.php" ]; then
        cp cpanel/login.php /var/www/apps/filemanager/login.php
    fi
fi

# 3. Update Shared Assets (Skipping config.php to preserve DB Pass)
echo "[+] Updating Shared Assets..."
if [ -d "shared" ]; then
    # Copy all except config.php
    find shared -maxdepth 1 -type f -not -name "config.php" -exec cp {} /var/www/panel/shared/ \;
    # If there are subdirectories (like fonts/css), copy them
    find shared -mindepth 1 -type d -exec cp -r {} /var/www/panel/shared/ \;
fi

# 4. Permissions
echo "[+] Fixing Permissions..."
chown -R www-data:www-data /var/www/panel /var/www/apps
chmod -R 755 /var/www/panel

# 4a. Fix Client Permissions for File Manager (Recursive)
echo "[+] Fixing Client Permissions..."
# For each directory in /var/www/clients
for D in /var/www/clients/*; do
    if [ -d "$D" ]; then
        USER_NAME=$(basename "$D")
        echo " -> Fixing $USER_NAME..."
        usermod -a -G $USER_NAME www-data
        chown -R $USER_NAME:$USER_NAME "$D"
        chmod -R 775 "$D"
    fi
done

# 5. Clear Caches (Optional but recommended)
echo "[+] Restarting Nginx to clear any FastCGI caches..."
systemctl restart nginx

echo -e "\033[0;32m[SUCCESS] Update Completed. Please refresh your browser.\033[0m"
