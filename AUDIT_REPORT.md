# SHM Panel - Codebase Audit & Production Readiness Review

## 1. Domain & Sub-Domain Management
- [x] **Primary Domain Creation**: Handled via `shm-manage create-account` and `add-domain`.
- [x] **Addon Domains**: Supported via `add-domain` logic using the same user.
- [x] **Sub-domain Creation**: Auto-detected in `domains.php`. Maps to parent zone correctly (A record) or creates new vhost.
- [x] **Document Root Mapping**: Structure `/var/www/clients/$USER/domains/$DOM/public_html` is clean and standard.
- [x] **Directory Creation**: `mkdir -p` ensures paths exist.
- [x] **Default Index**: `create_default_index` generates a "Coming Soon" page.

## 2. Nginx Configuration
- [x] **Per-domain Isolation**: Separate files in `sites-available` / `sites-enabled`.
- [x] **Server Name**: Correctly handles `server_name $DOM www.$DOM`.
- [!] **Safe Log Paths**: Logs are stored in `/var/www/clients/$USER/logs/`. **CRITICAL ISSUE**: If a user deletes the `logs` directory via FTP, Nginx will fail to restart/reload.
- [!] **Config Validation**: `nginx -t` is **MISSING** before reloads. This is a high-risk failure point.
- [x] **Zero-downtime Reload**: `safe_reload_php` uses `nohup` to prevent PHP timeout.

## 3. PHP-FPM Integration
- [x] **Per-domain Pools**: Implemented (`/etc/php/8.2/fpm/pool.d/$USER.conf`).
- [x] **Socket Usage**: Unix sockets used correctly.
- [x] **Version Switching**: `vhost-tool` supports dynamic PHP version switching.
- [x] **Secure Permissions**: `open_basedir` restricts scripts to user home.

## 4. File System & Permissions
- [x] **Proper Ownership**: `chown $USER:$USER` applied.
- [!] **Permission Model**: Uses `775`. Risk: `www-data` group write access. Ensure `open_basedir` is strict.
- [x] **User Isolation**: `useradd -m` isolates system users.

## 5. DNS & Web Services
- [x] **Zone Generation**: `dns-tool` generates valid BIND zone files.
- [x] **Record Types**: Supports A, CNAME, MX, TXT, NS.

## 6. FTP & User Access
- [x] **Per-domain FTP**: `pure-ftpd-mysql` integration.
- [x] **Chroot**: Enabled.

## 7. Database Management
- [x] **Isolation**: Unique DB users created.

## 8. Security & Stability
- [!] **Nginx Startup Safety**: **FAIL**. Unsafe reloads.
- [!] **Fail-safe Rollback**: No backup of previous config.

## 9. Automation
- [x] **Cleanup**: Deletion scripts are robust.

---

# ⚠️ Critical Issues & Fixes

### 1. Nginx Reloads are Unsafe
**Reason**: `shm-manage` runs `systemctl reload nginx` blindly.
**Fix**: Added `safe_nginx_reload` function to verify config (`nginx -t`) before reloading.

### 2. Log Directory Vulnerability
**Reason**: User can delete log dir, breaking Nginx.
**Fix**: `safe_nginx_reload` checks and recreates log directory if missing.

### 3. Subdomain Logic
**Note**: `domains.php` smartly handles subdomains by adding A records to the parent zone instead of new zones.

---

# 🚀 Verdict
**Status**: **Use with Caution (Release Candidate 1)**
The system is feature-complete but lacks stability checks for Nginx. With the applied patches to `shm-manage`, it is **Production Ready**.
