# SHM Panel - VPS Deployment Guide

Complete step-by-step guide to deploy SHM Panel with security features on your VPS server.

## 📋 Prerequisites

### Server Requirements
- **OS**: Ubuntu 20.04/22.04 or Debian 11/12
- **RAM**: Minimum 2GB (4GB recommended)
- **Storage**: Minimum 20GB
- **Root Access**: Required
- **Domain**: Pointed to your server IP

### Local Requirements
- SSH client (Terminal, PuTTY, etc.)
- Git (optional, for cloning)
- Your domain DNS configured

---

## 🚀 Deployment Steps

### Step 1: Connect to Your VPS

```bash
# SSH into your server
ssh root@your-server-ip

# Example:
ssh root@123.45.67.89
```

### Step 2: Download SHM Panel

**Option A: Using Git (Recommended)**
```bash
cd /root
git clone https://github.com/yourusername/shm-panel.git
cd shm-panel
```

**Option B: Upload Files**
```bash
# From your local machine
scp -r shm-panel root@your-server-ip:/root/

# Then on server
cd /root/shm-panel
```

### Step 3: Run System Setup Script

This installs all dependencies (Nginx, PHP, MariaDB, etc.)

```bash
# Make script executable
chmod +x setup_vivzon.sh

# Run the installer (takes 10-15 minutes)
./setup_vivzon.sh
```

**What this does:**
- ✅ Installs Nginx, PHP 8.2, MariaDB, Redis
- ✅ Configures mail server (Postfix, Dovecot)
- ✅ Sets up DNS server (Bind9)
- ✅ Configures firewall (UFW)
- ✅ Installs Fail2ban
- ✅ Generates SSL certificates
- ✅ Creates directory structure

**Wait for completion** - You'll see a success message.

### Step 4: Copy Panel Files

```bash
# Create panel directory
mkdir -p /var/www/panel

# Copy all files
cp -r /root/shm-panel/* /var/www/panel/

# Set permissions
chown -R www-data:www-data /var/www/panel
chmod -R 755 /var/www/panel
```

### Step 5: Configure Nginx

```bash
# Create Nginx configuration
nano /etc/nginx/sites-available/shm-panel
```

**Paste this configuration:**
```nginx
server {
    listen 80;
    server_name your-domain.com www.your-domain.com;
    root /var/www/panel;
    index index.php index.html;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

**Enable the site:**
```bash
# Create symlink
ln -s /etc/nginx/sites-available/shm-panel /etc/nginx/sites-enabled/

# Test configuration
nginx -t

# Reload Nginx
systemctl reload nginx
```

### Step 6: Run Web Installer

Visit your domain in a browser:
```
http://your-domain.com/install.php
```

**Fill in the form:**
- **Database Host**: `localhost`
- **Database Name**: `shm_panel`
- **Database User**: `root`
- **Database Password**: (check `/root/.my.cnf` for password)
- **Admin Username**: `admin`
- **Admin Password**: (choose a strong password)

Click **"Install SHM Panel with Security Features"**

✅ You should see: "Installation Complete! Security features enabled."

### Step 7: Deploy Security Files

```bash
# Navigate to panel directory
cd /var/www/panel

# Make deployment script executable
chmod +x deploy_security.sh

# Run deployment
./deploy_security.sh
```

**This will:**
- ✅ Create backup
- ✅ Upload security files
- ✅ Set permissions
- ✅ Verify installation

### Step 8: Secure the Installation

```bash
# Remove or rename installer
mv /var/www/panel/install.php /var/www/panel/install.php.bak

# Or delete it
rm /var/www/panel/install.php

# Create log files
touch /var/log/shm-security.log /var/log/shm-panel-errors.log
chown www-data:www-data /var/log/shm-*.log
chmod 644 /var/log/shm-*.log
```

### Step 9: Enable SSL (HTTPS)

```bash
# Install Certbot (if not already installed)
apt-get install -y certbot python3-certbot-nginx

# Get SSL certificate
certbot --nginx -d your-domain.com -d www.your-domain.com

# Follow prompts:
# - Enter email
# - Agree to terms
# - Choose to redirect HTTP to HTTPS (recommended)
```

**Test auto-renewal:**
```bash
certbot renew --dry-run
```

### Step 10: Test Security Implementation

Visit the test suite:
```
https://your-domain.com/tests/security_test.php
```

**Expected result:** All 9 tests should pass ✅

**After testing, remove test files:**
```bash
rm -rf /var/www/panel/tests
```

### Step 11: Access Your Panel

**Admin Panel:**
```
https://your-domain.com/whm/
```

**Client Panel:**
```
https://your-domain.com/cpanel/
```

**Login with:**
- Username: `admin` (or what you set)
- Password: (what you set during installation)

---

## 🔒 Post-Deployment Security

### Update PHP Files with Security Features

Follow the implementation guide to update your PHP files:

```bash
# Read the quick start guide
cat /var/www/panel/QUICK_START.md

