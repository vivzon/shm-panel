# SHM Panel (v5.1 Production)

> **Enterprise-Grade Web Hosting Control Panel**
> A lightweight, high-performance alternative to CPanel/WHM, optimized for Ubuntu 20.04/22.04 LTS.

---

## 🚀 Key Features

*   **High Performance Stack**: Nginx, PHP-FPM (8.1/8.2/8.3), and MariaDB.
*   **Production Limits**: Optimized for large file uploads (**1GB Limit**) and high memory limits out of the box.
*   **Security First**:
    *   **Isolated Environments**: Each user runs in their own PHP-FPM pool (`open_basedir` restricted).
    *   **Strict Security**: Automated UFW Firewall, Fail2Ban protection, and Database hardening.
    *   **Auto-Backups**: Daily automated backups for all clients with 7-day retention.
*   **Robust Backend**: `shm-manage` engine handles all privileged system operations safely via sudo.
*   **Production-Ready DNS**:
    *   **Full Bind9 Automation**: Automatically creates zones and handles Glue Records (`ns1`/`ns2`) for your main domain.
    *   **Auto-Propagation**: Instantly generates A, CNAME, MX, SPF, and DMARC records for new accounts.
*   **Advanced File Manager**:
    *   Modern UI with List/Grid views.
    *   **2GB Upload Support**: Handle large backups and media files with ease.
    *   **Permission Tools**: Built-in CHMOD and "Fix Permissions" utilities.
*   **One-Click Apps**: Install WordPress, Laravel, React (Vite), and CodeIgniter.

---

## 🛠️ Installation Guide

### Prerequisites
*   **OS**: Ubuntu 20.04 LTS or 22.04 LTS (Fresh Install Recommended).
*   **Root Access**: You must be logged in as `root`.
*   **Domain**: A valid domain name (e.g., `vivzon.cloud`) pointed to your server's IP.
*   **System**: At least 1GB RAM (2GB Swap is automatically created if missing).

### Step 1: Upload & Prepare
Upload the entire project folder to your server (e.g., `/root/shm-panel`).

```bash
cd /root/shm-panel
chmod +x install.sh
```

### Step 2: Run the Production Installer
Execute the installer. This will install Nginx, PHP, MariaDB, Mail Server, etc., and apply all security hardening.

```bash
sudo ./install.sh
```

> **Note**: The installer will:
> *   Configure the UFW Firewall (allowing SSH, Web, Mail, DNS, FTP).
> *   Create a 2GB Swap file for stability.
> *   Secure the Database installation.

### Step 3: Follow the Wizard
1.  **Main Domain**: Enter your hosting provider domain (e.g., `vivzon.cloud`).
2.  **Admin Email**: Used for Let's Encrypt SSL and system alerts.

> **⚠️ IMPORTANT**: At the end of the installation, copy the **Database Password** and **Root SQL Password**. You will need them!

### Step 4: Post-Installation (Initialize DNS)
Once installed, perform these steps to ensure your Nameservers work:

1.  **Log in to WHM**: `http://admin.yourdomain.com` (User: `admin` / Pass: `admin123`)
2.  **Create Your Main Account**:
    *   Create a Client Account for your main domain (e.g., `vivzon.cloud`).
    *   **Why?** This triggers the automated DNS logic to create `ns1.vivzon.cloud` and `ns2.vivzon.cloud` A-records pointing to your server.
3.  **Setup Webmail**:
    *   Log in to CPanel (`http://client.yourdomain.com`) as the user you just created.
    *   Go to **Emails** and create an email account (e.g., `admin@vivzon.cloud`).
    *   Use this email/password to log in to Webmail (`http://webmail.yourdomain.com`).

---

## 🏗️ Architecture & Directory Structure

| Path | Description |
| :--- | :--- |
| `/usr/local/bin/shm-manage` | **The Brain**. Backend engine for all system commands. |
| `/var/www/clients/` | **User Data**. Contains all client websites, logs, and mail. |
| `/var/www/panel/` | **Frontend**. Source code for WHM and CPanel interfaces. |
| `/etc/shm/` | **Config**. System-wide configuration files. |
| `/etc/cron.daily/shm-backup`| **Backups**. Daily automated backup script. |

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

### DNS Not Propagating?
1.  Ensure you created a **Client Account** for your main domain inside WHM.
2.  Check that your domain registrar has "Glue Records" (Nameservers) pointing `ns1` and `ns2` to your server IP.
3.  Run `dig ns yourdomain.com` to verify.

### 413 Request Entity Too Large?
*   The system allows up to **1GB** file uploads. If you face issues, ensure you are running the latest version of `shm-manage` (re-run `install.sh`).

---

## 📜 License
Private Distribution.
(c) 2026 SHM Panel Development Team.
