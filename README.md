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

## 💿 Installation

### Method 1: Automated Installer (Recommended)

1.  **Prepare the Server**
    Login as `root`.
    ```bash
    sudo -i
    ```

2.  **Download Source**
    Upload the project files to `/root/shm-panel` or clone your repository.
    ```bash
    git clone https://github.com/vivzon/shm-panel.git /root/shm-panel
    cd /root/shm-panel
    ```

3.  **Run Installer**
    ```bash
    chmod +x install.sh
    ./install.sh
    ```
    *Follow the on-screen wizard to set your Primary Domain and Admin Email.*

### Method 2: Web Installer (Alternative/Recovery)
If you need to re-initialize the database or are running in a constrained local environment (e.g., using XAMPP/WAMP for frontend dev), use `install.php`.
1.  Navigate to `/install.php` in your browser.
2.  Enter Database Credentials.
3.  Click **Install**.

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
