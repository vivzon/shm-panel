# SHM Panel (v6.0 Production)

> **Enterprise-Grade Web Hosting Control Panel**
> A lightweight, high-performance CPanel/WHM alternative for Ubuntu 20.04/22.04 LTS.

---

## 🚀 Key Features

*   **High Performance**: Nginx, PHP-FPM (8.1/8.2/8.3), and MariaDB stack.
*   **One-Click Apps**: Instantly install **WordPress**, **Laravel**, **React (Vite)**, and **CodeIgniter**.
*   **Advanced DNS**: 
    *   **Smart Subdomains**: Automatically manages DNS records for subdomains in the parent zone.
    *   **Full Bind9 Automation**: Auto-configures Zones, Glue Records, SPF, DKIM, and DMARC.
*   **Security First**:
    *   **Live Error Logs**: View and clear website error logs directly from the dashboard.
    *   **Isolated Environments**: Dedicated PHP-FPM pools for every user.
    *   **Monitoring**: Real-time traffic stats and Malware Scanning (ClamAV).
*   **File Manager**: Powerful web-based file manager with unzip, edit, and large file upload support (2GB+).

---

## 🛠️ Usage & Deployment

A comprehensive deployment guide is available in [DEPLOYMENT.md](DEPLOYMENT.md).

### Quick Install

On a fresh Ubuntu 20.04+ server (running as root):

```bash
# 1. Clone or Upload the repository
git clone https://github.com/your-repo/shm-panel.git /root/shm-panel
cd /root/shm-panel

# 2. Run the Installer
chmod +x install.sh
sudo ./install.sh
```

Follow the on-screen wizard to set up your Primary Domain and Admin Email.

### Accessing the Panel

| Role | URL | Default Credentials |
| :--- | :--- | :--- |
| **Admin (WHM)** | `http://admin.yourdomain.com` | `admin` / `admin123` |
| **Client (CPanel)** | `http://client.yourdomain.com` | Created via WHM |
| **Webmail** | `http://webmail.yourdomain.com` | Email acts as login |

---

## 🔧 Troubleshooting

*   **502 Bad Gateway**: Wait 30s after install for PHP-FPM to initialize. Run `systemctl start php8.2-fpm` if needed.
*   **Permission Denied**: Use the "Fix Permissions" tool in CPanel > Tools.
*   **Logs**: Check `/var/log/shm-manage.log` for backend operation history.

---

## 📜 License
(c) 2026 SHM Panel Development Team. Private Distribution.
