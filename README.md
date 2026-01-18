# SHM Panel (v5.1 Production)

> **Enterprise-Grade Web Hosting Control Panel**
> A lightweight, high-performance alternative to CPanel/WHM, optimized for Ubuntu 20.04/22.04 LTS.

---

## 🚀 Key Features

*   **High Performance Stack**: Nginx, PHP-FPM (8.1/8.2/8.3), and MariaDB.
*   **Security First**:
    *   **Isolated Environments**: Each user runs in their own PHP-FPM pool (`open_basedir` restricted).
    *   **Strict Permissions**: Enforced `0775` directory structure to allow `www-data` write access while maintaining user ownership.
    *   **Automated Security**: Integrated Fail2Ban and UFW configuration.
*   **Robust Backend**: `shm-manage` engine handles all privileged system operations safely via sudo.
*   **Production-Ready DNS**:
    *   Full Bind9 integration.
    *   **Auto-Propagation**: Automatically creates A, CNAME, MX, SPF, and DMARC records for new domains.
    *   `dns-tool` for seamless zone file management.
*   **Advanced File Manager**:
    *   Modern UI with List/Grid views.
    *   Zip/Unzip, Code Editor, and Multi-Upload support.
    *   **Permission Tools**: Built-in CHMOD and "Fix Permissions" utilities.
*   **One-Click Apps**: Install WordPress, Laravel, React (Vite), and CodeIgniter.

---

## 🛠️ Installation Guide

### Prerequisites
*   **OS**: Ubuntu 20.04 LTS or 22.04 LTS (Fresh Install Recommended).
*   **Root Access**: You must be logged in as `root`.
*   **Domain**: A valid domain name (e.g., `vivzon.cloud`) pointed to your server's IP.

### Step 1: Upload & Prepare
Upload the entire project folder to your server (e.g., `/root/shm-panel`).

```bash
cd /root/shm-panel
chmod +x install.sh
```

### Step 2: Run the Installer
Execute the production installer. This will install all dependencies (Nginx, PHP, MySQL, Mail, etc.) and configure the system.

```bash
sudo ./install.sh
```

### Step 3: Follow the Wizard
1.  **Main Domain**: Enter your hosting provider domain (e.g., `panel.yourhost.com`).
2.  **Admin Email**: Used for Let's Encrypt SSL and system alerts.

> **⚠️ IMPORTANT**: At the end of the installation, the script will output your **Database Password** and **Root SQL Password**. Save these immediately!

---

## 🏗️ Architecture & Directory Structure

| Path | Description |
| :--- | :--- |
| `/usr/local/bin/shm-manage` | **The Brain**. Backend engine for all system commands. |
| `/var/www/clients/` | **User Data**. Contains all client websites, logs, and mail. |
| `/var/www/panel/` | **Frontend**. Source code for WHM and CPanel interfaces. |
| `/etc/shm/` | **Config**. System-wide configuration files. |
| `/etc/nginx/sites-available/` | **VHosts**. Nginx configuration for domains. |

### Domain Logic
*   **Path**: `/var/www/clients/$USER/domains/$DOMAIN/public_html`
*   **Permissions**: Files `0664`, Folders `0775`.
*   **Ownership**: `$USER:www-data`.

---

## 🖥️ Accessing the Panel

| Panel | URL | Default Creds |
| :--- | :--- | :--- |
| **Admin (WHM)** | `http://admin.yourdomain.com` | `admin` / `admin123` |
| **Client (CPanel)** | `http://client.yourdomain.com` | *(Created via WHM)* |
| **Webmail** | `http://webmail.yourdomain.com` | *(Created via CPanel)* |

---

## 🔧 Troubleshooting

### "Permission Denied" in File Manager?
1.  Log in to CPanel.
2.  Go to **Tools > Security**.
3.  Click **"Fix File Permissions"**.
4.  *Alternatively, right-click the folder in File Manager and use the "Permissions" tool.*

### DNS Not Propagating?
Ensure your domain registrar points `ns1.yourdomain.com` and `ns2.yourdomain.com` to your server IP. The system automatically handles the internal Bind9 zones.

### Resetting an Account
If a client account is corrupted, you can reset it via WHM or run the backend command manually:
```bash
sudo shm-manage reset-account <username>
```

---

## 📜 License
Private Distribution.
(c) 2026 SHM Panel Development Team.
