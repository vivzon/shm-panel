#!/bin/bash

# ==============================================================================
# SHM PANEL - PREFLIGHT CHECKS
# ==============================================================================

run_preflight() {
    log "Running Pre-flight Checks..."

    # 1. Check Root
    if [ "$EUID" -ne 0 ]; then 
        error "Please run as root (sudo ./install.sh)"
    fi
    
    # 2. Check Files
    if [ ! -f "shm-manage" ]; then
        if [ -f "shm-manage.txt" ]; then
            mv shm-manage.txt shm-manage
            chmod +x shm-manage
        else
            error "File 'shm-manage' not found in project root."
        fi
    fi
    
    # 3. Check OS
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$NAME
        VER=$VERSION_ID
        
        log "Detected OS: $OS $VER"
        
        if [[ "$OS" != *"Ubuntu"* && "$OS" != *"Debian"* ]]; then
            warn "This installer is optimized for Ubuntu 20.04+/Debian 11+."
            read -p "Continue anyway? (y/n) " -n 1 -r
            echo
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then 
                exit 1
            fi
        fi
        
        if [[ "$OS" == *"Ubuntu"* ]] && [[ "$VER" < "20.04" ]]; then
            warn "Ubuntu version $VER is older than recommended 20.04+"
        fi
    else
        warn "Cannot detect OS version."
    fi
    
    # 4. Internet Check (Ping Google DNS)
    if ! ping -c 1 8.8.8.8 &> /dev/null; then
        error "No internet connection detected. Installer requires internet access."
    fi
    
    # 5. Backup existing if this is a reinstall
    if [ -d "/etc/shm" ]; then
        warn "Existing installation detected!"
        log "Creating backup of existing configurations..."
        BACKUP_DIR="/root/shm-backup-$(date +%Y%m%d_%H%M%S)"
        mkdir -p "$BACKUP_DIR"
        cp -r /etc/nginx/sites-available/* "$BACKUP_DIR/" 2>/dev/null || true
        cp -r /etc/shm "$BACKUP_DIR/" 2>/dev/null || true
        log "Backup saved to $BACKUP_DIR"
    fi
}
