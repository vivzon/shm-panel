#!/bin/bash

# ==============================================================================
# SHM PANEL - MAIL SETUP (Postfix + Dovecot)
# ==============================================================================
# Audit & Fixes:
# 1. Removed duplicate bind9 packages.
# 2. Added dovecot-lmtpd explicitly.
# 3. Improved vmail user/group idempotency using getent.
# 4. Verified Postfix chroot paths for LMTP and Auth sockets.
# 5. Added explicit systemctl enable/restart sequences.
# 6. improved error handling and logging.

setup_mail() {
    log "Setting up Mail Server (Postfix + Dovecot)..."
    
    # 1. Install Packages
    # --------------------------------------------------------------------------
    export DEBIAN_FRONTEND=noninteractive
    
    # Removed duplicate bind9 line. Added dovecot-lmtpd.
    # ssl-cert ensures snakeoil availability.
    apt-get install -y \
        bind9 bind9utils \
        ssl-cert \
        dovecot-core dovecot-imapd dovecot-pop3d dovecot-mysql dovecot-lmtpd \
        postfix postfix-mysql

    # 2. SSL Configuration
    # --------------------------------------------------------------------------
    # Ensure Snakeoil Cert exists (Dovecot needs it to start initially)
    # Certbot will replace this in production use-cases later.
    if [ ! -f /etc/ssl/certs/ssl-cert-snakeoil.pem ]; then
        log "Generating self-signed SSL certificate..."
        make-ssl-cert generate-default-snakeoil --force-overwrite
    fi
    
    # 3. Create vmail user (Idempotent)
    # --------------------------------------------------------------------------
    # Use getent to check existence before trying to create
    if ! getent group vmail >/dev/null; then
        groupadd -g 5000 vmail
        log "Created vmail group (5000)"
    fi

    if ! getent passwd vmail >/dev/null; then
        useradd -g vmail -u 5000 vmail -d /var/mail/vhosts -m -s /sbin/nologin
        log "Created vmail user (5000)"
    fi

    # Ensure permissions are correct even if user existed
    mkdir -p /var/mail/vhosts
    chown -R vmail:vmail /var/mail/vhosts
    chmod 750 /var/mail/vhosts
    
    # 4. Configure Dovecot
    # --------------------------------------------------------------------------
    
    # SQL Config
    # Uses 127.0.0.1 to avoid localhost DNS resolution issues
    cat > /etc/dovecot/dovecot-sql.conf.ext << DOVECOT_SQL
driver = mysql
connect = host=127.0.0.1 dbname=$DB_NAME user=$DB_USER password=$DB_PASS
default_pass_scheme = SHA512-CRYPT
password_query = SELECT email as user, password FROM mail_users WHERE email='%u' AND is_active=1;
user_query = SELECT 5000 as uid, 5000 as gid, '/var/mail/vhosts/%d/%n' as home, CONCAT('*:bytes=', quota_mb, 'M') as quota_rule FROM mail_users WHERE email='%u';
DOVECOT_SQL
    
    chmod 600 /etc/dovecot/dovecot-sql.conf.ext
    chown root:root /etc/dovecot/dovecot-sql.conf.ext

    # Auth Config
    # Enable SQL auth, disable system auth
    sed -i 's/!include auth-system.conf.ext/#!include auth-system.conf.ext/' /etc/dovecot/conf.d/10-auth.conf
    sed -i 's/#!include auth-sql.conf.ext/!include auth-sql.conf.ext/' /etc/dovecot/conf.d/10-auth.conf
    
    # Main Config
    # Check for IPv6 support to prevent bind errors
    LISTEN_OPTS="*"
    if [ -f /proc/net/protocols ] && grep -q "ipv6" /proc/net/protocols; then
        LISTEN_OPTS="*, ::"
    fi

    cat > /etc/dovecot/dovecot.conf << DOVECOT_MAIN
!include conf.d/*.conf
!include_try local.conf

protocols = imap pop3 lmtp

listen = $LISTEN_OPTS

# SSL (using snakeoil initially)
ssl = yes
ssl_cert = </etc/ssl/certs/ssl-cert-snakeoil.pem
ssl_key = </etc/ssl/private/ssl-cert-snakeoil.key

# Mail Location (Matches vmail home)
mail_location = maildir:/var/mail/vhosts/%d/%n

# Authentication
auth_mechanisms = plain login
passdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}
userdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}

# Service configuration for Postfix chroot interaction
service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0666
    user = postfix
    group = postfix
  }
  unix_listener auth-userdb {
    mode = 0600
    user = vmail
  }
}

service stats {
    unix_listener stats-reader {
        user = vmail
        group = vmail
        mode = 0660
    }
    unix_listener stats-writer {
        user = vmail
        group = vmail
        mode = 0660
    }
}
DOVECOT_MAIN
    
    # 5. Configure Postfix
    # --------------------------------------------------------------------------
    
    # SQL Maps
    # Using 127.0.0.1 for consistency and speed
    cat > /etc/postfix/mysql-virtual-mailbox-domains.cf << POSTFIX_DOMAINS
user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT 1 FROM mail_domains WHERE domain='%s'
POSTFIX_DOMAINS

    cat > /etc/postfix/mysql-virtual-mailbox-maps.cf << POSTFIX_MAILBOXES
user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT 1 FROM mail_users WHERE email='%s' AND is_active=1
POSTFIX_MAILBOXES

    cat > /etc/postfix/mysql-virtual-alias-maps.cf << POSTFIX_ALIASES
user = $DB_USER
password = $DB_PASS
hosts = 127.0.0.1
dbname = $DB_NAME
query = SELECT destination FROM mail_aliases WHERE source='%s'
POSTFIX_ALIASES

    # Secure the SQL map files
    chmod 600 /etc/postfix/mysql-*.cf
    chown root:root /etc/postfix/mysql-*.cf

    # Main Config
    # Basic Identity
    postconf -e "myhostname = mail.$MAIN_DOMAIN"
    postconf -e "mydomain = $MAIN_DOMAIN"
    postconf -e "myorigin = \$mydomain"
    postconf -e "mydestination = localhost"
    postconf -e "mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128"
    
    # Networking
    postconf -e "inet_interfaces = all"
    postconf -e "inet_protocols = all"
    
    # Virtual configs (MySQL)
    postconf -e "virtual_mailbox_domains = mysql:/etc/postfix/mysql-virtual-mailbox-domains.cf"
    postconf -e "virtual_mailbox_maps = mysql:/etc/postfix/mysql-virtual-mailbox-maps.cf"
    postconf -e "virtual_alias_maps = mysql:/etc/postfix/mysql-virtual-alias-maps.cf"
    
    # Hand off to Dovecot LMTP
    # Matches unix_listener /var/spool/postfix/private/dovecot-lmtp in dovecot.conf
    postconf -e "virtual_transport = lmtp:unix:private/dovecot-lmtp"
    
    # Static UID/GID for virtual users (matches vmail)
    postconf -e "virtual_uid_maps = static:5000"
    postconf -e "virtual_gid_maps = static:5000"
    
    # SASL / Auth (Dovecot)
    # Matches unix_listener /var/spool/postfix/private/auth in dovecot.conf
    postconf -e "smtpd_sasl_type = dovecot"
    postconf -e "smtpd_sasl_path = private/auth"
    postconf -e "smtpd_sasl_auth_enable = yes"
    postconf -e "smtpd_recipient_restrictions = permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination"
    
    # TLS security
    postconf -e "smtpd_tls_cert_file = /etc/ssl/certs/ssl-cert-snakeoil.pem"
    postconf -e "smtpd_tls_key_file = /etc/ssl/private/ssl-cert-snakeoil.key"
    postconf -e "smtpd_use_tls = yes"
    
    # 6. Service Management
    # --------------------------------------------------------------------------
    log "Verifying Dovecot Configuration..."
    if ! doveconf -n > /dev/null; then
        error "Dovecot configuration is invalid. Check /var/log/shm-install.log"
    else
        log "Dovecot config syntax OK."
    fi

    # Enable services to start on boot
    systemctl enable dovecot
    systemctl enable postfix

    # Restart in order: Dovecot first (provides sockets), then Postfix
    log "Restarting Dovecot..."
    if systemctl restart dovecot; then
        log "Dovecot restarted successfully."
    else
        warn "Dovecot failed to restart. Check 'systemctl status dovecot'."
    fi

    log "Restarting Postfix..."
    if systemctl restart postfix; then
        log "Postfix restarted successfully."
    else
        warn "Postfix failed to restart. Check 'systemctl status postfix'."
    fi
}
