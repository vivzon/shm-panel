# SHM Panel - Deployment Guide

Professional Web Hosting Control Panel for Ubuntu/Debian.

## 🚀 Prerequisites

- **OS**: Ubuntu 20.04+ or Debian 11+ (Fresh install recommended)
- **User**: Root or sudo access
- **Hard Drive**: Minimum 20GB (depending on hosting needs)
- **RAM**: Minimum 2GB (4GB recommended)
- **Domain**: A registered domain name for the panel (e.g., `panel.yourdomain.com`)

## 🛠️ Installation Steps

### 1. Copy Files to Server
Upload the entire project directory to your server (e.g., to `/root/shm-panel/`).

### 2. Set Permissions
Ensure the installer script is executable:
```bash
chmod +x install.sh
```

### 3. Run the Installer
Execute the production installer as root:
```bash
sudo ./install.sh
```

**During installation, you will be asked for:**
- Your Main Domain (the panel's primary domain)
- Admin Email

The installer will automatically:
- Install Nginx, Apache, PHP (8.1, 8.2, 8.3), MariaDB, and Redis.
- Configure BIND (DNS) and Postfix/Dovecot (Mail).
- Set up the privileged backend engine (`shm-manage`).
- Secure the system with UFW and Fail2ban.

### 4. Post-Installation
Once the script finishes, it will display your:
- **Admin Panel URL**
- **Admin Username/Password**
- **Database Credentials**

---

## 🔧 Management Commands

The system uses `shm-manage` for all major operations.

| Command | Description |
| :--- | :--- |
| `sudo shm-manage vhost-tool sync-all` | Rebuilds all VirtualHost configs (Apache/Nginx) |
| `sudo shm-manage fix-permissions <user>` | Fixes ownership/perms for a client account |
| `sudo shm-manage delete-account <user>` | Entirely removes a client account |
| `sudo shm-manage dns-tool sync <domain_id>` | Manually refreshes DNS zone for a domain |

---

## 🔒 Security Notes
- The panel does not run as root. It uses a **Sudo Bridge** via `shm-manage`.
- Every client is isolated into their own system user for maximum security.
- Standard ports (80, 443, 21, 22, 25, 53, 143, 587) are automatically opened by the installer.

---

## 📁 Directory Structure
- `/var/www/panel/` - Panel frontend files (WHM/CPanel)
- `/var/www/clients/` - Hosted client data (Web/Logs/Backups)
- `/etc/shm/` - System configuration files
- `/usr/local/bin/shm-manage` - Backend engine executable
