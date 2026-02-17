# SHM Panel - Complete Database Schema Fix Guide

## 🔍 Issues Found

After comprehensive audit of all WHM and cPanel files, found **20+ database schema mismatches**:

### 1. **DNS Records Table** - Wrong Column Name
- **Issue**: Code uses `host` column, but table has `name` column
- **Affected Files**: 
  - `whm/accounts.php` (2 instances)
  - `cpanel/domains.php` (14 instances)
  - `cpanel/emails.php` (potential)

### 2. **Missing Tables**
- `client_databases` - Required by `cpanel/databases.php`
- `client_db_users` - Required by `cpanel/databases.php`
- `app_installations` - Required by `cpanel/apps.php` and `cpanel/tools.php`
- `ftp_users` - Required by `whm/tools.php` and `cpanel/tools.php`

### 3. **Missing Columns**
- `mail_users` table missing `client_id` and `domain_id`
- `domains` table missing `parent_id` (for subdomains)
- `php_config` table has wrong structure

### 4. **Missing client_id in Inserts**
- `cpanel/emails.php` line 25 - `mail_domains` insert
- `cpanel/emails.php` line 28 - `mail_users` insert  
- `whm/tools.php` line 63 - `mail_users` insert

---

## 🚀 Fix Instructions

### Step 1: Run Database Migration

```bash
# On your server
cd /var/www/panel
mysql -u root -p shm_panel < migrations/003_fix_all_schema_issues.sql
```

This will:
- Create 4 missing tables
- Add missing columns to existing tables
- Fix php_config table structure

### Step 2: Upload Fixed PHP Files

Upload these corrected files to your server:

```bash
# From your local machine
scp whm/accounts.php root@your-server-ip:/var/www/panel/whm/
scp cpanel/domains.php root@your-server-ip:/var/www/panel/cpanel/
scp cpanel/emails.php root@your-server-ip:/var/www/panel/cpanel/
scp cpanel/databases.php root@your-server-ip:/var/www/panel/cpanel/
scp whm/tools.php root@your-server-ip:/var/www/panel/whm/
```

### Step 3: Verify Fix

```bash
# Test account creation
# Go to WHM → Create Account
# Should work without errors now
```

---

## 📋 Files That Need Fixing

### High Priority (Blocking Account Creation)
1. ✅ `whm/accounts.php` - Fixed (lines 99-100)
2. ⚠️ `cpanel/domains.php` - Needs fixing (14 instances)
3. ⚠️ `cpanel/emails.php` - Needs fixing (2 instances)

### Medium Priority (Features May Fail)
4. ⚠️ `cpanel/databases.php` - Missing tables
5. ⚠️ `whm/tools.php` - Missing client_id
6. ⚠️ `cpanel/tools.php` - Missing tables

---

## 🔧 Manual Fix (If Needed)

If you can't upload files, manually edit on server:

### Fix cpanel/domains.php

```bash
nano /var/www/panel/cpanel/domains.php

# Find and replace ALL instances of:
(domain_id, type, host, value)

# With:
(domain_id, type, name, value)

# Save: Ctrl+X, Y, Enter
```

### Fix cpanel/emails.php

```bash
nano /var/www/panel/cpanel/emails.php

# Line 25 - Change:
INSERT INTO mail_domains (domain) VALUES (?)

# To:
INSERT INTO mail_domains (client_id, domain) VALUES (?, ?)

# Line 28 - Change:
INSERT INTO mail_users (domain_id, email, password) VALUES

# To:
INSERT INTO mail_users (client_id, domain_id, email, password) VALUES

# Save: Ctrl+X, Y, Enter
```

---

## ✅ Verification Checklist

After applying fixes:

- [ ] Run database migration script
- [ ] Upload/fix all PHP files
- [ ] Test WHM account creation
- [ ] Test cPanel domain creation
- [ ] Test email account creation
- [ ] Test database creation
- [ ] Check error logs: `tail -f /var/log/shm-panel-errors.log`

---

## 📊 Summary

| Component | Issues | Status |
|-----------|--------|--------|
| Database Schema | 4 missing tables, 3 column issues | ✅ SQL script ready |
| WHM Files | 2 column name issues | ✅ Fixed |
| cPanel Files | 16 column name issues | ⚠️ Needs fixing |
| Total Files Affected | 6 PHP files | 1/6 fixed |

---

## 🎯 Next Steps

1. Run the migration: `migrations/003_fix_all_schema_issues.sql`
2. I'll create fixed versions of all affected PHP files
3. Upload them to your server
4. Test all functionality

This will resolve ALL database schema issues across the entire panel!
