# Security Implementation Guide

This document provides step-by-step instructions for implementing the critical security fixes in SHM Panel.

## 🔴 Critical Security Files Created

The following security files have been created and are ready to use:

### 1. `shared/Database.php` - SQL Injection Prevention
**Purpose**: Secure database wrapper using PDO prepared statements

**Features**:
- Singleton pattern for single connection
- Prepared statements for all queries
- Transaction support
- Error logging
- Multiple query methods (fetchOne, fetchAll, execute)

**Usage Example**:
```php
require_once '../shared/Database.php';

$db = Database::getInstance();

// Fetch single row
$client = $db->fetchOne("SELECT * FROM clients WHERE username = ?", [$username]);

// Fetch all rows
$domains = $db->fetchAll("SELECT * FROM domains WHERE client_id = ?", [$clientId]);

// Execute INSERT/UPDATE/DELETE
$db->execute("INSERT INTO clients (username, email, password) VALUES (?, ?, ?)", 
    [$username, $email, $hashedPassword]);

// Get last inserted ID
$clientId = $db->lastInsertId();
```

### 2. `shared/security.php` - CSRF Protection & Security Utilities
**Purpose**: Comprehensive security functions

**Features**:
- CSRF token generation and validation
- Input sanitization and validation
- Password hashing (bcrypt with cost 12)
- Rate limiting
- Security event logging
- Shell argument escaping

**Usage Example**:
```php
require_once '../shared/security.php';

// CSRF Protection in forms
<form method="POST">
    <?php echo csrf_field(); ?>
    <input type="text" name="domain">
    <button>Submit</button>
</form>

// CSRF Validation on submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    // Process form...
}

// Input validation
if (!validate_input($_POST['email'], 'email')) {
    die('Invalid email');
}

// Password hashing
$hash = hash_password($password);
if (verify_password($inputPassword, $hash)) {
    // Login successful
}

// Rate limiting
if (!check_rate_limit('login:' . $_SERVER['REMOTE_ADDR'], 5, 300)) {
    die('Too many attempts. Try again later.');
}
```

### 3. `shared/session.php` - Secure Session Management
**Purpose**: Prevent session fixation and hijacking

**Features**:
- Secure cookie configuration (HttpOnly, Secure, SameSite)
- Session ID regeneration every 30 minutes
- User agent validation
- Automatic timeout after 1 hour of inactivity
- Helper functions for authentication

**Usage Example**:
```php
// Replace all session_start() calls with:
require_once '../shared/session.php';

// Check if logged in
if (!is_logged_in('client')) {
    header('Location: login.php');
    exit;
}

// Or use require_login helper
require_login('admin'); // Automatically redirects if not logged in

// Get user info
$userId = get_user_id('client');
$username = get_username('client');

// Flash messages
set_flash_message('Domain created successfully!', 'success');
// On next page:
$flash = get_flash_message();
if ($flash) {
    echo "<div class='alert alert-{$flash['type']}'>{$flash['message']}</div>";
}

// Logout
destroy_session();
```

## 📋 Implementation Checklist

### Phase 1: Database Migration (2-3 days)

#### Step 1: Update cpanel/domains.php
```php
// OLD CODE (VULNERABLE):
$query = "SELECT * FROM domains WHERE client_id='$client_id'";
$result = mysqli_query($conn, $query);

// NEW CODE (SECURE):
require_once '../shared/Database.php';
$db = Database::getInstance();
$domains = $db->fetchAll("SELECT * FROM domains WHERE client_id = ?", [$client_id]);
```

**Files to update**:
- [ ] cpanel/domains.php (all queries)
- [ ] cpanel/databases.php (all queries)
- [ ] cpanel/emails.php (all queries)
- [ ] cpanel/apps.php (all queries)
- [ ] cpanel/backups.php (all queries)
- [ ] whm/accounts.php (all queries)
- [ ] whm/packages.php (all queries)
- [ ] whm/tools.php (all queries)

#### Step 2: Test Database Operations
```bash
# Test each page:
# 1. View listings
# 2. Create new items
# 3. Edit existing items
# 4. Delete items
# 5. Check for SQL errors in logs
```

### Phase 2: CSRF Protection (1-2 days)

#### Step 1: Add CSRF to All Forms
```php
// In every form:
<form method="POST" action="domains.php">
    <?php 
    require_once '../shared/security.php';
    echo csrf_field(); 
    ?>
    <!-- rest of form -->
</form>
```

#### Step 2: Validate CSRF on Form Submission
```php
// At the top of every POST handler:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../shared/security.php';
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    // Process form...
}
```

**Forms to update**:
- [ ] cpanel/domains.php (add domain, delete domain, SSL forms)
- [ ] cpanel/databases.php (create DB, create user, delete forms)
- [ ] cpanel/emails.php (create email, delete email forms)
- [ ] whm/accounts.php (create account, suspend, delete forms)
- [ ] whm/packages.php (create/edit package forms)
- [ ] All other forms in cpanel/ and whm/

### Phase 3: Session Security (1 day)

#### Step 1: Replace session_start()
```php
// OLD CODE:
session_start();

// NEW CODE:
require_once '../shared/session.php';
```

**Files to update**:
- [ ] cpanel/index.php
- [ ] cpanel/login.php
- [ ] cpanel/domains.php
- [ ] cpanel/databases.php
- [ ] cpanel/emails.php
- [ ] cpanel/files.php
- [ ] cpanel/tools.php
- [ ] whm/index.php
- [ ] whm/login.php
- [ ] whm/accounts.php
- [ ] All other PHP files using sessions

