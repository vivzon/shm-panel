#!/bin/bash
# ==============================================================================
# SHM PANEL - SSL CERTIFICATE INSTALLER (Production)
# ==============================================================================
# This script installs SSL certificates for SHM Panel using Let's Encrypt.
# Features: Domain validation, DNS checking, fallback options, auto-renewal
# ==============================================================================

# Exit on error
set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Logging functions
log() { echo -e "${GREEN}[SSL] $1${NC}"; }
warn() { echo -e "${YELLOW}[WARNING] $1${NC}"; }
error() { echo -e "${RED}[ERROR] $1${NC}"; exit 1; }

# Check root
if [ "$EUID" -ne 0 ]; then 
    error "Please run as root (sudo ./enable-ssl.sh)"
fi

# ------------------------------------------------------------------------------
# DOMAIN VALIDATION FUNCTIONS
# ------------------------------------------------------------------------------

validate_domain() {
    local domain="$1"
    
    # Basic domain format validation
    if [[ ! "$domain" =~ ^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
        error "Invalid domain format: $domain"
    fi
    
    # Check domain length
    if [ ${#domain} -gt 255 ]; then
        error "Domain name too long (max 255 chars): $domain"
    fi
    
    return 0
}

check_dns() {
    local domain="$1"
    local ip="$2"
    
    log "Checking DNS for $domain..."
    
    # Get current server IP
    if [ -z "$ip" ]; then
        ip=$(curl -s http://checkip.amazonaws.com || hostname -I | awk '{print $1}')
    fi
    
    # Check if domain resolves
    local dns_ip
    dns_ip=$(dig +short "$domain" | head -1)
    
    if [ -z "$dns_ip" ]; then
        warn "DNS NOT FOUND: $domain does not resolve to any IP"
        return 1
    fi
    
    if [ "$dns_ip" != "$ip" ]; then
        warn "DNS MISMATCH: $domain resolves to $dns_ip but server IP is $ip"
        echo "You need to update DNS records before continuing."
        return 1
    fi
    
    log "✓ DNS configured correctly for $domain"
    return 0
}

# ------------------------------------------------------------------------------
# NGINX CONFIGURATION
# ------------------------------------------------------------------------------

ensure_nginx_config() {
    local domain="$1"
    local type="$2"  # main, admin, client, webmail, phpmyadmin
    
    local config_file="/etc/nginx/sites-available/$domain"
    
    # If config exists, check if it's valid
    if [ -f "$config_file" ]; then
        log "Nginx config exists for $domain"
        return 0
    fi
    
    warn "Nginx config missing for $domain. Creating temporary config..."
    
    # Determine document root based on type
    case "$type" in
        "admin")
            root="/var/www/panel/whm"
            ;;
        "client")
            root="/var/www/panel/cpanel"
            ;;
        "webmail")
            root="/var/www/apps/webmail"
            ;;
        "phpmyadmin")
            root="/var/www/apps/phpmyadmin"
            ;;
        "main")
            root="/var/www/panel/landing"
            ;;
        *)
            root="/var/www/html"
            ;;
    esac
    
    # Create temporary HTTP config
    cat > "$config_file" << EOF
server {
    listen 80;
    server_name $domain;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    root $root;
    index index.php index.html index.htm;
    
    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    
    location ~ /\. {
        deny all;
    }
}
EOF
    
    # Enable site
    ln -sf "$config_file" "/etc/nginx/sites-enabled/"
    
    # Test Nginx config
    if ! nginx -t; then
        rm -f "$config_file" "/etc/nginx/sites-enabled/$domain"
        error "Failed to create valid Nginx config for $domain"
    fi
    
    log "Created temporary Nginx config for $domain"
}

# ------------------------------------------------------------------------------
# CERTBOT FUNCTIONS
# ------------------------------------------------------------------------------

install_certbot() {
    log "Installing Certbot..."
    
    if command -v certbot >/dev/null 2>&1; then
        log "Certbot already installed"
        return 0
    fi
    
    apt-get update
    apt-get install -y certbot python3-certbot-nginx
    
    if ! command -v certbot >/dev/null 2>&1; then
        error "Failed to install Certbot"
    fi
    
    log "✓ Certbot installed successfully"
}

