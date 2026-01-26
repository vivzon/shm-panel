# Implementation Plan - Update Utility Improvements

## Goal
Update `update.sh` to be a robust migration tool for existing SHM Panel installations. It must fix the "WHM Domain Hijacking" issue, repair log directory permissions, and update the database schema.

## Proposed Changes

### 1. `update.sh` (Shell Script)
- **[NEW] Default Nginx Block**: Add logic to check for and create the `000-default` catch-all configuration if it doesn't exist. This matches the fix in `install.sh`.
- **[NEW] Log Directory Fix**: Add a loop to iterate through `/var/www/clients/*` and ensure the `logs` directory format is correct or at least exists and is writable.
- **[MODIFY] Schema Updates**: Sync the SQL schema definitions from `install.sh` to ensuring missing columns/tables are added idempotently.
- **[MODIFY] Service Reload**: Enhance `check_and_reload` to run `nginx -t` before reloading (copy logic from `shm-manage`).

## Verification Plan

### Manual Verification
1.  **Dry Run Logic**: Since I cannot run this on a live server, I will verify the script syntax and logic correctness by review.
2.  **User Instructions**:
    -   User downloads updated script.
    -   Runs `./update.sh`.
    -   Verifies that `ls /etc/nginx/sites-enabled/000-default` exists.
    -   Verifies that Nginx reloads without error.
