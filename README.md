# 🚀 SHM Panel - Complete Installation Guide

**Enterprise-Grade Web Hosting Control Panel with Subdomain Architecture**

SHM Panel is a production-ready hosting control panel featuring separate subdomains for WHM, cPanel, phpMyAdmin, and Webmail. This guide provides a complete start-to-end installation process using the powerful `shm-manage` backend engine.

---

## 📋 **Prerequisites**

Before starting, ensure you have:

- ✅ **Fresh VPS** running Ubuntu 20.04/22.04 or Debian 11/12
- ✅ **Root access** to the server
- ✅ **Domain name** (e.g., `vivzon.cloud`) pointed to your server IP
- ✅ **Minimum Resources**: 2GB RAM, 20GB SSD, 1 CPU core

---

## 🎯 **Installation Overview**

1.  **Server Setup**: Install system packages (Nginx, PHP, MySQL, etc.).
2.  **Panel Setup**: Deploy code and configure the database.
3.  **Service Configuration**: Use `shm-manage` to set up Global Apps (phpMyAdmin, Webmail) and VHosts.
4.  **Final Polish**: Secure the installation.

**Total Time**: ~20 minutes

---

## 📖 **Step-by-Step Installation**

### **Phase 1: Server Setup**

Connect to your server via SSH:
```bash
ssh root@YOUR_SERVER_IP
```

#### Step 1: Install Dependencies
Run the system setup script to install all required packages (Nginx, PHP 8.2, MariaDB, Redis, Mail Services).

```bash
# Clone the repository
cd /root
git clone https://github.com/yourusername/shm-panel.git
cd shm-panel

# Run the package installer
chmod +x setup_vivzon.sh
./setup_vivzon.sh
```

### **Phase 2: Panel Deployment**

#### Step 2: Deploy Codebase
Move the panel files to the web root and fix permissions.

```bash
mkdir -p /var/www/panel
cp -r /root/shm-panel/* /var/www/panel/
chown -R www-data:www-data /var/www/panel
chmod -R 755 /var/www/panel
```

#### Step 3: Install Backend Engine (`shm-manage`)
This is the core tool that manages your server.

```bash
# Install shm-manage globally
cp /var/www/panel/shm-manage /usr/local/bin/shm-manage
chmod +x /usr/local/bin/shm-manage

# Configure Sudo for Web Panel (Required for GUI to work)
echo "www-data ALL=(ALL) NOPASSWD: /usr/local/bin/shm-manage" > /etc/sudoers.d/shm-panel
chmod 0440 /etc/sudoers.d/shm-panel
```

#### Step 4: Run Web Installer
1.  Create a temporary Nginx config to access the installer (or use IP directly if default).
2.  Visit `http://YOUR_SERVER_IP/install.php`.
3.  Enter Database Credentials (user `root`, password from `/root/.my.cnf`) and Create Admin Account.
4.  **Important**: Run the database schema fix after installation to ensure compliance.
    ```bash
    cd /var/www/panel
    mysql -u root -p shm_panel < migrations/003_fix_all_schema_issues.sql
    ```

---

### **Phase 3: Service Configuration (The Magic Step)**

Now we use `shm-manage` to automatically configure everything.

#### Step 5: Install Global System Apps
This command automatically downlods and installs **phpMyAdmin** and **Roundcube Webmail**, and configures Nginx to make them accessible from *any* domain on your server.

```bash
shm-manage setup-system-apps
```

#### Step 6: Generate VHosts & Fix Structure
This command detects all domains in the database, corrects their directory structure, generates secure Nginx VHosts (with SSL support if enabled), and applies permissions.

```bash
shm-manage troubleshoot fix-structure
```

#### Step 7: Restart Services
```bash
systemctl restart nginx
systemctl restart php8.2-fpm
```

---

## ✅ **Verification**

Your panel is now live!

### Access Credentials
-   **Admin Panel (WHM)**: `http://whm.yourdomain.com` (or via IP)
-   **Client Panel**: `http://cpanel.yourdomain.com`
-   **phpMyAdmin**: `http://any-domain.com/phpmyadmin`
-   **Webmail**: `http://any-domain.com/webmail`

### key Features Verified
1.  **Auto-SSL**: If you enabled SSL for a domain, `shm-manage` successfully ran Certbot.
2.  **Safe Configs**: User configs are loaded securely.
3.  **Self-Healing**: If you delete a config file, running `shm-manage troubleshoot fix-structure` brings it back.

---

## 🛠 **Management Guide (`shm-manage`)**

You can manage the server entirely from the command line using `shm-manage`.

| Action | Command |
| :--- | :--- |
| **Create Account** | `shm-manage create-account USER DOMAIN EMAIL PASS` |
| **Add Domain** | `shm-manage add-domain USER DOMAIN` |
| **Fix Permissions** | `shm-manage troubleshoot fix-perms DOMAIN_ID` |
| **Regenerate Configs** | `shm-manage troubleshoot fix-structure` |
| **Check Logs** | `shm-manage get-client-logs USER 50` |
| **System Info** | `shm-manage system-info` |

---

## 🔒 **Security Recommendations**

1.  **Firewall (UFW)**: strictly allow ports 80, 443, 22, and Mail ports (25, 587, 993, 995).
    ```bash
    ufw allow 80/tcp
    ufw allow 443/tcp
    ufw allow 22/tcp
    ufw enable
    ```
2.  **Rate Limiting (New)**: The panel now utilizes **Redis** for IP-based rate limiting on all authentication routes, neutralizing session-dropping brute-force attacks automatically. Maintain your Redis server configuration to keep this active.
3.  **SSH Hardening**: Disable root login via password in `/etc/ssh/sshd_config`.
4.  **Backups**: Use `shm-manage backup create USER` to generate backups.

---

**Enjoy your Enterprise SHM Panel!** 🚀
