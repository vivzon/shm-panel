-- ============================================================================
-- SHM Panel - Security Tables Migration
-- ============================================================================
-- This script adds security-related tables for logging and monitoring
-- Run this after implementing the security fixes
-- ============================================================================

-- Security logs table for tracking security events
CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(255) NOT NULL,
    severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
    ip VARCHAR(45),
    user VARCHAR(50),
    user_agent VARCHAR(500),
    context TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_severity (severity),
    INDEX idx_user (user),
    INDEX idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Error logs table for centralized error tracking
CREATE TABLE IF NOT EXISTS error_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    file VARCHAR(500),
    line INT,
    user VARCHAR(50),
    trace TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_type (type),
    INDEX idx_user (user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login attempts tracking for rate limiting and security monitoring
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    ip VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500),
    success BOOLEAN DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip),
    INDEX idx_username (username),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session tracking for security monitoring
CREATE TABLE IF NOT EXISTS active_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) UNIQUE NOT NULL,
    user_id INT,
    user_type ENUM('client', 'admin') NOT NULL,
    ip VARCHAR(45),
    user_agent VARCHAR(500),
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, user_type),
    INDEX idx_session (session_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Sample Data for Testing
-- ============================================================================

-- Insert a sample security log entry
INSERT INTO security_logs (event, severity, ip, user, user_agent, context) 
VALUES ('System initialized', 'info', '127.0.0.1', 'system', 'Installation Script', '{"version": "1.0.0"}');

-- ============================================================================
-- Cleanup Old Sessions (Run periodically via cron)
-- ============================================================================

-- Delete sessions older than 24 hours
-- Add this to a cron job: 0 * * * * mysql shm_panel -e "DELETE FROM active_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 24 HOUR);"
-- DELETE FROM active_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- ============================================================================
-- Useful Queries for Monitoring
-- ============================================================================

-- View recent security events
-- SELECT * FROM security_logs ORDER BY created_at DESC LIMIT 50;

-- View failed login attempts
-- SELECT username, ip, COUNT(*) as attempts, MAX(created_at) as last_attempt 
-- FROM login_attempts 
-- WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
-- GROUP BY username, ip 
-- ORDER BY attempts DESC;

-- View active sessions
-- SELECT s.*, 
--        CASE WHEN s.user_type = 'admin' THEN a.username ELSE c.username END as username
-- FROM active_sessions s
-- LEFT JOIN admins a ON s.user_id = a.id AND s.user_type = 'admin'
-- LEFT JOIN clients c ON s.user_id = c.id AND s.user_type = 'client'
-- WHERE s.last_activity > DATE_SUB(NOW(), INTERVAL 1 HOUR)
-- ORDER BY s.last_activity DESC;

-- ============================================================================
-- Verification
-- ============================================================================

-- Check if all tables were created successfully
SELECT 
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'shm_panel' 
AND TABLE_NAME IN ('security_logs', 'error_logs', 'login_attempts', 'active_sessions');
