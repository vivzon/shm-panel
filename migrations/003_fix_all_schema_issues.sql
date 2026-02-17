-- ============================================================================
-- SHM Panel - Complete Database Schema Fix
-- ============================================================================
-- This script fixes all database schema mismatches found in the codebase
-- Run this on your existing database to fix all issues

USE shm_panel;

-- ============================================================================
-- 1. Fix existing tables - Add missing columns
-- ============================================================================

-- Add client_id to mail_users if missing (for WHM tools.php)
ALTER TABLE mail_users 
ADD COLUMN IF NOT EXISTS client_id INT AFTER id,
ADD INDEX IF NOT EXISTS idx_client_id (client_id);

-- Add domain_id to mail_users if missing
ALTER TABLE mail_users 
ADD COLUMN IF NOT EXISTS domain_id INT AFTER client_id,
ADD INDEX IF NOT EXISTS idx_domain_id (domain_id);

-- ============================================================================
-- 2. Create missing tables
-- ============================================================================

-- Client-specific databases table (for cpanel/databases.php)
CREATE TABLE IF NOT EXISTS client_databases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    domain_id INT,
    db_name VARCHAR(64) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain_id (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Client database users table (for cpanel/databases.php)
CREATE TABLE IF NOT EXISTS client_db_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    db_user VARCHAR(32) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    UNIQUE KEY unique_client_user (client_id, db_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App installations table (for cpanel/apps.php and cpanel/tools.php)
CREATE TABLE IF NOT EXISTS app_installations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    domain_id INT NOT NULL,
    app_type VARCHAR(50) NOT NULL,
    db_name VARCHAR(64),
    db_user VARCHAR(32),
    db_pass VARCHAR(255),
    install_path VARCHAR(500),
    status ENUM('installing', 'installed', 'failed') DEFAULT 'installing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain_id (domain_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FTP users table (for whm/tools.php and cpanel/tools.php)
CREATE TABLE IF NOT EXISTS ftp_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    userid VARCHAR(32) UNIQUE NOT NULL,
    passwd VARCHAR(255) NOT NULL,
    homedir VARCHAR(500) NOT NULL,
    uid INT DEFAULT 33,
    gid INT DEFAULT 33,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_userid (userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. Fix php_config table structure
-- ============================================================================

-- Drop old php_config if it exists with wrong structure
DROP TABLE IF EXISTS php_config;

-- Recreate with correct structure
CREATE TABLE php_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    domain_id INT,
    memory_limit VARCHAR(20) DEFAULT '128M',
    max_execution_time INT DEFAULT 30,
    upload_max_filesize VARCHAR(20) DEFAULT '64M',
    post_max_size VARCHAR(20) DEFAULT '64M',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_domain_id (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. Add parent_id to domains table (for subdomains)
-- ============================================================================

ALTER TABLE domains 
ADD COLUMN IF NOT EXISTS parent_id INT AFTER domain,
ADD INDEX IF NOT EXISTS idx_parent_id (parent_id);

-- ============================================================================
-- 5. Verify all tables exist
-- ============================================================================

SELECT 
    'Tables Check' as Status,
    COUNT(*) as Total_Tables
FROM information_schema.tables 
WHERE table_schema = 'shm_panel';

-- List all tables
SELECT table_name 
FROM information_schema.tables 
WHERE table_schema = 'shm_panel'
ORDER BY table_name;

-- ============================================================================
-- 6. Verify critical columns
-- ============================================================================

SELECT 
    table_name,
    column_name,
    data_type
FROM information_schema.columns
WHERE table_schema = 'shm_panel'
    AND table_name IN ('dns_records', 'mail_domains', 'mail_users', 'domains', 'php_config')
ORDER BY table_name, ordinal_position;

SELECT 'Schema fix completed successfully!' as Result;
