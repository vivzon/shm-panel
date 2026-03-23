#!/bin/bash

# ==============================================================================
# SHM PANEL - UNIFIED INSTALLER (One-Click Setup)
# ==============================================================================
# This script prepares the server and launches the modular SHM Panel installer.
# Supported OS: Ubuntu 20.04, 22.04, 24.04 LTS
# ==============================================================================

set -e

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log() { echo -e "${GREEN}[INFO]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# --- Root Check ---
if [[ $EUID -ne 0 ]]; then
   error "This script must be run as root. Try: sudo bash $0"
fi

# --- OS Compatibility Check ---
if [ -f /etc/os-release ]; then
    . /etc/os-release
    if [[ "$ID" != "ubuntu" ]]; then
        error "SHM Panel currently only supports Ubuntu. Detected: $ID"
    fi
    log "Operating System: $PRETTY_NAME"
else
    error "Could not detect Operating System. Is this a Linux distribution?"
fi

# --- Dependency Installation ---
log "Checking base dependencies..."
apt-get update -y > /dev/null
apt-get install -y git curl wget unzip software-properties-common > /dev/null

# --- Project Directory Setup ---
INSTALL_DIR="/root/shm-panel"

if [ ! -d "$INSTALL_DIR/.git" ]; then
    log "Cloning SHM Panel repository..."
    if [ -d "$INSTALL_DIR" ]; then
        warn "$INSTALL_DIR already exists but is not a git repo. Backing up to ${INSTALL_DIR}_old..."
        mv "$INSTALL_DIR" "${INSTALL_DIR}_old"
    fi
    git clone https://github.com/vivzon/shm-panel.git "$INSTALL_DIR"
else
    log "SHM Panel repository already exists. Syncing latest updates..."
    cd "$INSTALL_DIR"
    git fetch --all > /dev/null
    git reset --hard origin/main > /dev/null
fi

cd "$INSTALL_DIR"

# --- Permissions Fix ---
log "Setting executable permissions..."
chmod +x install.sh
chmod +x installer/*.sh
chmod +x shm-manage 2>/dev/null || true

# --- Launch Modular Installer ---
echo -e "${BLUE}"
echo "  *****************************************"
echo "  *        SHM PANEL INSTALLATION         *"
echo "  *****************************************"
echo -e "${NC}"

log "Launching internal installer modules..."
./install.sh

# --- Verification ---
if [ $? -eq 0 ]; then
    echo -e "${GREEN}"
    echo "  ========================================="
    echo "  STAGING COMPLETE - WEB PANEL READY"
    echo "  ========================================="
    echo -e "${NC}"
    log "You can now access your panel at the domains you configured."
    log "If you need to re-run the web installer, access: http://your-ip/install.php"
else
    error "Installation failed during the modular phase. Please check /var/log/shm-install.log"
fi
