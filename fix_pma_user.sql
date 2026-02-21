-- =============================================================
-- Fix phpMyAdmin 'controluser' and 'phpmyadmin' access denied
-- =============================================================

-- 1. Recreate the phpmyadmin user with a known password
DROP USER IF EXISTS 'phpmyadmin'@'localhost';
CREATE USER 'phpmyadmin'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('phpmyadmin_pass_123');

-- 2. Ensure the phpmyadmin database exists (standard for advanced features)
CREATE DATABASE IF NOT EXISTS `phpmyadmin`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. Grant privileges to the phpmyadmin user
GRANT SELECT, INSERT, UPDATE, DELETE ON `phpmyadmin`.* TO 'phpmyadmin'@'localhost';
GRANT ALL PRIVILEGES ON `shm_panel`.* TO 'phpmyadmin'@'localhost';

-- Apply changes
FLUSH PRIVILEGES;
