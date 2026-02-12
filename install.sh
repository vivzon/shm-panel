#!/bin/bash

# ==============================================================================
# SHM PANEL - MAIN INSTALLER
# ==============================================================================

# 1. Load Bootstrap
if [ -f "installer/bootstrap.sh" ]; then
    source "installer/bootstrap.sh"
else
    echo "Error: installer/bootstrap.sh not found. Are you in the project root?"
    exit 1
fi

# 2. Show Header
show_header

# 3. Load Configuration (Interactive if needed)
source "installer/config.sh"
load_config

# 4. Load Modules
source "installer/00_preflight.sh"
source "installer/01_system.sh"
source "installer/02_web.sh"
source "installer/03_database.sh"
source "installer/04_mail.sh"
source "installer/05_panel.sh"
source "installer/06_finalize.sh"

# 5. Execute Steps
log "Starting Installation Process..."

# Step 0: Pre-flight
run_preflight

# Step 1: System Base
setup_system

# Step 2: Web Stack
setup_web

# Step 3: Database
setup_database

# Step 4: Mail
setup_mail

# Step 5: Panel Logic
deploy_panel

# Step 6: Finalize
finalize_install

# Done
log "Installation Process Completed."
exit 0