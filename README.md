# 🚀 SHM Panel - Complete Installation Guide

**Enterprise-Grade Web Hosting Control Panel with Subdomain Architecture**

SHM Panel is a production-ready hosting control panel with separate subdomains for WHM, cPanel, phpMyAdmin, and Webmail. This guide will walk you through the complete installation process step-by-step.

---

## 📋 **Prerequisites**

Before starting, ensure you have:

- ✅ **Fresh VPS** running Ubuntu 20.04/22.04 or Debian 11/12
- ✅ **Root access** to the server
- ✅ **Domain name** (e.g., `vivzon.cloud`)
- ✅ **DNS access** to create A records
- ✅ **Minimum**: 2GB RAM, 20GB SSD, 1 CPU core

---

## 🎯 **Installation Overview**

The installation is divided into 3 main phases:

1. **Server Setup** (15 minutes) - Install system dependencies
2. **Panel Installation** (5 minutes) - Configure database and admin account
3. **Subdomain Configuration** (10 minutes) - Set up WHM, cPanel, phpMyAdmin, Webmail

**Total Time**: ~30 minutes

---

## 📖 **Step-by-Step Installation**

### **Phase 1: Server Setup**

#### Step 1: Connect to Your Server

```bash
ssh root@YOUR_SERVER_IP
```

#### Step 2: Download SHM Panel

```bash
cd /root
git clone https://github.com/yourusername/shm-panel.git
cd shm-panel
```

#### Step 3: Run System Setup Script

```bash
chmod +x setup_vivzon.sh
./setup_vivzon.sh
```

**What this does:**
- ✅ Updates system packages
- ✅ Installs Nginx, PHP 8.2, MariaDB
- ✅ Installs Postfix/Dovecot (mail server)
- ✅ Installs Bind9 (DNS server)
- ✅ Configures UFW firewall
- ✅ Installs Fail2ban
- ✅ Sets up Redis caching

**⏱️ Duration**: 10-15 minutes

---

### **Phase 2: Panel Installation**

#### Step 4: Copy Panel Files

```bash
mkdir -p /var/www/panel
cp -r /root/shm-panel/* /var/www/panel/
chown -R www-data:www-data /var/www/panel
chmod -R 755 /var/www/panel
```

#### Step 5: Configure Temporary Nginx

```bash
# Create temporary config for installer
cat > /etc/nginx/sites-available/installer <<'EOF'
server {
    listen 80 default_server;
    root /var/www/panel;
    index install.php index.php;
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
EOF

# Enable and restart
ln -sf /etc/nginx/sites-available/installer /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl restart nginx
```

#### Step 6: Run Web Installer

1. Open browser and visit: `http://YOUR_SERVER_IP/install.php`

2. Fill in the form:
   - **Database Host**: `localhost`
   - **Database Name**: `shm_panel`
   - **Database User**: `root`
   - **Database Password**: (from `/root/.my.cnf`)
   - **Admin Username**: `admin`
   - **Admin Password**: (choose a strong password)

3. Click **"Install SHM Panel with Security Features"**

4. Wait for installation to complete (creates 15 tables)

**✅ Success**: You should see "Installation Successful!"

#### Step 7: Apply Database Schema Fixes

```bash
cd /var/www/panel
mysql -u root -p shm_panel < migrations/003_fix_all_schema_issues.sql
```

**What this does:**
- ✅ Creates 4 missing tables (client_databases, client_db_users, app_installations, ftp_users)
- ✅ Adds missing columns to existing tables
- ✅ Fixes php_config table structure

---

### **Phase 3: Subdomain Configuration**

#### Step 8: Configure DNS Records

**IMPORTANT**: Do this BEFORE running the subdomain setup!

Go to your DNS provider and add these **A records**:

```
whm.vivzon.cloud        → YOUR_SERVER_IP
cpanel.vivzon.cloud     → YOUR_SERVER_IP
phpmyadmin.vivzon.cloud → YOUR_SERVER_IP
webmail.vivzon.cloud    → YOUR_SERVER_IP
```

**⏱️ Wait 5-10 minutes** for DNS propagation.

**Verify DNS**:
```bash
dig whm.vivzon.cloud +short
# Should return YOUR_SERVER_IP
```

#### Step 9: Run Subdomain Setup Script

```bash
cd /var/www/panel
chmod +x setup_subdomains.sh
./setup_subdomains.sh
```

**What this does:**
- ✅ Installs phpMyAdmin
- ✅ Installs Roundcube (Webmail)
- ✅ Configures Nginx for all 4 subdomains
- ✅ Attempts to install SSL certificates

**⏱️ Duration**: 5-10 minutes

#### Step 10: Setup SSL Certificates

If SSL setup failed in Step 9 (DNS not propagated), run manually:

```bash
certbot --nginx \
  -d whm.vivzon.cloud \
  -d cpanel.vivzon.cloud \
  -d phpmyadmin.vivzon.cloud \
  -d webmail.vivzon.cloud \
  --email YOUR_EMAIL@example.com
```

#### Step 11: Secure Installation

```bash
# Remove installer
rm /var/www/panel/install.php

# Remove test files
rm -rf /var/www/panel/tests

# Remove temporary Nginx config
rm /etc/nginx/sites-enabled/installer

# Restart Nginx
systemctl restart nginx
```

---

## ✅ **Verification**

### Test All Services

```bash
# Test HTTP/HTTPS access
curl -I https://whm.vivzon.cloud
curl -I https://cpanel.vivzon.cloud
curl -I https://phpmyadmin.vivzon.cloud
curl -I https://webmail.vivzon.cloud

# Check service status
systemctl status nginx php8.2-fpm mariadb redis-server fail2ban

# Check firewall
ufw status
```

