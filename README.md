# 🚀 SHM Panel

**The Ultimate Web Hosting Control Panel for Modern Servers.**

SHM Panel is a lightweight, powerful, and secure web hosting control panel designed for Ubuntu and Debian servers. It offers a complete suite of tools to manage websites, databases, emails, and more through an intuitive web interface.

## ✨ Key Features

*   **Multi-Role Architecture**: Separate **Admin Panel (WHM)** for server management and **Client Panel (cPanel)** for users.
*   **Web Stack Management**: Automated setup of **Nginx**, **PHP** (8.1, 8.2, 8.3), and **MariaDB**.
*   **One-Click Apps**: Install WordPress, Laravel, CodeIgniter, and React apps instantly.
*   **Email Server**: Full-featured mail server with **Postfix**, **Dovecot**, and **Roundcube** webmail.
*   **Security First**: Built-in **ModSecurity**, **Fail2ban**, **UFW Firewall**, and automated **Let's Encrypt SSL**.
*   **Robust Installer**: Modular, self-healing installation system that handles errors gracefully.
*   **Developer Friendly**: SSH Key management, PHP version switcher, and resource monitoring.

## 📋 System Requirements

*   **OS**: Ubuntu 20.04 LTS / 22.04 LTS or Debian 11 / 12 (Fresh Install Recommended)
*   **CPU**: 1 Core (2+ Cores Recommended)
*   **RAM**: 2GB (4GB+ Recommended)
*   **Storage**: 20GB SSD
*   **Root Access**: Required

## 🛠️ Installation Guide

Follow these steps to install SHM Panel on your server.

### 1. Prepare your Server
Login to your server via SSH as `root`.
```bash
ssh root@your-server-ip
```

### 2. Download the Installer
Clone the repository or download the project files to your server.
```bash
apt-get update && apt-get install -y git
git clone https://github.com/your-repo/shm-panel.git
cd shm-panel
```
*(If you have the files locally, upload them to `/root/shm-panel`)*

### 3. Run the Installer
Make the script executable and run it. The interactive wizard will guide you through the configuration.
```bash
chmod +x install.sh
sudo ./install.sh
```

### 4. Configuration Wizard
You will be asked to provide:
1.  **Main Domain**: The primary domain for the panel (e.g., `hosting.example.com`).
2.  **Admin Email**: Email address for the super admin and SSL certificates.
3.  **Server IP**: Auto-detected, but confirm it is correct.

The installer will automatically:
*   Install all dependencies (Nginx, PHP, MySQL, Redis, etc.).
*   Configure the database and backend services.
*   Set up the directory structure and permissions.
*   Generate secure credentials.

### 5. Post-Installation
Once the installation is complete, you will see a success message with your credentials. **Save these credentials immediately!**

You can access your panels here:
*   **Admin Panel (WHM)**: `https://admin.your-domain.com`
*   **Client Panel**: `https://client.your-domain.com`
*   **Webmail**: `https://webmail.your-domain.com`
*   **System Monitor**: `https://monitor.your-domain.com`

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
