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
- **User**: Root access required for full installation
- **Architecture**: x86_64
- **Web Server**: Nginx (managed by installer)
- **Database**: MariaDB (managed by installer)

## 🛠️ Installation & Deployment

### Option A: Local Development (Windows/Linux)
You can run the panel locally to test the UI and Database interactions.

1.  **Clone the Repository**:
    ```bash
    git clone https://github.com/your-repo/shm-panel.git
    cd shm-panel
    ```
2.  **Start Web Server**: Point your local web server (XAMPP, Laragon, or builtin PHP) to the project root.
    ```bash
    php -S localhost:8000
    ```
3.  **Run Web Installer**:
    Open `http://localhost:8000/install.php` in your browser.
    - Enter your local database credentials (e.g., root/empty).
    - The installer will create the database, tables, and admin user.
    - It will generate `shared/config.local.php`.

### Option B: Production Server (Ubuntu)
This installs the full stack (Nginx, PHP, MySQL, Mail Server, etc.).

1.  **Upload to Server**:
    Upload the files to your server (e.g., `/root/shm-panel`).
2.  **Run the Provisioning Script**:
    ```bash
    chmod +x install.sh
    ./install.sh
    ```
    *This script handles system-level dependencies and executes the Web Installer logic automatically.*

## 📂 Project Structure

```
shm-panel/
├── whm/               # Admin Interface (PHP)
├── cpanel/            # Client Interface (PHP)
├── landing/           # Main Landing Page
├── shared/            # Shared Configuration & Assets
│   ├── config.php     # Main Config Loader
│   └── config.local.php # Local Overrides (GitIgnored)
├── install.php        # Web Installer (Single-File Setup)
├── shm-manage         # Backend Engine (Bash)
└── install.sh         # Production Server Provisioner
```

## 🌐 Accessing the Panel

| Service | URL | Default Creds |
| :--- | :--- | :--- |
| **Main Portal** | `http://example.com` | (Landing Page) |
| **WHM Admin** | `http://admin.example.com` | `admin` / `admin123` |
| **CPanel** | `http://client.example.com` | (Created via WHM) |
| **Webmail** | `http://webmail.example.com` | (SSO / Email Creds) |
| **File Manager** | `http://filemanager.example.com` | (SSO / cPanel Creds) |
| **phpMyAdmin** | `http://phpmyadmin.example.com` | (DB User Creds) |

> **Important**: On local setup, access via `http://localhost/shm-panel/whm` or `http://localhost/shm-panel/cpanel`.

## 🔒 Security

- **Database**: Uses `utf8mb4` and prepared statements (PDO) to prevent SQL injection.
- **System**: PHP runs as specific system users (pool isolation).
- **Network**: UFW firewall configured to allow only essential ports (80, 443, 22, 21, 25, etc.).

## 🔄 Deploying Updates

To update the panel manually (e.g., applying fixes):
1.  Upload the modified files (e.g., `cpanel/`, `shared/`) to your server.
2.  **Important**: If you overwrite `shared/config.php` on a live server, it may reset your database password. Run `repair_config.sh` to fix this.

## 🔧 Troubleshooting

### Database Connection Failed / HTTP 500
If your site goes down after an update, it's likely the configuration was reset.
1.  Upload `repair_config.sh` to your server.
2.  Run the script to restore your password:
    ```bash
    chmod +x repair_config.sh
    ./repair_config.sh
    ```

## 📜 License

This project is open-source and available under the [MIT License](LICENSE).