### Access Your Panels

Open your browser and visit:

- 🔧 **WHM (Admin Panel)**: https://whm.vivzon.cloud
- 👤 **cPanel (Client Panel)**: https://cpanel.vivzon.cloud
- 🗄️ **phpMyAdmin**: https://phpmyadmin.vivzon.cloud
- 📧 **Webmail**: https://webmail.vivzon.cloud

**Login Credentials:**
- WHM/cPanel: Use admin credentials from Step 6
- phpMyAdmin: Use MySQL root credentials
- Webmail: Use email account credentials (create in cPanel)

---

## 🔒 **Post-Installation Security**

### Immediate Actions

```bash
# 1. Change default passwords
# Visit WHM → Change admin password

# 2. Configure firewall
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 22/tcp
ufw allow 25/tcp   # SMTP
ufw allow 587/tcp  # SMTP Submission
ufw allow 993/tcp  # IMAPS
ufw allow 995/tcp  # POP3S
ufw enable

# 3. Enable automatic updates
apt install unattended-upgrades
dpkg-reconfigure --priority=low unattended-upgrades

# 4. Configure backups
# See VPS_DEPLOYMENT_GUIDE.md for backup setup
```

### Optional: Restrict phpMyAdmin Access

Edit `/etc/nginx/sites-available/phpmyadmin.vivzon.cloud.conf`:

```nginx
location / {
    # Only allow from your IP
    allow YOUR_IP_ADDRESS;
    deny all;
    try_files $uri $uri/ /index.php?$query_string;
}
```

Then restart Nginx:
```bash
systemctl restart nginx
```

---

## 🐛 **Troubleshooting**

### Common Issues

**1. DNS not resolving**
```bash
# Check DNS propagation
dig whm.vivzon.cloud
nslookup whm.vivzon.cloud

# Wait longer (up to 24 hours in some cases)
```

**2. SSL certificate fails**
```bash
# Ensure DNS is propagated first
# Check firewall allows port 80
ufw allow 80/tcp

# Retry SSL
certbot --nginx -d whm.vivzon.cloud
```

**3. 502 Bad Gateway**
```bash
# Restart services
systemctl restart php8.2-fpm nginx

# Check logs
tail -f /var/log/nginx/error.log
```

**4. Database connection error**
```bash
# Check MariaDB is running
systemctl status mariadb

# Get root password
cat /root/.my.cnf

# Test connection
mysql -u root -p
```

**5. Permission denied errors**
```bash
# Fix permissions
chown -R www-data:www-data /var/www/panel
chmod -R 755 /var/www/panel
```

### Check Logs

```bash
# Nginx error log
tail -f /var/log/nginx/error.log

# PHP error log
tail -f /var/log/php8.2-fpm.log

# Security log
tail -f /var/log/shm-security.log

# WHM specific
tail -f /var/log/nginx/whm-error.log

# cPanel specific
tail -f /var/log/nginx/cpanel-error.log
```

---

## 📚 **Additional Documentation**

- **[VPS Deployment Guide](VPS_DEPLOYMENT_GUIDE.md)** - Detailed 11-step guide
- **[Subdomain Setup Guide](SUBDOMAIN_SETUP_GUIDE.md)** - Subdomain configuration details
- **[Database Schema Fix Guide](DATABASE_SCHEMA_FIX_GUIDE.md)** - Database troubleshooting
- **[Security Implementation](SECURITY_IMPLEMENTATION.md)** - Security features guide

---

## 🎯 **What's Next?**

### Create Your First Client Account

1. Login to WHM: https://whm.vivzon.cloud
2. Go to **Accounts** → **Create Account**
3. Fill in:
   - Username: `testuser`
   - Domain: `example.com`
   - Email: `admin@example.com`
   - Password: (strong password)
   - Package: Select a package
4. Click **Create**

### Configure Email

1. Login to cPanel: https://cpanel.vivzon.cloud
2. Go to **Email Accounts**
3. Create email: `info@example.com`
4. Access via Webmail: https://webmail.vivzon.cloud

### Manage Databases

1. Visit phpMyAdmin: https://phpmyadmin.vivzon.cloud
2. Login with MySQL root credentials
3. Create databases for your applications

---

## 🆘 **Getting Help**

### System Status Check

```bash
# Quick health check
systemctl status nginx php8.2-fpm mariadb

# Disk space
df -h

# Memory usage
free -h

# CPU load
top
```

### Support Resources

- **Documentation**: Check all `.md` files in the project
- **Logs**: Always check logs first for errors
- **Community**: (Add your support channels here)

---

## 🎉 **Success!**

You now have a fully functional hosting control panel with:

- ✅ Separate subdomains for each service
- ✅ Enterprise-grade security
- ✅ SSL certificates
- ✅ Full mail server
- ✅ DNS management
- ✅ Database management
- ✅ Automated backups (configure as needed)

**Enjoy your new hosting panel!** 🚀

---

## 📊 **Quick Reference**

| Service | URL | Purpose |
|---------|-----|---------|
| WHM | https://whm.vivzon.cloud | Server administration |
| cPanel | https://cpanel.vivzon.cloud | Client control panel |
| phpMyAdmin | https://phpmyadmin.vivzon.cloud | Database management |
| Webmail | https://webmail.vivzon.cloud | Email access |

**Default Ports:**
- HTTP: 80
- HTTPS: 443
- SSH: 22
- SMTP: 25, 587
- IMAP: 993
- POP3: 995
- DNS: 53
