# Quick Start Guide - Security Implementation

This is a condensed guide to get you started quickly with implementing the security fixes.

## 🚀 Quick Deploy (5 minutes)

### Step 1: Deploy Files to Server
```bash
# From your local shm-panel directory
chmod +x deploy_security.sh
./deploy_security.sh
```

This will:
- ✅ Create backup of current system
- ✅ Upload security files
- ✅ Run database migrations
- ✅ Set proper permissions
- ✅ Verify installation

### Step 2: Test Security Implementation
```bash
# SSH into your server
ssh root@vivzon.cloud

# Create test directory
mkdir -p /var/www/panel/tests
```

Upload `tests/security_test.php` to server, then visit:
`https://client.vivzon.cloud/tests/security_test.php`

You should see all tests passing ✅

### Step 3: Update One File (Example)
Let's update `cpanel/login.php` as a proof of concept:

```bash
# Backup original
cp /var/www/panel/cpanel/login.php /var/www/panel/cpanel/login.php.backup

# Copy example
cp examples/secure_login_example.php /var/www/panel/cpanel/login.php
```

Test login at: `https://client.vivzon.cloud/cpanel/login.php`

## 📋 What Changed?

### Before (Vulnerable):
```php
session_start();
$query = "SELECT * FROM clients WHERE username='$username'";
$result = mysqli_query($conn, $query);
```

### After (Secure):
```php
require_once '../shared/session.php';  // Secure sessions
require_once '../shared/security.php'; // CSRF protection
require_once '../shared/Database.php'; // Prepared statements

verify_csrf_token($_POST['csrf_token']);
$db = Database::getInstance();
$client = $db->fetchOne("SELECT * FROM clients WHERE username = ?", [$username]);
```

## 🔄 Update Workflow

For each PHP file:

1. **Add security includes** at the top
2. **Add CSRF field** to all forms
3. **Verify CSRF** on POST requests
4. **Replace queries** with prepared statements
5. **Test thoroughly**

## 📁 Files Priority Order

Update in this order for maximum security impact:

1. ✅ **cpanel/login.php** (already done in example)
2. **whm/login.php** (same pattern as cpanel)
3. **cpanel/domains.php** (use secure_domains_example.php)
4. **cpanel/databases.php**
5. **cpanel/emails.php**
6. **whm/accounts.php**
7. All other files

## 🧪 Testing Checklist

After updating each file:
- [ ] Page loads without errors
- [ ] Forms submit successfully
- [ ] CSRF protection works (try submitting without token)
- [ ] Data displays correctly
- [ ] No SQL errors in logs

## 📊 Monitor Logs

```bash
# Watch security log
tail -f /var/log/shm-security.log

# Watch error log
tail -f /var/log/shm-panel-errors.log

# Watch PHP errors
tail -f /var/log/php8.2-fpm.log
```

## 🆘 Rollback if Needed

```bash
# Restore from backup
cd /root
tar -xzf shm-panel-backup-YYYYMMDD-HHMMSS.tar.gz
cp -r var/www/panel/* /var/www/panel/

# Restart services
systemctl restart nginx php8.2-fpm
```

## ✅ Success Criteria

You'll know it's working when:
- ✅ All tests pass in security_test.php
- ✅ Login works with CSRF protection
- ✅ No SQL injection possible
- ✅ Sessions are secure
- ✅ All forms require CSRF tokens

## 📞 Next Steps

1. **Complete all file updates** (use examples as templates)
2. **Remove test files** from production
3. **Enable 2FA** (see SECURITY_IMPLEMENTATION.md)
4. **Set up monitoring** (security logs)
5. **Schedule regular audits**

## 💡 Pro Tips

- Work on one file at a time
- Test after each change
- Keep backups of originals
- Use examples as templates
- Check logs frequently

---

**Estimated Time**: 
- Initial deployment: 5 minutes
- Per file update: 15-30 minutes
- Total for all files: 4-6 hours

**Need Help?** See full guide: `SECURITY_IMPLEMENTATION.md`
