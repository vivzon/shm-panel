#!/bin/bash

# ==============================================================================
# SHM PANEL - MAIL SETUP (Postfix + Dovecot)
# ==============================================================================

setup_mail() {
    log "Setting up Mail Server (Postfix + Dovecot)..."
    
    # 1. Install Packages
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y bind9 bind9utils
    apt-get install -y dovecot-core dovecot-imapd dovecot-pop3d dovecot-mysql postfix postfix-mysql
    
    # 2. Create vmail user
    groupadd -g 5000 vmail 2>/dev/null || true
    useradd -g vmail -u 5000 vmail -d /var/mail/vhosts -m -s /sbin/nologin 2>/dev/null || true
    chown -R vmail:vmail /var/mail/vhosts
    chmod 750 /var/mail/vhosts
    
    # 3. Configure Dovecot
    
    # SQL Config
    cat > /etc/dovecot/dovecot-sql.conf.ext << DOVECOT_SQL
driver = mysql
connect = host=localhost dbname=$DB_NAME user=$DB_USER password=$DB_PASS
default_pass_scheme = SHA512-CRYPT
password_query = SELECT email as user, password FROM mail_users WHERE email='%u' AND is_active=1;
user_query = SELECT 5000 as uid, 5000 as gid, '/var/mail/vhosts/%d/%n' as home, CONCAT('*:bytes=', quota_mb, 'M') as quota_rule FROM mail_users WHERE email='%u';
DOVECOT_SQL
    
    # Auth Config
    sed -i 's/!include auth-system.conf.ext/#!include auth-system.conf.ext/' /etc/dovecot/conf.d/10-auth.conf
    sed -i 's/#!include auth-sql.conf.ext/!include auth-sql.conf.ext/' /etc/dovecot/conf.d/10-auth.conf
    
    # Main Config
    cat > /etc/dovecot/dovecot.conf << DOVECOT_MAIN
!include conf.d/*.conf
!include_try local.conf

protocols = imap pop3 lmtp
listen = *, ::

# SSL (using snakeoil initially, certbot will update)
ssl = yes
ssl_cert = </etc/ssl/certs/ssl-cert-snakeoil.pem
ssl_key = </etc/ssl/private/ssl-cert-snakeoil.key

mail_location = maildir:/var/mail/vhosts/%d/%n

auth_mechanisms = plain login
passdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}
userdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}

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
DOVECOT_MAIN
    
    # 4. Configure Postfix
    
    # SQL Maps
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

    # Main Config
    postconf -e "myhostname = mail.$MAIN_DOMAIN"
    postconf -e "mydomain = $MAIN_DOMAIN"
    postconf -e "myorigin = \$mydomain"
    postconf -e "mydestination = localhost"
    postconf -e "mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128"
    postconf -e "inet_interfaces = all"
    postconf -e "inet_protocols = all"
    
    # Virtual configs
    postconf -e "virtual_mailbox_domains = mysql:/etc/postfix/mysql-virtual-mailbox-domains.cf"
    postconf -e "virtual_mailbox_maps = mysql:/etc/postfix/mysql-virtual-mailbox-maps.cf"
    postconf -e "virtual_alias_maps = mysql:/etc/postfix/mysql-virtual-alias-maps.cf"
    postconf -e "virtual_transport = lmtp:unix:private/dovecot-lmtp"
    postconf -e "virtual_uid_maps = static:5000"
    postconf -e "virtual_gid_maps = static:5000"
    
    # SASL / Auth
    postconf -e "smtpd_sasl_type = dovecot"
    postconf -e "smtpd_sasl_path = private/auth"
    postconf -e "smtpd_sasl_auth_enable = yes"
    postconf -e "smtpd_recipient_restrictions = permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination"
    
    # Restart Services
    systemctl restart dovecot
    systemctl restart postfix
}
