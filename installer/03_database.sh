#!/bin/bash

# ==============================================================================
# SHM PANEL - DATABASE SETUP (STABLE & NON-BLOCKING)
# ==============================================================================

# --- Configuration (Adjust as needed) ---
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS:-$(openssl rand -hex 12)}"
DB_NAME="${DB_NAME:-shm_panel}"
DB_USER="${DB_USER:-shm_user}"
DB_PASS="${DB_PASS:-$(openssl rand -hex 12)}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
MAIN_DOMAIN="${MAIN_DOMAIN:-$(hostname -f)}"

# --- Logging Helpers ---
log()   { echo -e "\e[32m[INFO] $1\e[0m"; }
warn()  { echo -e "\e[33m[WARN] $1\e[0m"; }
error() { echo -e "\e[31m[ERR] $1\e[0m"; exit 1; }

setup_database() {
    log "Step 1: Installing MariaDB and Redis..."
    
    # Pre-configure MariaDB to avoid interactive prompts
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y mariadb-server mariadb-client redis-server >/dev/null 2>&1
    
    # WSL FIX: Disable Native AIO to prevent hanging on startup
    mkdir -p /etc/mysql/mariadb.conf.d/
    echo -e "[mysqld]\ninnodb_use_native_aio=0" > /etc/mysql/mariadb.conf.d/99-wsl.cnf
    
    # Remove Apache2 now to prevent port 80 conflict with Nginx (installed later)
    if dpkg -l apache2 &>/dev/null 2>&1; then
        warn "Apache2 detected -- removing to prevent port 80 conflict with Nginx..."
        systemctl stop apache2 2>/dev/null || true
        apt-get purge -y apache2 apache2-utils apache2-bin libapache2-mod-php* >/dev/null 2>&1 || true
        apt-get autoremove -y >/dev/null 2>&1 || true
        log "Apache2 removed."
    fi

    systemctl enable redis-server mariadb >/dev/null 2>&1
    systemctl start redis-server || true
    systemctl start mariadb || true

    # Wait up to 30 seconds for MariaDB to actually become ready
    log "Waiting for MariaDB to become ready..."
    DB_READY=0
    for i in $(seq 1 30); do
        if mysqladmin ping --no-defaults -u root --silent 2>/dev/null || \
           mysqladmin ping -u root --silent 2>/dev/null; then
            log "MariaDB is ready (after ${i}s)."
            DB_READY=1
            break
        fi
        sleep 1
    done
    if [ "$DB_READY" -eq 0 ]; then
        warn "MariaDB not ready after 30s — checking status..."
        systemctl status mariadb --no-pager || true
        journalctl -xeu mariadb --no-pager -n 30 || true
        # Force restart attempt
        systemctl restart mariadb || true
        sleep 5
    fi

    # Step 2: Set Root Password & Secure (NO-HANG Logic)
    log "Step 2: Securing MariaDB..."
    
    # Create the credential file so root can always log in from CLI
    cat > /root/.my.cnf << EOF
[client]
user=root
password=$MYSQL_ROOT_PASS
EOF
    chmod 600 /root/.my.cnf

    # ATTEMPT 1: Try setting password via Unix Socket (Standard for 10.4+)
    # This works without stopping the service or using mysqld_safe
    if mysql --no-defaults -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;" &>/dev/null; then
        log "Root password set successfully."
    
    # ATTEMPT 2: Already configured?
    elif mysql -e "SELECT 1" &>/dev/null; then
        log "Root access already verified."
        
    # ATTEMPT 3: The Bootstrap Fallback (If logic above fails, use a non-blocking init file)
    else
        warn "Initial access denied. Attempting bootstrap reset..."
        echo "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS'; FLUSH PRIVILEGES;" > /tmp/db_init.sql
        systemctl stop mariadb
        # Bootstrap runs the SQL then exits immediately - NO HANGING
        # Use mariadbd (MariaDB 10.4+) with fallback to mysqld
        local db_binary
        db_binary=$(command -v mariadbd 2>/dev/null || command -v mysqld 2>/dev/null || echo "/usr/sbin/mariadbd")
        "$db_binary" --user=mysql --bootstrap --init-file=/tmp/db_init.sql >/dev/null 2>&1
        rm /tmp/db_init.sql
        systemctl start mariadb
    fi

    # Clean up anonymous users and test DB
    mysql -e "DELETE FROM mysql.user WHERE User='';"
    mysql -e "DROP DATABASE IF EXISTS test;"
    mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # Step 3: Performance Optimization
    log "Step 3: Applying Optimizations..."
    cat > /etc/mysql/mariadb.conf.d/99-shm-panel.cnf << MYSQL_OPT
[mysqld]
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_file_per_table = 1
innodb_flush_method = O_DIRECT
max_connections = 200
query_cache_size = 64M
query_cache_type = 1
MYSQL_OPT
    
    systemctl restart mariadb
    
    # Step 4: Create Panel Database and User
    log "Step 4: Creating Database: $DB_NAME"
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # Step 5: Import Full Schema
    log "Step 5: Importing Table Schemas..."
    log "Step 5: Importing Table Schemas..."
    if [ -f "installer/schema.sql" ]; then
        mysql "$DB_NAME" < installer/schema.sql
    elif [ -f "schema.sql" ]; then
        mysql "$DB_NAME" < schema.sql
    else
        warn "Could not find schema.sql to import! Database initialized empty."
    fi

    # Default Admin & Mail Domain seeding
    cat << SQL | mysql "$DB_NAME"
INSERT IGNORE INTO admins (username, password, email, role) VALUES 
('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$ADMIN_EMAIL', 'superadmin');

INSERT IGNORE INTO mail_domains (domain) VALUES ('$MAIN_DOMAIN');
SQL

    log "Database setup completed successfully!"
}

# Run the script
setup_database
