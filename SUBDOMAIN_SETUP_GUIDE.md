# SHM Panel - Subdomain Hosting Setup Guide

## 🌐 Subdomain Configuration

This guide will help you set up subdomain-based hosting for SHM Panel services.

### Subdomains Structure

| Service | Subdomain | Purpose |
|---------|-----------|---------|
| WHM Panel | `whm.vivzon.cloud` | Server administration |
| cPanel | `cpanel.vivzon.cloud` | Client control panel |
| phpMyAdmin | `phpmyadmin.vivzon.cloud` | Database management |
| Webmail | `webmail.vivzon.cloud` | Email access (Roundcube) |

---

## 📋 Prerequisites

1. ✅ Server with SHM Panel installed
2. ✅ Domain `vivzon.cloud` pointing to your server
3. ✅ DNS access to create subdomains
4. ✅ Root/sudo access to server

---

## 🚀 Quick Setup

### Step 1: Configure DNS

Add these **A records** to your DNS provider:

```
whm.vivzon.cloud        → YOUR_SERVER_IP
cpanel.vivzon.cloud     → YOUR_SERVER_IP
phpmyadmin.vivzon.cloud → YOUR_SERVER_IP
webmail.vivzon.cloud    → YOUR_SERVER_IP
```

**Wait 5-10 minutes** for DNS propagation.

### Step 2: Upload Files to Server

```bash
# From your local machine
scp -r nginx/ root@your-server-ip:/var/www/panel/
scp setup_subdomains.sh root@your-server-ip:/var/www/panel/
```

### Step 3: Run Setup Script

```bash
# On your server
cd /var/www/panel
chmod +x setup_subdomains.sh
./setup_subdomains.sh
```

The script will:
- ✅ Install phpMyAdmin
- ✅ Install Roundcube (Webmail)
- ✅ Configure Nginx for all subdomains
- ✅ Set up SSL certificates
- ✅ Restart services

---

## 🔧 Manual Setup (Alternative)

If you prefer manual setup:

### 1. Install phpMyAdmin

```bash
apt-get update
apt-get install -y phpmyadmin
```

### 2. Install Roundcube

```bash
apt-get install -y roundcube roundcube-core roundcube-mysql
```

### 3. Copy Nginx Configs

```bash
cp nginx/*.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/whm.vivzon.cloud.conf /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/cpanel.vivzon.cloud.conf /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/phpmyadmin.vivzon.cloud.conf /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/webmail.vivzon.cloud.conf /etc/nginx/sites-enabled/
```

### 4. Test and Restart Nginx

```bash
nginx -t
systemctl restart nginx
```

### 5. Setup SSL

```bash
certbot --nginx -d whm.vivzon.cloud -d cpanel.vivzon.cloud -d phpmyadmin.vivzon.cloud -d webmail.vivzon.cloud
```

---

## ✅ Verification

### Test Each Subdomain

```bash
# Test HTTP access
curl -I http://whm.vivzon.cloud
curl -I http://cpanel.vivzon.cloud
curl -I http://phpmyadmin.vivzon.cloud
curl -I http://webmail.vivzon.cloud

# Test HTTPS access (after SSL setup)
curl -I https://whm.vivzon.cloud
curl -I https://cpanel.vivzon.cloud
curl -I https://phpmyadmin.vivzon.cloud
curl -I https://webmail.vivzon.cloud
```

### Check Nginx Status

```bash
systemctl status nginx
nginx -t
```

### Check SSL Certificates

```bash
certbot certificates
```

---

## 🔒 Security Recommendations

### 1. Firewall Rules

```bash
# Allow HTTP and HTTPS
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

### 2. Restrict phpMyAdmin Access (Optional)

Edit `/etc/nginx/sites-available/phpmyadmin.vivzon.cloud.conf`:

```nginx
# Add IP whitelist
location / {
    allow YOUR_IP_ADDRESS;
    deny all;
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 3. Enable Fail2ban

```bash
systemctl enable fail2ban
systemctl start fail2ban
```

---

## 🐛 Troubleshooting

### DNS Not Resolving

```bash
# Check DNS propagation
dig whm.vivzon.cloud
nslookup whm.vivzon.cloud

# Wait 5-10 minutes and try again
```

### SSL Certificate Fails

```bash
# Ensure DNS is propagated first
# Then retry:
certbot --nginx -d whm.vivzon.cloud -d cpanel.vivzon.cloud -d phpmyadmin.vivzon.cloud -d webmail.vivzon.cloud
```

### Nginx Configuration Error

```bash
# Test configuration
nginx -t

# Check error logs
tail -f /var/log/nginx/error.log
```

### phpMyAdmin 404 Error

```bash
# Verify installation
ls -la /usr/share/phpmyadmin

# If missing, reinstall:
apt-get install --reinstall phpmyadmin
```

### Webmail Not Loading

```bash
# Verify Roundcube installation
ls -la /usr/share/roundcube

# Check logs
tail -f /var/log/nginx/webmail-error.log
```

---

## 📊 Service Locations

| Service | Document Root | Config Location |
|---------|--------------|-----------------|
| WHM | `/var/www/panel/whm` | `/etc/nginx/sites-available/whm.vivzon.cloud.conf` |
| cPanel | `/var/www/panel/cpanel` | `/etc/nginx/sites-available/cpanel.vivzon.cloud.conf` |
| phpMyAdmin | `/usr/share/phpmyadmin` | `/etc/nginx/sites-available/phpmyadmin.vivzon.cloud.conf` |
| Webmail | `/usr/share/roundcube` | `/etc/nginx/sites-available/webmail.vivzon.cloud.conf` |

---

## 🔄 Updating Configuration

After making changes to Nginx configs:

```bash
# Test configuration
nginx -t

# Reload Nginx (no downtime)
systemctl reload nginx

# Or restart Nginx
systemctl restart nginx
```

---

## 📝 Next Steps

1. ✅ Configure DNS records
2. ✅ Run setup script
3. ✅ Verify all subdomains are accessible
4. ✅ Set up SSL certificates
5. ✅ Configure phpMyAdmin security
6. ✅ Test webmail functionality
7. ✅ Update firewall rules

---

## 🎯 Access Your Services

After setup completion:

- **WHM Panel**: https://whm.vivzon.cloud
- **cPanel**: https://cpanel.vivzon.cloud  
- **phpMyAdmin**: https://phpmyadmin.vivzon.cloud
- **Webmail**: https://webmail.vivzon.cloud

**Default Credentials:**
- WHM/cPanel: Use credentials from installation
- phpMyAdmin: Use MySQL root credentials
- Webmail: Use email account credentials

---

## 📞 Support

If you encounter issues:

1. Check logs: `/var/log/nginx/`
2. Verify DNS propagation
3. Ensure firewall allows ports 80/443
4. Check service status: `systemctl status nginx`