#### Step 2: Update Login Pages
```php
// cpanel/login.php
require_once '../shared/session.php';
require_once '../shared/security.php';
require_once '../shared/Database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    // Rate limiting
    if (!check_rate_limit('login:' . $_SERVER['REMOTE_ADDR'], 5, 300)) {
        die('Too many login attempts. Please try again in 5 minutes.');
    }
    
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];
    
    $db = Database::getInstance();
    $client = $db->fetchOne("SELECT * FROM clients WHERE username = ?", [$username]);
    
    if ($client && verify_password($password, $client['password'])) {
        // Regenerate session ID on login
        session_regenerate_id(true);
        
        $_SESSION['client_id'] = $client['id'];
        $_SESSION['username'] = $client['username'];
        
        // Check if password needs rehashing
        if (password_needs_rehash($client['password'])) {
            $newHash = hash_password($password);
            $db->execute("UPDATE clients SET password = ? WHERE id = ?", 
                [$newHash, $client['id']]);
        }
        
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid credentials';
        log_security_event('Failed login attempt', 'warning', ['username' => $username]);
    }
}
```

### Phase 4: Command Injection Prevention (2-3 days)

Update `shm-manage` script with enhanced validation:

```bash
# Add to shm-manage near the top

# Shell escaping function
shell_escape() {
    local input="$1"
    printf '%q' "$input"
}

# Enhanced validation
validate_input() {
    local input="$1"
    local type="$2"
    
    case "$type" in
        username)
            # Strict whitelist
            if ! [[ "$input" =~ ^[a-zA-Z0-9_-]{3,32}$ ]]; then
                log_action "[VALIDATION] Invalid username: $input"
                echo "Invalid username format"
                exit 1
            fi
            # Check for shell metacharacters
            if [[ "$input" =~ [\$\`\!\;\&\|\<\>\(\)\{\}\[\]\\] ]]; then
                log_action "[SECURITY] Shell metacharacters detected: $input"
                echo "Invalid characters in username"
                exit 1
            fi
            ;;
        domain)
            # Strict domain validation
            if ! [[ "$input" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$ ]]; then
                log_action "[VALIDATION] Invalid domain: $input"
                echo "Invalid domain format"
                exit 1
            fi
            ;;
    esac
}
```

## 🧪 Testing Procedures

### 1. SQL Injection Testing
```bash
# Try these malicious inputs:
username: admin' OR '1'='1
username: '; DROP TABLE clients; --
domain: test.com'; DELETE FROM domains; --

# Expected result: All should be safely handled by prepared statements
```

### 2. CSRF Testing
```bash
# Try submitting forms without CSRF token
curl -X POST https://client.vivzon.cloud/cpanel/domains.php \
  -d "domain=test.com" \
  -b "cookies.txt"

# Expected result: 403 Forbidden - CSRF token validation failed
```

### 3. Session Security Testing
```bash
# Test session timeout
# 1. Login
# 2. Wait 1 hour
# 3. Try to access protected page
# Expected: Redirect to login with timeout message

# Test session hijacking protection
# 1. Login from Chrome
# 2. Copy session cookie
# 3. Try to use in Firefox with different User-Agent
# Expected: Session destroyed, redirect to login
```

## 📊 Database Schema Updates

Add security logging tables:

```sql
-- Security logs table
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
    INDEX idx_user (user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Error logs table (for future error handler)
CREATE TABLE IF NOT EXISTS error_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    file VARCHAR(500),
    line INT,
    user VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_user (user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 🚀 Deployment Steps

1. **Backup Everything**
   ```bash
   cd /root
   tar -czf shm-panel-backup-$(date +%Y%m%d).tar.gz shm-panel/
   mysqldump shm_panel > shm-panel-db-backup-$(date +%Y%m%d).sql
   ```

2. **Upload New Files**
   ```bash
   scp shared/Database.php root@vivzon.cloud:/var/www/panel/shared/
   scp shared/security.php root@vivzon.cloud:/var/www/panel/shared/
   scp shared/session.php root@vivzon.cloud:/var/www/panel/shared/
   ```

3. **Update Database Schema**
   ```bash
   mysql shm_panel < security_tables.sql
   ```

4. **Test in Development First**
   - Set up a test subdomain
   - Deploy changes there first
   - Test all functionality
   - Monitor logs for errors

5. **Deploy to Production**
   - Schedule maintenance window
   - Deploy during low-traffic period
   - Monitor error logs closely
   - Have rollback plan ready

## 📝 Post-Implementation

- [ ] Review all error logs
- [ ] Monitor security logs for suspicious activity
- [ ] Test all major features
- [ ] Update documentation
- [ ] Train team on new security practices
- [ ] Schedule security audit in 30 days

## 🆘 Rollback Plan

If issues occur:

```bash
# Restore files
cd /root
tar -xzf shm-panel-backup-YYYYMMDD.tar.gz
cp -r shm-panel/* /var/www/panel/

# Restore database
mysql shm_panel < shm-panel-db-backup-YYYYMMDD.sql

# Restart services
systemctl restart nginx php8.2-fpm
```

## 📞 Support

If you encounter issues during implementation:
1. Check `/var/log/shm-panel-errors.log`
2. Check `/var/log/shm-security.log`
3. Check PHP error logs: `/var/log/php8.2-fpm.log`
4. Review the audit report for detailed explanations

---

**Next Steps**: After completing these critical security fixes, proceed to High Priority items (2FA, Testing, Documentation).
