# Vivzon SHM Panel

**Vivzon SHM Panel** is a lightweight, production-ready web hosting control panel designed for Ubuntu servers. It provides a split-architecture system with a **WHM (Web Host Manager)** for administrators and a **CPanel** for end-users, powered by a robust bash backend engine.

![SHM Panel Banner](landing/assets/banner.png) > *Note: Replace with actual banner if available.*

## 🚀 Features

### 🛡️ WHM (Admin Panel)
- **Account Provisioning**: Create/Suspend/Delete shared hosting accounts.
- **Package Management**: Define hosting plans with Disk, Domain, and Email limits.
- **Service Monitoring**: Real-time status of Nginx, PHP-FPM, MariaDB, Postfix, etc.
- **System Health**: CPU, RAM, and Disk usage metrics.

### 👤 CPanel (User Portal)
- **Domain Management**: Add subdomains, manage DNS records (A, MX, CNAME), and toggle SSL.
- **PHP Selector**: Switch between PHP 8.1, 8.2, and 8.3 per domain.
- **Database Tools**: Create MariaDB databases and users instantly.
- **Email Suite**: Create email accounts with automatic Postfix/Dovecot configuration.
- **File Manager**: Built-in native file manager for uploads, editing, and zipping.
- **One-Click Apps**: Access Roundcube Webmail and phpMyAdmin.

### ⚙️ Backend Engine (`shm-manage`)
- **Zero-Latency**: Direct PHP-to-Bash execution via sudo bridge.
- **Nginx VHosts**: Automated virtual host generation and reloading.
- **Bind9 DNS**: Dynamic zone file generation from database records.
- **Security**: Isolated users via Linux permissions and PHP `open_basedir`.

## 📋 Requirements

- **OS**: Ubuntu 22.04 LTS or 24.04 LTS
- **User**: Root access required for installation
- **Architecture**: x86_64

## 🛠️ Installation

1.  **Clone or Upload** the repository to your server (e.g., `/root/shm-panel`).
2.  **Run the Installer**:
    ```bash
    cd shm-panel
    chmod +x install.sh
    ./install.sh
    ```
3.  **Wait for Completion**: The script will:
    *   Install Nginx, PHP (8.1-8.3), MariaDB, Bind9, Postfix, Dovecot, ProFTPD.
    *   Configure system firewalls (UFW).
    *   Deploy the SHM backend and frontend.
    *   Generate secure database credentials.

## 🌐 Accessing the Panel

After installation, the following subdomains are configured (assuming `vivzon.cloud` is your domain):

| Service | URL | Default Creds |
| :--- | :--- | :--- |
| **WHM Admin** | `http://admin.vivzon.cloud` | `admin` / `admin123` |
| **CPanel** | `http://client.vivzon.cloud` | (Created via WHM) |
| **Webmail** | `http://webmail.vivzon.cloud` | (Created via CPanel) |
| **File Manager** | `http://filemanager.vivzon.cloud` | (Access via CPanel) |

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
