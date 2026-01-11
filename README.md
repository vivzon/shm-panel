# Vivzon SHM Panel

**Vivzon SHM Panel** is a lightweight, production-ready web hosting control panel designed for Ubuntu servers. It provides a split-architecture system with a **WHM (Web Host Manager)** for administrators and a **CPanel** for end-users, powered by a robust bash backend engine.

![SHM Panel Banner](landing/assets/banner.png) > *Note: Replace with actual banner if available.*

## 🚀 Features

### 🛡️ WHM (Admin Panel)
- **Account Management**: Create, Suspend/Unsuspend, and Delete accounts.
- **Impersonation**: "Login as Client" key to instantly access user cPanel without credentials.
- **Account Reset**: "Nuclear Option" to wipe `public_html` and databases for a fresh start.
- **Server Insights**: Dashboard card for IP, Nameservers, and Mail Hostname.
- **Package Management**: Define limits for Disk ID, Domains, and Emails.

### 👤 CPanel (User Portal)
- **One-Click App Installer**: Install **WordPress, Laravel, CodeIgniter 4, and React (Vite)** instantly.
- **Account Control**: **Delete Websites** and **Reset Database/Mail Passwords** directly from UI.
- **Contextual File Manager**: "Manage Files" button launches File Manager in the correct website root.
- **Auto-DNS**: Adding a domain automatically configures A, CNAME, MX, and SPF records.
- **Domain Management**: PHP Version Selector (8.1-8.3) and SSL Toggling.
- **Email Suite**: Create email accounts with automatic Postfix/Dovecot SQL configuration.
- **Database Tools**: Manage MariaDB databases and users.

### ⚙️ Backend Engine (`shm-manage`)
- **Zero-Latency**: Direct PHP-to-Bash execution via sudo bridge.
- **Safe Service Reloads**: Asynchronous, delayed reloads prevent **502 Bad Gateway** errors.
- **Security**: Isolated PHP-FPM pools and Linux permissions.

## 📋 Requirements

- **OS**: Ubuntu 22.04 LTS or 24.04 LTS
- **User**: Root access required for installation
- **Architecture**: x86_64

## 🛠️ Installation

1.  **Clone or Upload** the repository to your server.
2.  **Run the Installer**:
    ```bash
    chmod +x install.sh
    ./install.sh
    ```
3.  **Completion**: The script installs Nginx, PHP, MariaDB, Bind9, Postfix, Dovecot, FTP, and Web Apps.

## ⬆️ Upgrading from v4

To upgrade safely while preserving client data:
1.  Upload `upgrade_latest.sh`.
2.  Run:
    ```bash
    chmod +x upgrade_latest.sh
    ./upgrade_latest.sh
    ```

### 🌐 Professional Landing Page
- **Dynamic Portal Hub**: A modern, glassmorphism-styled landing page on the *Main Domain*.
- **Smart Linking**: Automatically directs users to Client, Admin, Webmail, and File Manager subdomains.

## 🌐 Accessing the Panel

| Service | URL | Default Creds |
| :--- | :--- | :--- |
| **Main Portal** | `http://example.com` | (Landing Page) |
| **WHM Admin** | `http://admin.example.com` | `admin` / `admin123` |
| **CPanel** | `http://client.example.com` | (Created via WHM) |
| **Webmail** | `http://webmail.example.com` | (SSO / Email Creds) |
| **File Manager** | `http://filemanager.example.com` | (SSO / cPanel Creds) |
| **phpMyAdmin** | `http://phpmyadmin.example.com` | (DB User Creds) |

> **Important**: Check `/root/shm-credentials.txt` on your server for the generated MySQL root password and other secrets.

## 📂 Project Structure

```
shm-panel/
├── whm/               # Admin Interface (PHP)
├── cpanel/            # Client Interface (PHP)
├── landing/           # Main Landing Page
├── shm-manage         # Backend Engine (Bash)
├── install.sh         # Master Installer
└── shared_config.php  # PHP-Shell Bridge
```

## 🔒 Security

- **Database**: Uses `utf8mb4` and prepared statements (PDO) to prevent SQL injection.
- **System**: PHP runs as specific system users (pool isolation).
- **Network**: UFW firewall configured to allow only essential ports (80, 443, 22, 21, 25, etc.).

## 📜 License

This project is open-source and available under the [MIT License](LICENSE).