obtain_certificate() {
    local domains="$1"
    local email="$2"
    
    log "Obtaining SSL certificate for domains:"
    echo "$domains" | tr ' ' '\n' | sed 's/^/- /'
    
    # Create certbot command
    local cmd="certbot --nginx"
    
    # Add each domain with -d flag
    for domain in $domains; do
        cmd="$cmd -d $domain"
    done
    
    cmd="$cmd --non-interactive --agree-tos --email $email --redirect"
    
    # Run certbot
    echo "Running: $cmd"
    
    if eval "$cmd"; then
        log "✓ SSL certificate obtained successfully"
        return 0
    else
        warn "Certbot failed. Trying alternative method..."
        
        # Try standalone mode (requires stopping Nginx)
        systemctl stop nginx
        
        if certbot certonly --standalone --non-interactive --agree-tos \
            --email "$email" --preferred-challenges http \
            $domains; then
            log "✓ Certificate obtained via standalone mode"
            systemctl start nginx
            return 0
        else
            error "Failed to obtain SSL certificate"
        fi
    fi
}

configure_auto_renewal() {
    log "Configuring automatic certificate renewal..."
    
    # Test renewal (dry run)
    if certbot renew --dry-run; then
        log "✓ Auto-renewal configured successfully"
        
        # Add renewal hook to reload Nginx
        mkdir -p /etc/letsencrypt/renewal-hooks/post
        cat > /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh << 'HOOK'
#!/bin/bash
echo "Reloading Nginx after certificate renewal..."
systemctl reload nginx
HOOK
        
        chmod +x /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh
        
    else
        warn "Auto-renewal test failed. Manual renewal may be needed."
    fi
}

# ------------------------------------------------------------------------------
# POST-INSTALLATION CONFIGURATION
# ------------------------------------------------------------------------------

configure_hsts() {
    log "Configuring HSTS (HTTP Strict Transport Security)..."
    
    # Find all SSL-enabled Nginx configs
    find /etc/nginx/sites-available -type f -name "*.conf" -o -name "*" | \
    while read config; do
        if grep -q "ssl_certificate" "$config"; then
            # Add HSTS header if not present
            if ! grep -q "Strict-Transport-Security" "$config"; then
                sed -i '/ssl_certificate/a\
    # HSTS (1 year)\
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;' "$config"
                log "Added HSTS to $config"
            fi
        fi
    done
    
    # Reload Nginx to apply HSTS
    systemctl reload nginx
}

test_ssl() {
    local domain="$1"
    
    log "Testing SSL configuration for $domain..."
    
    # Test with openssl
    if openssl s_client -connect "$domain:443" -servername "$domain" < /dev/null 2>/dev/null | \
       grep -q "Certificate chain"; then
        log "✓ SSL certificate is valid for $domain"
        
        # Get certificate expiry
        expiry=$(openssl s_client -connect "$domain:443" -servername "$domain" 2>/dev/null | \
                 openssl x509 -noout -enddate | cut -d= -f2)
        echo "  Certificate expires: $expiry"
        
    else
        warn "⚠ SSL test failed for $domain"
        return 1
    fi
}

# ------------------------------------------------------------------------------
# MAIN SCRIPT
# ------------------------------------------------------------------------------

clear
echo -e "${BLUE}"
echo "  _____________________________________"
echo " / SHM Panel - SSL Certificate Installer \\"
echo " \_____________________________________/"
echo -e "${NC}"

# Get main domain
if [ -z "$MAIN_DOMAIN" ]; then
    # Try to detect from existing config
    if [ -f "/etc/shm/config.sh" ]; then
        source /etc/shm/config.sh
        log "Detected main domain from config: $MAIN_DOMAIN"
    fi
    
    if [ -z "$MAIN_DOMAIN" ]; then
        echo "Enter your main domain (e.g., example.com)"
        echo "Note: This domain and its subdomains must point to this server's IP"
        read -p "Domain: " MAIN_DOMAIN
    fi
fi

validate_domain "$MAIN_DOMAIN"

# Get admin email
if [ -z "$ADMIN_EMAIL" ]; then
    if [ -f "/etc/shm/config.sh" ] && [ -n "$ADMIN_EMAIL" ]; then
        log "Using admin email from config: $ADMIN_EMAIL"
    else
        DEFAULT_EMAIL="admin@$MAIN_DOMAIN"
        read -p "Admin email for certificate notifications [$DEFAULT_EMAIL]: " ADMIN_EMAIL
        ADMIN_EMAIL=${ADMIN_EMAIL:-$DEFAULT_EMAIL}
    fi
fi

