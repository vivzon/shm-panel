#!/bin/bash

# ==============================================================================
# SHM PANEL - INSTALLER BOOTSTRAP
# ==============================================================================

# Strict Mode
set -e
set -o pipefail

# --- Colors ---
export GREEN='\033[0;32m'
export BLUE='\033[0;34m'
export RED='\033[0;31m'
export YELLOW='\033[1;33m'
export CYAN='\033[0;36m'
export NC='\033[0m'

# --- Logging Configuration ---
LOG_FILE="/var/log/shm-install.log"

# Ensure log directory exists
if [ ! -d "$(dirname "$LOG_FILE")" ]; then
    mkdir -p "$(dirname "$LOG_FILE")"
fi

# Initialize log
if [ ! -f "$LOG_FILE" ]; then
    touch "$LOG_FILE"
    chmod 600 "$LOG_FILE"
fi

# --- Helper Functions ---

# Log to screen and file
log() {
    local msg="$1"
    echo -e "${GREEN}[INSTALLER] ${msg}${NC}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] $msg" >> "$LOG_FILE"
}

warn() {
    local msg="$1"
    echo -e "${YELLOW}[WARNING] ${msg}${NC}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [WARN] $msg" >> "$LOG_FILE"
}

error() {
    local msg="$1"
    echo -e "${RED}[ERROR] ${msg}${NC}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] $msg" >> "$LOG_FILE"
    exit 1
}

info() {
    local msg="$1"
    echo -e "${CYAN}[INFO] ${msg}${NC}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] $msg" >> "$LOG_FILE"
}

# Error Handler
handle_error() {
    local line=$1
    local command=$2
    local code=$3
    echo -e "${RED}[FATAL] Command '$command' failed at line $line with exit code $code${NC}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [FATAL] Command '$command' failed at line $line with exit code $code" >> "$LOG_FILE"
}

trap 'handle_error ${LINENO} "$BASH_COMMAND" $?' ERR

# Header
show_header() {
    clear
    echo -e "${BLUE}"
    echo "  _____________________________________"
    echo " / SHM Panel - Modular Installer       \\"
    echo " \\_____________________________________/"
    echo -e "${NC}"
}
