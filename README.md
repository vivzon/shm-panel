# SHM Panel (v5.0)

SHM Panel is a lightweight, high-performance web hosting control panel alternative to CPanel/WHM. It provides a robust backend engine and a modern web interface for managing Nginx, PHP, MySQL, Email, and DNS.

## Features
-   **Web Server**: Nginx with PHP-FPM (Supports PHP 8.1, 8.2, 8.3).
-   **Database**: MariaDB with automatic user/schema management.
-   **Email**: Postfix + Dovecot (IMAP/POP3) with SQL authentication.
-   **DNS**: Bind9 integration for automated zone management.
-   **File Manager**: Custom-built, high-performance file manager (v5.0).
-   **One-Click Apps**: Install WordPress, Laravel, React, and CodeIgniter.
-   **Security**: Fail2Ban, UFW, and isolated Users (fpm pools).

## Installation

### Prerequisites
-   **OS**: Ubuntu 20.04 LTS or 22.04 LTS (Recommended), or Debian 11/12.
-   **User**: Root access (mandatory).
-   **Resources**: Minimum 1GB RAM, 10GB Disk.

### Quick Start
1.  **Clone or Upload** the project files to your server (e.g., `/root/shm-panel`).
2.  **Run the Installer**:
    ```bash
    chmod +x install.sh
    sudo ./install.sh
    ```
3.  **Follow the Wizard**:
    -   Enter your main domain (e.g., `example.com`).
    -   Enter your admin email.

The installer will automatically:
-   Update system packages.
-   Install Nginx, PHP, MariaDB, and Mail services.
-   Configure the Database and Schema.
-   Deploy the Backend Engine (`shm-manage`).
-   Set up the Web Interface.

### Accessing the Panel
Once installed, you can access the following dashboards:

-   **Admin Panel (WHM)**: `http://admin.yourdomain.com`
    -   *Default User*: `admin`
    -   *Default Pass*: `admin123`
-   **Client Panel (CPanel)**: `http://client.yourdomain.com`
    -   *Login with created client accounts.*
-   **Webmail**: `http://webmail.yourdomain.com`

> **Note**: The installer will output the MySQL Root Password and the Database Password at the end. **Save these credentials immediately.**

## Directory Structure
-   `/usr/local/bin/shm-manage` - Backend executable.
-   `/var/www/panel/` - Frontend source code (WHM, CPanel).
-   `/var/www/clients/` - User data (websites, logs).
-   `/etc/shm/` - System configuration.

## Development info
-   **Backend**: Bash Scripts (`shm-manage`).
-   **Frontend**: Native PHP + TailwindCSS.
-   **Database**: MySQL/MariaDB.
