# SHM Panel - Security Implementation Tasks

## Critical Security Fixes (Week 1-2)

### 1. SQL Injection Prevention
- [/] Create `shared/Database.php` class
- [ ] Update `cpanel/domains.php` queries
- [ ] Update `cpanel/databases.php` queries
- [ ] Update `cpanel/emails.php` queries
- [ ] Update `whm/accounts.php` queries
- [ ] Update `whm/tools.php` queries
- [ ] Test all database operations
- [ ] Remove old mysqli connections

### 2. CSRF Protection
- [/] Create `shared/security.php` with CSRF functions
- [ ] Update all forms in `cpanel/domains.php`
- [ ] Update all forms in `cpanel/databases.php`
- [ ] Update all forms in `cpanel/emails.php`
- [ ] Update all forms in `whm/accounts.php`
- [ ] Update all forms in `whm/packages.php`
- [ ] Update all forms in `whm/tools.php`
- [ ] Test form submissions

### 3. Secure Session Configuration
- [/] Create `shared/session.php`
- [ ] Replace `session_start()` in all cpanel files
- [ ] Replace `session_start()` in all whm files
- [ ] Test login/logout functionality
- [ ] Test session timeout

### 4. Command Injection Prevention
- [ ] Add `shell_escape()` function to `shm-manage`
- [ ] Enhance `validate_input()` function
- [ ] Update `create-account` command
- [ ] Update `add-domain` command
- [ ] Update `mysql-tool` commands
- [ ] Update `dns-tool` commands
- [ ] Test all shm-manage operations

## High Priority (Week 3-4)

### 5. Error Handling
- [ ] Create `shared/ErrorHandler.php`
- [ ] Add `error_logs` table to database
- [ ] Initialize error handler in all entry points
- [ ] Create error display template
- [ ] Test error handling

### 6. Two-Factor Authentication
- [ ] Install `robthree/twofactorauth` via Composer
- [ ] Add TOTP columns to admins table
- [ ] Create 2FA setup page
- [ ] Create 2FA verification page
- [ ] Update login flow
- [ ] Test 2FA enrollment

## Testing & Documentation

### 7. Automated Testing
- [ ] Install PHPUnit
- [ ] Create test directory structure
- [ ] Write unit tests for Database class
- [ ] Write integration tests
- [ ] Set up CI/CD pipeline

### 8. API Documentation
- [ ] Install phpDocumentor
- [ ] Add PHPDoc comments to all functions
- [ ] Generate API documentation
- [ ] Host documentation

## Notes
- All critical fixes should be completed before moving to high priority items
- Test thoroughly in development environment before deploying to production
- Create backups before making changes
