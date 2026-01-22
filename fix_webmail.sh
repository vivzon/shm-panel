#!/bin/bash
# SHM Panel - Webmail Fixer (Roundcube)
# Run this as root on your server

source /etc/shm/config.sh

echo "Fixing Webmail..."

if [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
    echo "Error: DB_USER or DB_PASS not found in /etc/shm/config.sh"
    echo "Please edit /etc/shm/config.sh and add:"
    echo "DB_USER='shm_admin'"
    echo "DB_PASS='your_password'"
    exit 1
fi

# 1. Check if Roundcube Database Exists
if ! mysql -e "USE roundcube"; then
    echo "Creating 'roundcube' database..."
    mysql -e "CREATE DATABASE IF NOT EXISTS roundcube;"
    mysql -e "GRANT ALL PRIVILEGES ON roundcube.* TO '$DB_USER'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # Import Schema
    if [ -f "/var/www/apps/webmail/SQL/mysql.initial.sql" ]; then
        mysql roundcube < /var/www/apps/webmail/SQL/mysql.initial.sql
        echo "Imported Roundcube schema."
    else
        echo "Error: Roundcube SQL schema not found. Re-run installer?"
    fi
fi

# 2. Update Roundcube Config
CAT > /var/www/apps/webmail/config/config.inc.php << RC
<?php
\$config['db_dsnw'] = 'mysql://$DB_USER:$DB_PASS@localhost/roundcube';
\$config['default_host'] = 'localhost';
\$config['smtp_server'] = 'localhost';
\$config['smtp_port'] = 25;
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';
\$config['support_url'] = '';
\$config['product_name'] = 'SHM Webmail';
\$config['des_key'] = '$(openssl rand -hex 12)';
\$config['plugins'] = ['archive', 'zipdownload'];
?>
RC

echo "Updated Roundcube Config."

# 3. Fix Permissions
chown -R www-data:www-data /var/www/apps/webmail
chmod -R 755 /var/www/apps/webmail

echo "Done. Try accessing Webmail now."
