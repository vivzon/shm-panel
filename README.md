# 🚀 SHM Panel - Professional Hosting Control Panel

**SHM Panel** is a lightweight, secure, and high-performance web hosting control panel designed for **Ubuntu 20.04+** and **Debian 11+**. It leverages **Nginx** for speed, **PHP-FPM** for flexibility, and a secure **Bash-based backend** for system operations.

---

## ✨ Features

### 🖥️ Client Panel (CPanel)
-   **Domain Management**: Add/Remove domains, manage DNS records, and configure PHP versions per domain.
-   **File Manager**: Full-featured web-based file manager to upload, edit, and manage files.
-   **Database Wizard**: Create and manage MySQL databases and users.
-   **Email Accounts**: Create email accounts with quota limits (Postfix/Dovecot/Roundcube).
-   **FTP Accounts**: Manage FTP access for your domains.
-   **One-Click Apps**: Install popular applications (phpMyAdmin, Roundcube, etc.).
-   **SSL Certificates**: Automated Let's Encrypt SSL integration.
-   **Analytics**: View bandwidth usage and traffic statistics.

### 🛠️ Admin Panel (WHM)
-   **Account Management**: Create, suspend, unsuspend, and delete client accounts.
-   **Package Manager**: Define hosting packages with resource limits (Disk, Bandwidth, Domains, etc.).
-   **Server Health**: Real-time monitoring of CPU, RAM, and Disk usage.
-   **Service Status**: Monitor status of key services (Nginx, MySQL, PHP, Mail).
-   **System Updates**: Keep the panel and system packages up to date.

---

## 🏗️ Architecture

-   **Frontend**: PHP 8.2+ (Laravel-like structure but lightweight native PHP).
-   **Backend**: `shm-manage` (Bash script) acting as a privileged bridge via sudo.
-   **Web Server**: Nginx (High performance, reverse proxy).
-   **Database**: MariaDB (MySQL compatible).
-   **Mail Server**: Postfix + Dovecot.
-   **Security**:
    -   **User Isolation**: Each client runs as a separate system user.
    -   **PHP-FPM Pools**: Dedicated PHP pools for each user/site.
    -   **Sudo Bridge**: Frontend PHP code cannot run root commands directly; it requests `shm-manage` to perform specific, validated actions.

---

## 🚀 Installation

### Prerequisites
-   **OS**: Ubuntu 20.04+ / Debian 11+ (Fresh Install Recommended)
-   **Root Access**: You must be logged in as `root`.
-   **Hardware**: 1 CPU, 2GB RAM, 20GB Disk (Minimum).

### Quick Install

1.  **Download the Project**
    Upload the files to your server (e.g., `/root/shm-panel/`).

2.  **Run the Installer**
    ```bash
    chmod +x install.sh
    sudo ./install.sh
    ```

3.  **Follow the Prompts**
    -   Enter your **Main Domain** (e.g., `panel.example.com`).
    -   Enter your **Admin Email**.
    -   Wait for the installation to complete (takes ~5-10 minutes).

4.  **Access the Panel**
    -   **Admin WHM**: `https://admin.yourdomain.com` (or `http://your-ip/whm`)
    -   **Client Panel**: `https://client.yourdomain.com` (or `http://your-ip/cpanel`)

---

## 🔧 Management CLI

The `shm-manage` tool allows you to perform administrative tasks from the command line.

```bash
# Sync all VHost configurations
shm-manage vhost-tool sync-all

# Fix permissions for a specific user
shm-manage fix-permissions <username>

# Manually create a client account
shm-manage create-account <username> <domain> <email> <password>

# Suspend a client account
shm-manage suspend-account <username>
```

---

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on how to get started.

## 📄 License

This project is open-source and available under the **MIT License**.