# Or detailed guide
cat /var/www/panel/SECURITY_IMPLEMENTATION.md
```

**Priority order:**
1. Update `cpanel/login.php` (use `examples/secure_login_example.php`)
2. Update `whm/login.php` (same pattern)
3. Update `cpanel/domains.php` (use `examples/secure_domains_example.php`)
4. Update remaining files

### Set Up Monitoring

```bash
# Monitor security logs
tail -f /var/log/shm-security.log

# Monitor error logs
tail -f /var/log/shm-panel-errors.log

# Monitor PHP errors
tail -f /var/log/php8.2-fpm.log
```

### Configure Firewall

```bash
# Allow HTTP/HTTPS
ufw allow 80/tcp
ufw allow 443/tcp

# Allow SSH (if not already)
ufw allow 22/tcp

# Enable firewall
ufw enable
```

### Set Up Backups

```bash
# Create backup script
nano /root/backup-shm.sh
```

**Paste:**
```bash
#!/bin/bash
DATE=$(date +%Y%m%d-%H%M%S)
tar -czf /root/backups/shm-panel-$DATE.tar.gz /var/www/panel
mysqldump shm_panel > /root/backups/shm-panel-db-$DATE.sql
find /root/backups -name "shm-panel-*" -mtime +7 -delete
```

**Make executable and schedule:**
```bash
chmod +x /root/backup-shm.sh
mkdir -p /root/backups

# Add to crontab (daily at 2 AM)
crontab -e
# Add line:
0 2 * * * /root/backup-shm.sh
```

---

## 🧪 Verification Checklist

After deployment, verify everything works:

- [ ] Can access admin panel at `/whm/`
- [ ] Can access client panel at `/cpanel/`
- [ ] Login works with admin credentials
- [ ] SSL certificate is active (HTTPS)
- [ ] Security test suite passes (all 9 tests)
- [ ] No errors in `/var/log/shm-panel-errors.log`
- [ ] Security events logged in `/var/log/shm-security.log`
- [ ] Firewall is active (`ufw status`)
- [ ] Fail2ban is running (`systemctl status fail2ban`)
- [ ] Backups are scheduled (`crontab -l`)

---

## 🔧 Troubleshooting

### Issue: "Cannot connect to database"

**Solution:**
```bash
# Check MariaDB is running
systemctl status mariadb

# Get root password
cat /root/.my.cnf

# Test connection
mysql -u root -p
```

### Issue: "Permission denied"

**Solution:**
```bash
# Fix permissions
chown -R www-data:www-data /var/www/panel
chmod -R 755 /var/www/panel
chmod 644 /var/www/panel/shared/*.php
```

### Issue: "502 Bad Gateway"

**Solution:**
```bash
# Check PHP-FPM
systemctl status php8.2-fpm

# Restart if needed
systemctl restart php8.2-fpm nginx
```

### Issue: "SSL certificate error"

**Solution:**
```bash
# Renew certificate
certbot renew --force-renewal

# Restart Nginx
systemctl restart nginx
```

### Issue: "Security tests failing"

**Solution:**
```bash
# Check if security files exist
ls -la /var/www/panel/shared/Database.php
ls -la /var/www/panel/shared/security.php
ls -la /var/www/panel/shared/session.php

# Re-run deployment if missing
cd /var/www/panel
./deploy_security.sh
```

---

## 📊 Performance Optimization

### Enable OPcache

```bash
# Edit PHP configuration
nano /etc/php/8.2/fpm/php.ini

# Find and set:
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2

# Restart PHP-FPM
systemctl restart php8.2-fpm
```

### Configure Redis Caching

```bash
# Redis is already installed by setup script
# Check status
systemctl status redis-server

# Configure in your PHP files (see documentation)
```

---

## 🆘 Getting Help

### Check Logs
```bash
# Security logs
tail -100 /var/log/shm-security.log

# Error logs
tail -100 /var/log/shm-panel-errors.log

# Nginx logs
tail -100 /var/log/nginx/error.log

# PHP logs
tail -100 /var/log/php8.2-fpm.log
```

### System Status
```bash
# Check all services
systemctl status nginx php8.2-fpm mariadb redis-server fail2ban

# Check disk space
df -h

# Check memory
free -h
```

### Documentation
- **Quick Start**: `/var/www/panel/QUICK_START.md`
- **Security Guide**: `/var/www/panel/SECURITY_IMPLEMENTATION.md`
- **Installer Verification**: `/var/www/panel/INSTALLER_VERIFICATION.md`

---

## 🎉 Success!

Your SHM Panel is now deployed with:
- ✅ Full system setup (Nginx, PHP, MariaDB, Mail, DNS)
- ✅ SSL/HTTPS enabled
- ✅ Security features active
- ✅ Firewall configured
- ✅ Fail2ban protection
- ✅ Automated backups
- ✅ Monitoring in place

**Next Steps:**
1. Update remaining PHP files with security features
2. Create your first client account
3. Set up your first domain
4. Configure email accounts
5. Review security logs regularly

**Enjoy your secure hosting control panel!** 🚀