# Validate email format
if [[ ! "$ADMIN_EMAIL" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
    error "Invalid email format: $ADMIN_EMAIL"
fi

# Get server IP for DNS validation
SERVER_IP=$(curl -s http://checkip.amazonaws.com || hostname -I | awk '{print $1}')
log "Server IP detected: $SERVER_IP"
echo "Make sure these DNS records exist:"
echo "  A     @         -> $SERVER_IP"
echo "  A     admin     -> $SERVER_IP"
echo "  A     client    -> $SERVER_IP"
echo "  A     webmail   -> $SERVER_IP"
echo "  A     phpmyadmin -> $SERVER_IP"
echo ""
read -p "Press Enter to continue or Ctrl+C to cancel and configure DNS..."

# Check DNS for all domains
DOMAINS=(
    "$MAIN_DOMAIN"
    "admin.$MAIN_DOMAIN"
    "client.$MAIN_DOMAIN"
    "webmail.$MAIN_DOMAIN"
    "phpmyadmin.$MAIN_DOMAIN"
    "www.$MAIN_DOMAIN"
)

DNS_ERRORS=0
for domain in "${DOMAINS[@]}"; do
    if ! check_dns "$domain" "$SERVER_IP"; then
        DNS_ERRORS=$((DNS_ERRORS + 1))
    fi
done

if [ $DNS_ERRORS -gt 0 ]; then
    warn "$DNS_ERRORS domain(s) have DNS issues"
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Install Certbot
install_certbot

# Ensure Nginx configs exist
log "Configuring Nginx for SSL..."
ensure_nginx_config "$MAIN_DOMAIN" "main"
ensure_nginx_config "www.$MAIN_DOMAIN" "main"
ensure_nginx_config "admin.$MAIN_DOMAIN" "admin"
ensure_nginx_config "client.$MAIN_DOMAIN" "client"
ensure_nginx_config "webmail.$MAIN_DOMAIN" "webmail"
ensure_nginx_config "phpmyadmin.$MAIN_DOMAIN" "phpmyadmin"

# Restart Nginx to apply new configs
systemctl restart nginx

# Obtain certificates
CERT_DOMAINS=""
for domain in "${DOMAINS[@]}"; do
    CERT_DOMAINS="$CERT_DOMAINS $domain"
done

obtain_certificate "$CERT_DOMAINS" "$ADMIN_EMAIL"

# Configure auto-renewal
configure_auto_renewal

# Configure HSTS
configure_hsts

# Test SSL
log "Testing SSL configuration..."
SSL_ERRORS=0
for domain in "${DOMAINS[@]}"; do
    if ! test_ssl "$domain"; then
        SSL_ERRORS=$((SSL_ERRORS + 1))
    fi
done

# Final output
echo -e "${GREEN}"
echo "================================================"
echo "   SSL INSTALLATION COMPLETE"
echo "================================================"
echo -e "${NC}"

echo -e "${BLUE}🔗 Secure URLs:${NC}"
echo "  https://$MAIN_DOMAIN"
echo "  https://admin.$MAIN_DOMAIN"
echo "  https://client.$MAIN_DOMAIN"
echo "  https://webmail.$MAIN_DOMAIN"
echo "  https://phpmyadmin.$MAIN_DOMAIN"
echo ""

echo -e "${BLUE}🔧 Certificate Info:${NC}"
certbot certificates

echo -e "${BLUE}🔄 Auto-renewal:${NC}"
systemctl list-timers | grep certbot || echo "Auto-renewal configured via cron"

echo -e "${BLUE}📋 Next Steps:${NC}"
echo "1. Update your panel configuration to use HTTPS"
echo "2. Test all links work correctly"
echo "3. Set up monitoring for certificate expiry"
echo "4. Consider enabling additional security headers"

if [ $SSL_ERRORS -gt 0 ]; then
    echo -e "${YELLOW}"
    echo "⚠️  WARNING: $SSL_ERRORS domain(s) failed SSL test"
    echo "   Run: certbot certificates"
    echo "   Check: /var/log/letsencrypt/letsencrypt.log"
    echo -e "${NC}"
fi

echo -e "${GREEN}✅ SSL setup completed at $(date)${NC}"
echo "================================================"

# Create SSL configuration file
mkdir -p /etc/shm/ssl
cat > /etc/shm/ssl/status.json << SSL_STATUS
{
    "installed": true,
    "timestamp": "$(date -Iseconds)",
    "main_domain": "$MAIN_DOMAIN",
    "admin_email": "$ADMIN_EMAIL",
    "domains": [
        "$MAIN_DOMAIN",
        "admin.$MAIN_DOMAIN",
        "client.$MAIN_DOMAIN",
        "webmail.$MAIN_DOMAIN",
        "phpmyadmin.$MAIN_DOMAIN"
    ],
    "expiry_check": "0 3 * * * /usr/bin/certbot renew --quiet",
    "renewal_hook": "/etc/letsencrypt/renewal-hooks/post/reload-nginx.sh"
}
SSL_STATUS

log "SSL configuration saved to /etc/shm/ssl/status.json"