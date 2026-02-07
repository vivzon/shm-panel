# SHM Panel Architecture

## High-Level Overview

SHM Panel is a web hosting control panel designed for Ubuntu/Debian servers. It consists of a **frontend** (PHP-based) for user interaction and a **backend** (Bash-based) for executing privileged system commands.

The application follows a **split-privilege model**:
-   **Frontend**: Runs as `www-data` (limited privileges).
-   **Backend**: Runs as `root` via `sudo` (privileged operations).

---

## 🏗️ Components

### 1. Frontend (Web Interface)
The frontend is divided into two main sections:
-   **Client Panel (CPanel)**: Located in `/var/www/panel/cpanel/`. Used by end-users to manage their domains, files, databases, and emails.
-   **Admin Panel (WHM)**: Located in `/var/www/panel/whm/`. Used by administrators to manage accounts, packages, and system health.

**Key Technologies:**
-   **Language**: PHP 8.1+
-   **Web Server**: Nginx
-   **Database**: MariaDB (stores user data, domains, settings)
-   **Template Engine**: Native PHP with modular layout (header/footer).

### 2. Backend Engine (`shm-manage`)
The core of the system is the `shm-manage` bash script located at `/usr/local/bin/shm-manage`. This script handles all system-level operations that require root privileges.

**Responsibilities:**
-   Creating/Deleting system users (`useradd`, `userdel`).
-   Managing Nginx virtual hosts (creation, deletion, reloading).
-   Managing PHP-FPM pools.
-   Managing MySQL databases and users.
-   Configuring DNS zones (BIND).
-   Managing Email accounts (Postfix/Dovecot).

**Execution Flow:**
1.  Frontend (PHP) receives a request (e.g., "Create Account").
2.  PHP validates the input.
3.  PHP executes `sudo shm-manage <command> <args>` via `shell_exec()`.
4.  `shm-manage` executes the command and returns the output.
5.  PHP parses the output and displays the result to the user.

### 3. Database Schema
The database (`shm_panel`) stores all persistent data. Key tables include:
-   `clients`: Stores user account details.
-   `domains`: Stores domain information and settings.
-   `packages`: Defines hosting plans and resource limits.
-   `admins`: Stores administrator credentials.
-   `dns_records`: Stores DNS records for domains.

### 4. Web Server (Nginx)
Nginx acts as the reverse proxy and web server.
-   **Main Panel**: Served via a dedicated server block.
-   **User Sites**: Each user site has its own configuration file in `/etc/nginx/sites-available/`, symlinked to `/etc/nginx/sites-enabled/`.
-   **PHP-FPM**: PHP requests are proxied to the appropriate PHP-FPM pool via Unix sockets.

---

## 🔒 Security Model

### User Isolation
-   Each client account maps to a unique **Linux System User**.
-   Client files are stored in `/var/www/clients/<username>/`.
-   **PHP-FPM Pool**: Each user has a dedicated PHP-FPM pool running as their own user/group. This ensures that one user cannot access another user's files.
-   **OpenBasedir**: PHP is restricted to the user's home directory.

### Sudo Bridge
The web server user (`www-data`) is granted strictly limited sudo access via `/etc/sudoers.d/shm`. It can *only* execute the `/usr/local/bin/shm-manage` script. This prevents arbitrary command execution even if the web interface is compromised.

### Input Validation
-   Both the 
Frontend (PHP) and Backend (Bash) perform strict input validation to prevent command injection and ensure data integrity.
