# 🚀 SHM Panel

**The Ultimate Web Hosting Control Panel for Modern Servers.**

SHM Panel is a lightweight, powerful, and secure web hosting control panel designed for Ubuntu and Debian servers. It offers a complete suite of tools to manage websites, databases, emails, and more through an intuitive web interface.

## ✨ Key Features

*   **Multi-Role Architecture**: Separate **Admin Panel (WHM)** for server management and **Client Panel (cPanel)** for users.
*   **Web Stack Management**: Automated setup of **Nginx**, **PHP 8.2**, and **MariaDB**.
*   **Email & DNS**: Full-featured **Postifx/Dovecot** mail server with **Roundcube** and **Bind9** DNS.
*   **Security First**: Built-in **Fail2ban (SSH Protection)**, **UFW Firewall**, and automated **Let's Encrypt SSL**.
*   **Zero-Touch Installer**: Single script (`setup_vivzon.sh`) handles everything from system updates to SSL generation.

## 📋 System Requirements

*   **OS**: Ubuntu 20.04 LTS / 22.04 LTS or Debian 11 / 12 (Fresh Install Recommended)
*   **CPU**: 1 Core (2+ Cores Recommended)
*   **RAM**: 2GB (4GB+ Recommended)
*   **Storage**: 20GB SSD
*   **Root Access**: Required

## 🛠️ Installation Guide

**Automated Single-Command Installation for Vivzon Cloud**

Follow these steps to deploy SHM Panel on your fresh Ubuntu/Debian VPS.

### 1. Upload Project
Upload the entire `shm-panel` directory to your server's root directory (`/root/`).
```bash
# From your local machine
scp -r shm-panel root@vivzon.cloud:/root/
```

### 2. Run Installer
SSH into your server and execute the setup script. This will install Nginx, PHP 8.2, MariaDB, setup the database, configure firewalls, and generate SSL certificates automatically.
```bash
ssh root@vivzon.cloud
cd /root/shm-panel
chmod +x setup_vivzon.sh
./setup_vivzon.sh
```

### 3. Access Your Panel
Once the script finishes, it will display your **Login Credentials**.
-   **Landing**: `https://vivzon.cloud`
-   **Admin Panel**: `https://admin.vivzon.cloud`
-   **Client Panel**: `https://client.vivzon.cloud`
-   **FileManager**: `https://filemanager.vivzon.cloud`
-   **Database**: `https://phpmyadmin.vivzon.cloud`
-   **Webmail**: `https://webmail.vivzon.cloud`

> **Note**: If SSL fails due to DNS propagation, run `certbot --nginx` manually.

---

## 🔧 Management Tools

SHM Panel includes a powerful command-line tool `shm-manage` for advanced tasks.

```bash
# General Usage
shm-manage COMMAND [ARGS]

# Examples
shm-manage create-account myuser example.com admin@example.com password123
shm-manage add-domain myuser new-site.com
shm-manage fix-permissions myuser
shm-manage monitor-status
```

## 📂 Directory Structure

*   `/var/www/panel/`: Core panel files (WHM, cPanel).
*   `/var/www/clients/`: User data and website files.
*   `/etc/shm/`: Configuration files.
*   `/var/log/shm/`: Panel-specific logs.
*   `/usr/local/bin/shm-manage`: Backend engine executable.
*   `installer/`: Modular installation scripts.

## ❓ Troubleshooting

If the installation fails, the installer is designed to be **idempotent** and **self-healing**.

1.  **Check Logs**: Detailed logs are saved to `/var/log/shm-install.log`.
2.  **Retry**: Simply run `sudo ./install.sh` again. It will detect existing configurations and attempt to fix issues (e.g., database permissions).
3.  **Database Issues**: The installer includes a "safe mode" reset for MariaDB if password conflicts are detected.

## 🔒 Security Recommendations

1.  **Change Passwords**: Immediately change the generated Admin and Database passwords after login.
2.  **Firewall**: The installer checks UFW, but ensure your cloud provider's firewall allows ports 80, 443, 22, 21, and mail ports (25, 465, 587, 110, 143, 993, 995).
3.  **Backups**: Configure remote backups in the Admin Panel settings.

## 📄 License
This project is licensed under the MIT License.
