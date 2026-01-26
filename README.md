# SHM Panel (Server Hosting Management)

> **The Next-Gen Web Hosting Control Panel**
> A premium, high-performance alternative to cPanel/WHM, built for speed, security, and aesthetics.

![SHM Panel Banner](landing/assets/banner_placeholder.png)

## 📖 Overview

**SHM Panel** is a full-featured hosting platform designed for Ubuntu/Debian servers. It separates concerns between a powerful backend engine (`shm-manage`) and a beautiful, Glassmorphism-based frontend.

-   **WHM (Web Host Manager)**: Admin interface for server management, account provisioning, and package creation.
-   **cPanel (Client Portal)**: End-user interface for managing websites, domains, emails, and databases.
-   **Backend Engine**: A robust Bash/Systemd-driven core that handles isolation, Nginx VHosts, PHP pools, and DNS zones.

---

## 🚀 Key Features

### 🖥️ Stunning UI/UX
-   **Glassmorphism Design**: Modern, clean, and responsive interface using TailwindCSS.
-   **Dynamic Branding**: White-label support that adapts branding strings based on the domain name (e.g., `vivzon.cloud`, `shm-panel.com`).

### ⚡ Infrastructure & Performance
-   **Tech Stack**: Nginx, PHP-FPM (8.1, 8.2, 8.3 Switchable), MariaDB/MySQL.
-   **Isolated Environments**: Every user gets a dedicated system user (Linux account) and PHP-FPM pool for maximum security and resource isolation.
-   **Traffic Monitoring**: Real-time tracking of hits and bandwidth usage.

### 🌐 Advanced DNS & Domains
-   **DNS Automation**: Automatically manages Bind9 zones (`named.conf`), creating A, MX, SPF, DMARC, and Glue Records.
-   **Subdomain Management**: specialized handling for subdomains, correctly mapping them to parent zones.
-   **SSL**: Automated Let's Encrypt certificates via Certbot.

### 🛠️ Functionality
-   **One-Click Apps**: Install **WordPress**, **Laravel**, **React (Vite)**, and **CodeIgniter** in seconds.
-   **File Manager**: Full-featured web-based file manager (Upload, Zip/Unzip, Edit, Permissions).
-   **Security Suite**:
    -   **Malware Scanner**: Integrated ClamAV scanning.
    -   **SSH Key Manager**: Manage public keys for secure SFTP/SSH access.
    -   **Permission Fixer**: Auto-repair file ownership issues.

---

## 💻 System Requirements

*   **OS**: Ubuntu 20.04 / 22.04 LTS or Debian 11/12 (Fresh Install).
*   **CPU**: 2+ Cores recommended.
*   **RAM**: 2GB Minimum (4GB Recommended).
*   **Storage**: 20GB+ SSD/NVMe.
*   **Root Access**: Required.

---

## 💿 Deployment Guide (Step-by-Step)

Follow this guide to deploy SHM Panel on a production server.

### 1. Prerequisites
- **Server**: A fresh VPS or Dedicated Server (Ubuntu 20.04/22.04 or Debian 11/12).
- **Public IP**: A static IPv4 address (e.g., `192.0.2.1`).
- **Domain**: A registered domain name (e.g., `example.com`) pointed to your server's IP.

### 2. DNS Configuration (Before You Start)
Set up the following A records at your domain registrar (Cloudflare/Namecheap/GoDaddy):
```text
@           IN A   <YOUR_SERVER_IP>
www         IN A   <YOUR_SERVER_IP>
admin       IN A   <YOUR_SERVER_IP>
client      IN A   <YOUR_SERVER_IP>
webmail     IN A   <YOUR_SERVER_IP>
ns1         IN A   <YOUR_SERVER_IP>
ns2         IN A   <YOUR_SERVER_IP>
```

### 3. Server Installation
Login to your server via SSH as `root`.

#### Step A: Download the Installer
Clone the repository to your root directory.
```bash
cd /root
git clone https://github.com/vivzon/shm-panel.git
cd shm-panel
```

#### Step B: Run the Deployment Script
Make the script executable and run it. The script will handle all dependencies, database setup, and web server configuration.
```bash
chmod +x install.sh
./install.sh
```

#### Step C: Configuration Wizard
The script will ask for:
1.  **Main Domain**: Enter your primary domain (e.g., `example.com`).
2.  **Admin Email**: Used for Let's Encrypt SSL and alerts.

*The installation takes some minutes. Do not close the terminal.*

### 4. Post-Installation Verification

Once the "SHM PANEL INSTALLED SUCCESSFULLY" message appears:

1.  **Access Admin Panel**: Go to `http://admin.example.com`
    -   Login with default credentials (see below).
2.  **Verify Nginx**:
    -   Run `systemctl status nginx` to ensure it's active.
    -   If sites don't load, check the default catch-all: `ls -l /etc/nginx/sites-enabled/000-default`.
3.  **Secure your Install**:
    -   Change the admin password immediately inside the WHM.
    -   SSH into your server and delete the installer logs if sensitive data is visible.

### ❓ Troubleshooting

**Issue: All domains load the Admin Login page.**
*Fix*: This means the default server block is missing. Run:
```bash
ln -s /etc/nginx/sites-available/000-default /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

**Issue: Nginx fails to reload.**
*Fix*: Check if a client deleted their log directory.
```bash
# Force restore log directories
shm-manage fix-permissions <username>
```

**Issue: PHP Upload Limit too low.**
*Fix*: Adjust limits in the Admin Panel -> PHP Settings, or manually edit:
`/etc/php/8.2/fpm/pool.d/<user>.conf`

---

## 🔐 Default Credentials

After installation, the following access points are available:

| Portal | URL | Default User | Default Pass |
| :--- | :--- | :--- | :--- |
| **Admin Panel (WHM)** | `http://admin.yourdomain.com` | `admin` | `admin123` |
| **Client Panel** | `http://client.yourdomain.com` | *(Create via Admin)* | *(Set via Admin)* |
| **Webmail** | `http://webmail.yourdomain.com` | `user@domain.com` | *(User Password)* |
| **Landing Page** | `http://yourdomain.com` | - | - |

> **⚠️ IMPORTANT**: Change the default admin password immediately after first login!

---

## 📂 Project Structure

```
shm-panel/
├── install.sh          # Main System Installer
├── shm-manage          # Core Backend CLI Tool
├── whm/                # Admin Panel Source
├── cpanel/             # Client Panel Source
├── landing/            # Main Website Source
├── shared/             # Shared Config & Libraries
└── install.php         # Web-based DB Initializer
```

## 📜 License
(c) 2026 SHM Panel / Vivzon Cloud. All Rights Reserved.
