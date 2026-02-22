#!/bin/bash

# ==============================================================================
# SHM PANEL - FINALIZATION & CLEANUP
# ==============================================================================

finalize_install() {
    log "Finalizing Installation..."
    
    # 1. Firewall (UFW)
    log "Configuring Firewall..."
    ufw --force reset
    ufw default deny incoming
    ufw default allow outgoing
    
    # Essential ports
    ufw allow 22/tcp comment 'SSH'
    ufw allow 80/tcp comment 'HTTP'
    ufw allow 443/tcp comment 'HTTPS'
    ufw allow 53/tcp comment 'DNS TCP'
    ufw allow 53/udp comment 'DNS UDP'
    
    # Email ports
    ufw allow 25/tcp comment 'SMTP'
    ufw allow 587/tcp comment 'SMTP Submission'
    ufw allow 465/tcp comment 'SMTPS'
    ufw allow 110/tcp comment 'POP3'
    ufw allow 143/tcp comment 'IMAP'
    ufw allow 993/tcp comment 'IMAPS'
    ufw allow 995/tcp comment 'POP3S'
    
    # FTP ports
    ufw allow 21/tcp comment 'FTP'
    ufw allow 50000:51000/tcp comment 'FTP Passive Ports'
    
    echo "y" | ufw enable
    
    # 2. Restart Services
    log "Restarting Services..."
    services=("nginx" "mariadb" "php8.2-fpm" "proftpd" "postfix" "dovecot" "redis-server" "fail2ban" "clamav-daemon")
    
    for service in "${services[@]}"; do
        if systemctl is-enabled "$service" &>/dev/null; then
            systemctl restart "$service" && log "✓ $service restarted" || warn "⚠ $service restart failed"
        fi
    done
    
    # 2.5 Automated SSL Setup
    log "Configuring Automated SSL Setup via Let's Encrypt..."
    if systemctl is-active --quiet nginx; then
        # The list of domains to secure
        CERT_DOMAINS="-d $MAIN_DOMAIN -d admin.$MAIN_DOMAIN -d client.$MAIN_DOMAIN -d filemanager.$MAIN_DOMAIN -d phpmyadmin.$MAIN_DOMAIN -d webmail.$MAIN_DOMAIN -d monitor.$MAIN_DOMAIN"
        
        log "Requesting certificates: $CERT_DOMAINS"
        if certbot --nginx $CERT_DOMAINS --non-interactive --agree-tos -m "$ADMIN_EMAIL" --redirect; then
            log "✓ SSL Certificates installed successfully!"
        else
            warn "⚠ SSL Generation Failed! DNS might not be propagated yet. Retry manually later."
        fi
    else
        warn "⚠ Nginx is not running. Skipping SSL setup."
    fi
    
    # 3. Verification
    verify_installation
    
    # 4. Save Credentials
    cat > /root/shm-credentials.txt << CREDENTIALS
SHM Panel Installation Credentials
==================================
Installation Date: $(date)
Server: $(hostname)
IP Address: $SERVER_IP
Main Domain: $MAIN_DOMAIN

ACCESS URLS:
- Admin Panel:    https://admin.$MAIN_DOMAIN
- Client Panel:   https://client.$MAIN_DOMAIN
- Webmail:        https://webmail.$MAIN_DOMAIN
- phpMyAdmin:     https://phpmyadmin.$MAIN_DOMAIN
- Monitor:        https://monitor.$MAIN_DOMAIN

CREDENTIALS:
- Admin Username: admin
- Admin Password: $ADMIN_PASS
- MySQL Root:     root / $MYSQL_ROOT_PASS
- Panel DB User:  $DB_USER / $DB_PASS

DIRECTORIES:
- Web Root:       /var/www/panel/
- Config:         /etc/shm/

MANAGEMENT:
- Backend Tool:   /usr/local/bin/shm-manage
CREDENTIALS
    chmod 600 /root/shm-credentials.txt
    
    log "Credentials saved to /root/shm-credentials.txt"
    
    echo -e "${GREEN}"
    echo "================================================"
    echo "   SHM PANEL INSTALLED SUCCESSFULLY"
    echo "================================================"
    echo -e "${NC}"
    echo "Please download /root/shm-credentials.txt immediately."
}

verify_installation() {
    log "Verifying installation..."
    
    local errors=0
    
    # Check PHP
    if php -v &>/dev/null; then
        echo -e "${GREEN}✓ PHP: WORKING${NC}"
    else
        echo -e "${RED}✗ PHP: FAILED${NC}"
        ((errors++))
    fi
    
    # Check MySQL
    if mysql -e "SELECT 1" &>/dev/null; then
        echo -e "${GREEN}✓ MySQL: WORKING${NC}"
    else
        echo -e "${RED}✗ MySQL: FAILED${NC}"
        ((errors++))
    fi
    
    if [ $errors -eq 0 ]; then
        log "Verification Passed."
    else
        warn "Verification completed with errors."
    fi
}
