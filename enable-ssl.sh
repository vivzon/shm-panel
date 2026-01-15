#!/bin/bash
# ==============================================================================
# ENABLE SSL (Certbot)
# ==============================================================================
# This script installs SSL certificates for your SHM Panel domains.
# ==============================================================================

if [ "$EUID" -ne 0 ]; then echo "Please run as root (sudo ./enable-ssl.sh)"; exit 1; fi

# Detect Main Domain from Nginx config or ask user
# We'll default to asking to be safe, or check the user requested list.
# The user specifically asked for: admin, client, phpmyadmin, webmail on a domain.

if [ -z "$MAIN_DOMAIN" ]; then
    read -p "Enter Main Domain (e.g. vivzon.cloud): " MAIN_DOMAIN
fi

if [ -z "$MAIN_DOMAIN" ]; then
    echo "Error: Main Domain is required."
    exit 1
fi

echo -e "\033[0;34m[SSL] Installing Certbot...\033[0m"
apt-get update
apt-get install -y certbot python3-certbot-nginx

echo -e "\033[0;34m[SSL] Obtaining Certificates for: $MAIN_DOMAIN ...\033[0m"

# We request a SINGLE cert for all 4 subdomains if possible, or separate.
# Certbot handles multiple -d flags well.

# Check if domains resolve (basic check could be skipped, Certbot will fail if not)
# We'll just run Certbot.

# Domains: admin, client, phpmyadmin, webmail
# We also attempt to secure the root domain if it's set in Nginx (install.sh sets it up for Landing).

DOMAINS="-d admin.$MAIN_DOMAIN -d client.$MAIN_DOMAIN -d phpmyadmin.$MAIN_DOMAIN -d webmail.$MAIN_DOMAIN -d $MAIN_DOMAIN -d www.$MAIN_DOMAIN"

echo "Requesting certificates for:"
echo $DOMAINS

# Run Certbot non-interactively
# --redirect: Force HTTPS
# --agree-tos: Agree to terms
# --no-eff-email: Don't share email
# --email: Required for renewal warnings

certbot --nginx $DOMAINS --non-interactive --agree-tos --email "admin@$MAIN_DOMAIN" --redirect

if [ $? -eq 0 ]; then
    echo -e "\033[0;32m[SUCCESS] SSL Installed Successfully!\033[0m"
    echo "Access your panel securely at:"
    echo " - https://admin.$MAIN_DOMAIN"
    echo " - https://client.$MAIN_DOMAIN"
else
    echo -e "\033[0;31m[ERROR] Certbot failed. Please ensure your DNS records point to this server.\033[0m"
fi
