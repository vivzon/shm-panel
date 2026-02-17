#!/bin/bash
# SHM Panel - Fix PDO MySQL Driver Issue
# This script installs the required PHP PDO MySQL extension

echo "=== SHM Panel - Installing PHP PDO MySQL Extension ==="
echo ""

# Detect PHP version
PHP_VERSION=$(php -v | head -n 1 | cut -d " " -f 2 | cut -d "." -f 1,2)
echo "Detected PHP version: $PHP_VERSION"
echo ""

# Detect OS
if [ -f /etc/debian_version ]; then
    OS="debian"
    echo "OS: Debian/Ubuntu"
elif [ -f /etc/redhat-release ]; then
    OS="redhat"
    echo "OS: RedHat/CentOS"
else
    echo "Unknown OS. Please install php-mysql manually."
    exit 1
fi

echo ""
echo "Installing PHP PDO MySQL extension..."
echo ""

# Install based on OS
if [ "$OS" = "debian" ]; then
    apt-get update
    apt-get install -y php${PHP_VERSION}-mysql php${PHP_VERSION}-pdo
elif [ "$OS" = "redhat" ]; then
    yum install -y php-mysql php-pdo
fi

# Restart web server
echo ""
echo "Restarting web server..."
if systemctl is-active --quiet apache2; then
    systemctl restart apache2
    echo "✓ Apache2 restarted"
elif systemctl is-active --quiet httpd; then
    systemctl restart httpd
    echo "✓ Apache (httpd) restarted"
fi

# Verify installation
echo ""
echo "Verifying installation..."
if php -m | grep -q pdo_mysql; then
    echo "✅ PDO MySQL extension installed successfully!"
    echo ""
    echo "Installed modules:"
    php -m | grep -i pdo
else
    echo "❌ Installation may have failed. Please check manually."
    exit 1
fi

echo ""
echo "You can now run the migration:"
echo "  cd /var/www/panel"
echo "  php migrations/run_004_migration.php"
