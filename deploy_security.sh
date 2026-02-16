#!/bin/bash

# ============================================================================
# SHM Panel - Security Implementation Deployment Script
# ============================================================================
# This script automates the deployment of security fixes to your server
# Run this from your local machine after reviewing all changes
# ============================================================================

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SERVER="vivzon.cloud"
SERVER_USER="root"
PANEL_PATH="/var/www/panel"
DB_NAME="shm_panel"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}SHM Panel Security Deployment Script${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Function to print colored messages
log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Check if we're in the correct directory
if [ ! -f "shared/Database.php" ]; then
    log_error "Please run this script from the shm-panel directory"
    exit 1
fi

# Step 1: Create backup
log_info "Step 1: Creating backup on server..."
ssh $SERVER_USER@$SERVER "cd /root && tar -czf shm-panel-backup-\$(date +%Y%m%d-%H%M%S).tar.gz $PANEL_PATH && mysqldump $DB_NAME > shm-panel-db-backup-\$(date +%Y%m%d-%H%M%S).sql"
if [ $? -eq 0 ]; then
    log_info "✓ Backup created successfully"
else
    log_error "✗ Backup failed"
    exit 1
fi

# Step 2: Upload security files
log_info "Step 2: Uploading security files..."
scp shared/Database.php $SERVER_USER@$SERVER:$PANEL_PATH/shared/
scp shared/security.php $SERVER_USER@$SERVER:$PANEL_PATH/shared/
scp shared/session.php $SERVER_USER@$SERVER:$PANEL_PATH/shared/
if [ $? -eq 0 ]; then
    log_info "✓ Security files uploaded"
else
    log_error "✗ Upload failed"
    exit 1
fi

# Step 3: Set proper permissions
log_info "Step 3: Setting file permissions..."
ssh $SERVER_USER@$SERVER "chown www-data:www-data $PANEL_PATH/shared/*.php && chmod 644 $PANEL_PATH/shared/*.php"
if [ $? -eq 0 ]; then
    log_info "✓ Permissions set"
else
    log_warn "⚠ Permission setting failed (non-critical)"
fi

# Step 4: Run database migrations
log_info "Step 4: Running database migrations..."
scp migrations/001_security_tables.sql $SERVER_USER@$SERVER:/tmp/
ssh $SERVER_USER@$SERVER "mysql $DB_NAME < /tmp/001_security_tables.sql && rm /tmp/001_security_tables.sql"
if [ $? -eq 0 ]; then
    log_info "✓ Database migrations completed"
else
    log_error "✗ Database migration failed"
    exit 1
fi

# Step 5: Test database connection
log_info "Step 5: Testing database connection..."
ssh $SERVER_USER@$SERVER "cd $PANEL_PATH && php -r \"require_once 'shared/Database.php'; try { \\\$db = Database::getInstance(); echo 'OK'; } catch (Exception \\\$e) { echo 'FAIL: ' . \\\$e->getMessage(); exit(1); }\""
if [ $? -eq 0 ]; then
    log_info "✓ Database connection test passed"
else
    log_error "✗ Database connection test failed"
    exit 1
fi

# Step 6: Create log files
log_info "Step 6: Creating log files..."
ssh $SERVER_USER@$SERVER "touch /var/log/shm-security.log /var/log/shm-panel-errors.log && chown www-data:www-data /var/log/shm-*.log && chmod 644 /var/log/shm-*.log"
if [ $? -eq 0 ]; then
    log_info "✓ Log files created"
else
    log_warn "⚠ Log file creation failed (non-critical)"
fi

# Step 7: Verify installation
log_info "Step 7: Verifying installation..."
VERIFICATION=$(ssh $SERVER_USER@$SERVER "cd $PANEL_PATH && ls -la shared/Database.php shared/security.php shared/session.php 2>/dev/null | wc -l")
if [ "$VERIFICATION" -eq "3" ]; then
    log_info "✓ All security files present"
else
    log_error "✗ Some security files are missing"
    exit 1
fi

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Deployment Completed Successfully!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
log_info "Next steps:"
echo "  1. Review the implementation guide: SECURITY_IMPLEMENTATION.md"
echo "  2. Update your PHP files to use the new security classes"
echo "  3. Test thoroughly in a development environment"
echo "  4. Monitor logs: /var/log/shm-security.log"
echo ""
log_warn "Important: The security files are deployed but not yet integrated."
log_warn "You must update your PHP files to use them (see examples/ directory)."
echo ""
