-- =============================================================
-- SHM Panel - Fix MariaDB User Authentication (Error 1698)
-- =============================================================
-- Run as root: sudo mysql < fix_db_user.sql
-- OR: sudo mysql -e "source /path/to/fix_db_user.sql"
-- =============================================================

-- Drop old users if they exist (to start clean)
DROP USER IF EXISTS 'shm_user'@'localhost';
DROP USER IF EXISTS 'shm_admin'@'localhost';

-- Create the DB user with native password auth (fixes Error 1698)
CREATE USER 'shm_admin'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('QwErTyUiOp@1');

-- Ensure the database exists
CREATE DATABASE IF NOT EXISTS `shm_panel`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Grant full access on shm_panel database
GRANT ALL PRIVILEGES ON `shm_panel`.* TO 'shm_admin'@'localhost';

-- Apply changes immediately
FLUSH PRIVILEGES;

-- Verify (optional - comment out if needed)
SELECT User, Host, plugin FROM mysql.user WHERE User IN ('shm_admin', 'shm_user');
