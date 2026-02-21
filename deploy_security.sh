#!/bin/bash

# ==============================================================================
# SHM PANEL - SECURITY POST-DEPLOYMENT
# ==============================================================================
# Secures a fresh installation of the SHM-Panel.
# Should be run as root after running install.sh or setup_vivzon.sh

# Colors
GREEN='\033[0;32m'
NC='\033[0m'
log() { echo -e "${GREEN}[INFO]${NC} $1"; }

log "Starting post-deployment security tasks..."

# 1. Clean up Leftover Installers
log "Removing leftover installation files to prevent unauthorized reinstallation..."
rm -f /var/www/panel/install.php
rm -f /var/www/panel/install.sh
rm -f /var/www/panel/setup_vivzon.sh
rm -rf /var/www/panel/installer

# Clean up Windows dev environment installs (if ran from testing dir)
rm -f install.php
rm -f install.sh
rm -f setup_vivzon.sh

# 2. Lock Down Permissions
log "Locking down panel core permissions..."

# The panel root should not be generally writable. 
# It depends on context but ensuring www-data doesn't own script files protects against code execution if a vulnerability exists.
chown -R root:root /var/www/panel 2>/dev/null || true
find /var/www/panel -type d -exec chmod 755 {} \; 2>/dev/null || true
find /var/www/panel -type f -exec chmod 644 {} \; 2>/dev/null || true

# Important: allow config logic to still operate
if [ -d "/var/www/panel/shared" ]; then
    chown -R www-data:www-data "/var/www/panel/shared" 2>/dev/null || true
fi

# Secure config files
if [ -f "/etc/shm/config.sh" ]; then
    chmod 600 /etc/shm/config.sh
fi
if [ -f "/var/www/panel/shared/config.php" ]; then
    chmod 600 "/var/www/panel/shared/config.php"
fi
if [ -f "/var/www/panel/shared/config.local.php" ]; then
    chmod 600 "/var/www/panel/shared/config.local.php"
fi

log "Security script completed successfully!"
exit 0
