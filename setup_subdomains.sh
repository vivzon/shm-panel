#!/bin/bash
# ============================================================================
# SHM Panel - Subdomain Setup Script
# ============================================================================
# This script configures Nginx for subdomain-based hosting
# Run this after initial server setup

set -e

echo "=========================================="
echo "SHM Panel - Subdomain Configuration"
echo "=========================================="

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Error: Please run as root"
    exit 1
fi

# Variables
PANEL_DIR="/var/www/panel"
NGINX_SITES="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"

echo ""
echo "[1/7] Installing phpMyAdmin..."
apt-get update
apt-get install -y phpmyadmin

# Configure phpMyAdmin to use mysqli
echo "Configuring phpMyAdmin..."
cat > /usr/share/phpmyadmin/config.inc.php <<'EOF'
<?php
$cfg['blowfish_secret'] = '$(openssl rand -base64 32)';
$i = 0;
$i++;
$cfg['Servers'][$i]['auth_type'] = 'cookie';
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
EOF

echo ""
echo "[2/7] Installing Roundcube (Webmail)..."
apt-get install -y roundcube roundcube-core roundcube-mysql

echo ""
echo "[3/7] Copying Nginx configurations..."
cp nginx/whm.vivzon.cloud.conf $NGINX_SITES/
cp nginx/cpanel.vivzon.cloud.conf $NGINX_SITES/
cp nginx/phpmyadmin.vivzon.cloud.conf $NGINX_SITES/
cp nginx/webmail.vivzon.cloud.conf $NGINX_SITES/

echo ""
echo "[4/7] Enabling sites..."
ln -sf $NGINX_SITES/whm.vivzon.cloud.conf $NGINX_ENABLED/
ln -sf $NGINX_SITES/cpanel.vivzon.cloud.conf $NGINX_ENABLED/
ln -sf $NGINX_SITES/phpmyadmin.vivzon.cloud.conf $NGINX_ENABLED/
ln -sf $NGINX_SITES/webmail.vivzon.cloud.conf $NGINX_ENABLED/

# Remove default site if exists
rm -f $NGINX_ENABLED/default

echo ""
echo "[5/7] Testing Nginx configuration..."
nginx -t

echo ""
echo "[6/7] Restarting Nginx..."
systemctl restart nginx

echo ""
echo "[7/7] Setting up SSL certificates..."
echo "Installing SSL for all subdomains..."

# Install Certbot if not already installed
if ! command -v certbot &> /dev/null; then
    apt-get install -y certbot python3-certbot-nginx
fi

# Get SSL certificates for all subdomains
certbot --nginx -d whm.vivzon.cloud -d cpanel.vivzon.cloud -d phpmyadmin.vivzon.cloud -d webmail.vivzon.cloud --non-interactive --agree-tos --email vivekrajraja@gmail.com || echo "SSL setup failed - you may need to configure DNS first"

echo ""
echo "=========================================="
echo "✅ Subdomain Setup Complete!"
echo "=========================================="
echo ""
echo "Your services are now available at:"
echo ""
echo "  🔧 WHM Panel:      https://whm.vivzon.cloud"
echo "  👤 cPanel:         https://cpanel.vivzon.cloud"
echo "  🗄️  phpMyAdmin:     https://phpmyadmin.vivzon.cloud"
echo "  📧 Webmail:        https://webmail.vivzon.cloud"
echo ""
echo "⚠️  IMPORTANT: Configure DNS Records"
echo "Add these A records to your DNS:"
echo ""
echo "  whm.vivzon.cloud        → $(hostname -I | awk '{print $1}')"
echo "  cpanel.vivzon.cloud     → $(hostname -I | awk '{print $1}')"
echo "  phpmyadmin.vivzon.cloud → $(hostname -I | awk '{print $1}')"
echo "  webmail.vivzon.cloud    → $(hostname -I | awk '{print $1}')"
echo ""
echo "After DNS propagates, re-run SSL setup:"
echo "  certbot --nginx -d whm.vivzon.cloud -d cpanel.vivzon.cloud -d phpmyadmin.vivzon.cloud -d webmail.vivzon.cloud"
echo ""
echo "=========================================="
