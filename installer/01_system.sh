#!/bin/bash

# ==============================================================================
# SHM PANEL - SYSTEM SETUP
# ==============================================================================

setup_system() {
    log "Starting System Preparation..."
    
    export DEBIAN_FRONTEND=noninteractive
    
    # 1. Update System
    log "Updating System Repositories..."
    apt-get update
    apt-get upgrade -y
    
    # 2. Install Dependencies
    log "Installing Core Dependencies..."
    apt-get install -y software-properties-common curl wget git zip unzip ufw fail2ban \
        acl quota jq clamav clamav-daemon clamav-freshclam htop nmon net-tools \
        bmon iftop nethogs rclone rsync screen tmux gnupg2 ca-certificates lsb-release \
        apt-transport-https
        
    # 3. Swap Setup
    if [ $(swapon --show | wc -l) -eq 0 ]; then
        log "Allocating 2GB Swap File..."
        fallocate -l 2G /swapfile
        chmod 600 /swapfile
        mkswap /swapfile
        swapon /swapfile
        echo '/swapfile none swap sw 0 0' | tee -a /etc/fstab
        
        # Tuning Swap
        sysctl vm.swappiness=10
        echo 'vm.swappiness=10' >> /etc/sysctl.conf
    else
        log "Swap already exists. Skipping."
    fi
    
    # 4. Kernel Optimization
    log "Optimizing Kernel Parameters..."
    cat > /etc/sysctl.d/99-shm-tuning.conf << SYSCTL_TUNE
# Network tuning
net.core.rmem_max = 134217728
net.core.wmem_max = 134217728
net.ipv4.tcp_rmem = 4096 87380 134217728
net.ipv4.tcp_wmem = 4096 65536 134217728
net.ipv4.tcp_congestion_control = bbr
net.ipv4.tcp_fastopen = 3

# Security hardening
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.accept_source_route = 0
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.icmp_ignore_bogus_error_responses = 1

# File descriptor limits
fs.file-max = 2097152
fs.nr_open = 2097152
kernel.pid_max = 4194303

# Memory
vm.vfs_cache_pressure = 50
vm.dirty_ratio = 10
vm.dirty_background_ratio = 5
SYSCTL_TUNE
    
    sysctl -p /etc/sysctl.d/99-shm-tuning.conf
    
    # 5. Automatic Updates
    log "Configuring automatic security updates..."
    apt-get install -y unattended-upgrades apt-listchanges
    
    cat > /etc/apt/apt.conf.d/50unattended-upgrades << UNATTENDED
Unattended-Upgrade::Allowed-Origins {
    "\${distro_id}:\${distro_codename}";
    "\${distro_id}:\${distro_codename}-security";
    "\${distro_id}ESMApps:\${distro_codename}-apps-security";
    "\${distro_id}ESM:\${distro_codename}-infra-security";
};
Unattended-Upgrade::Origins-Pattern {
    "origin=Debian,codename=\${distro_codename},label=Debian-Security";
    "origin=Ubuntu,codename=\${distro_codename},label=Ubuntu";
};
Unattended-Upgrade::AutoFixInterruptedDpkg "true";
Unattended-Upgrade::MinimalSteps "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
UNATTENDED

    cat > /etc/apt/apt.conf.d/20auto-upgrades << AUTO_UPGRADES
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade "1";
AUTO_UPGRADES
}
