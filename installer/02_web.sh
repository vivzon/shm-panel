#!/bin/bash

# ==============================================================================
# SHM PANEL - WEB STACK SETUP
# ==============================================================================

setup_web() {
    log "Setting up Web Stack (Nginx + PHP)..."
    
    # 1. Add Repositories
    log "Adding PHP Repository..."
    add-apt-repository ppa:ondrej/php -y
    apt-get update
    
    # 2. Install Nginx & Certbot
    apt-get install -y nginx certbot python3-certbot-nginx
    
    # 3. Install PHP Versions
    log "Installing PHP Versions (8.1, 8.2, 8.3)..."
    for v in 8.1 8.2 8.3; do
        apt-get install -y php$v-fpm php$v-mysql php$v-common php$v-gd php$v-mbstring \
            php$v-xml php$v-zip php$v-curl php$v-bcmath php$v-intl php$v-imagick php$v-cli \
            php$v-redis php$v-opcache php$v-soap
        
        # Configure PHP Limits
        sed -i "s/upload_max_filesize = .*/upload_max_filesize = 2048M/" /etc/php/$v/fpm/php.ini
        sed -i "s/post_max_size = .*/post_max_size = 2048M/" /etc/php/$v/fpm/php.ini
        sed -i "s/memory_limit = .*/memory_limit = 2048M/" /etc/php/$v/fpm/php.ini
        sed -i "s/max_execution_time = .*/max_execution_time = 300/" /etc/php/$v/fpm/php.ini
        sed -i "s/max_input_time = .*/max_input_time = 300/" /etc/php/$v/fpm/php.ini
        
        # Enable OPCache
        cat > /etc/php/$v/fpm/conf.d/10-opcache.ini << OPCACHE
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
OPCACHE
        
        # Configure PHP-FPM socket permissions
        cat >> /etc/php/$v/fpm/pool.d/www.conf << PHP_SOCKET
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
PHP_SOCKET
    done
    
    # 4. Install Composer
    if ! command -v composer &> /dev/null; then
        log "Installing Composer..."
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    fi
    
    # 5. Install Node.js & NPM (LTS v20)
    if ! command -v node &> /dev/null; then
        log "Installing Node.js..."
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
        apt-get install -y nodejs
    fi
    
    # 6. Configure Nginx Caching
    mkdir -p /var/cache/nginx/{client_temp,proxy_temp,fastcgi_cache}
    chown -R www-data:www-data /var/cache/nginx
    
    cat > /etc/nginx/conf.d/cache.conf << NGINX_CACHE
proxy_cache_path /var/cache/nginx levels=1:2 keys_zone=STATIC:10m inactive=24h max_size=1g;
proxy_temp_path /var/cache/nginx/tmp;

fastcgi_cache_path /var/cache/nginx/fastcgi levels=1:2 keys_zone=PHP:10m inactive=60m max_size=256m;
fastcgi_cache_key "\$scheme\$request_method\$host\$request_uri";
fastcgi_cache_use_stale error timeout invalid_header http_500;
fastcgi_ignore_headers Cache-Control Expires Set-Cookie;
NGINX_CACHE

    # 7. Default Catch-All VHost
    rm -f /etc/nginx/sites-enabled/default
    cat > /etc/nginx/sites-available/000-default << DEFAULT
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444; # Drop requests to undefined domains
}
DEFAULT
    ln -sf /etc/nginx/sites-available/000-default /etc/nginx/sites-enabled/
    
    systemctl restart nginx
}
