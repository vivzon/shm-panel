#!/bin/bash
# Script to fix phpMyAdmin configuration for the 'phpmyadmin' control user

PMA_CONF="/etc/phpmyadmin/config-db.php"
NEW_PASS="phpmyadmin_pass_123"

if [ -f "$PMA_CONF" ]; then
    # Backup the original config
    cp "$PMA_CONF" "${PMA_CONF}.bak"
    
    # Update the password in config-db.php
    sed -i "s/\$dbpass='.*'/\$dbpass='$NEW_PASS'/g" "$PMA_CONF"
    
    echo "Updated $PMA_CONF with new password."
else
    echo "Error: $PMA_CONF not found! Is phpMyAdmin installed correctly?"
fi
