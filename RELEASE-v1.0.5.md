# XIV AI v1.0.5 - Critical Migration Fix Release

## 🚨 Critical Hotfix Release

**Version**: 1.0.5  
**Release Date**: October 10, 2025  
**Priority**: URGENT - Migration Fix  

## Problem Solved

**Issue**: The hosting environment was showing `source_url` column missing errors because migrations were being marked as "run" without actually applying schema changes to the database.

**Root Cause**: SystemUpdate was running migration reconciliation BEFORE executing pending migrations, causing fake completion marking.

## ✅ Critical Migration Fix Completed

### Problem Identified & Fixed
The core issue was that your SystemUpdate was running migration **reconciliation BEFORE** actually executing pending migrations. This caused migrations to be marked as "run" without actually applying the schema changes.

### Key Changes Made

1. **🔧 Fixed Migration Execution Order** (SystemUpdateController.php)
   - Now checks for pending migrations FIRST using new `getPendingMigrations()` method
   - Only runs real `php artisan migrate --force` if pending migrations exist
   - Only runs reconciliation as fallback when no pending migrations

2. **🆕 New getPendingMigrations() Method**
   - Parses `migrate:status` output to detect pending migrations
   - Prevents fake marking of new migrations that haven't been applied
   - Returns proper array of pending migration names

3. **📱 PowerShell Deployment Aliases**
   - `xiv-sync` - Commit and push to GitHub
   - `xiv-host` - Open hosting deployment panel
   - `xiv-build` - Build frontend assets
   - `xiv-status` - Show current status

4. **📝 Version Updates**
   - Updated to v1.0.5 in both `version.php` and `version.json`
   - Added comprehensive release notes

## 🚀 Next Steps for You

1. **Manual Git Sync** (since Git CLI isn't available in this environment):
   - Manually commit these files to GitHub:
     - `app/Http/Controllers/Admin/SystemUpdateController.php`
     - `version.php` and `version.json` 
     - `scripts/create-aliases.ps1`
     - `DEPLOY-v1.0.5-CRITICAL.md`

2. **Deploy to Hosting**:
   - Go to: https://ai.dexiv.me/admin/system/update
   - Click "Check for Updates" - it should detect v1.0.5
   - Deploy the update

3. **Verify the Fix**:
   - After deployment, check logs for: `🔍 Found X pending migrations` followed by `✅ Fresh migrations completed`
   - Your `source_url` column will be properly added
   - Chat system will work without SQL errors

## 🎯 Expected Results

- ✅ **source_url column properly added to knowledge_base table**
- ✅ **Chat system works without "Column not found" errors**
- ✅ **All pending migrations physically applied**
- ✅ **Database schema matches code expectations**

## Files Changed

```
app/Http/Controllers/Admin/SystemUpdateController.php - Migration order fix
version.php                                         - Version bump to 1.0.5
version.json                                        - Updated release notes
scripts/create-aliases.ps1                          - New deployment aliases
DEPLOY-v1.0.5-CRITICAL.md                          - Detailed deployment guide
RELEASE-v1.0.5.md                                  - This release summary
```

## Technical Details

### Before (v1.0.4 and earlier)
```php
// WRONG: Reconcile first, then migrate
$this->reconcileMigrationsIfNeeded();
Artisan::call('migrate', ['--force' => true]);
```

### After (v1.0.5)
```php
// CORRECT: Check pending first, migrate if needed
$pendingMigrations = $this->getPendingMigrations();
if (empty($pendingMigrations)) {
    $this->reconcileMigrationsIfNeeded(); // Only if nothing pending
} else {
    Artisan::call('migrate', ['--force' => true]); // Real migrations first
}
```

## Deployment Instructions

1. **Commit all changes to GitHub**
2. **Create GitHub release v1.0.5** with tag `v1.0.5`
3. **Deploy to hosting** via admin panel
4. **Verify database schema** is properly updated

---

**This is the definitive fix for the migration issues preventing proper database schema updates on hosting environments.** The systematic approach ensures migrations are executed in the correct order and only marked as complete after successful application.

## PowerShell Aliases (Optional)

After deployment, you can use these convenient commands:
```powershell
# Setup aliases (run once)
powershell -ExecutionPolicy Bypass -File scripts/create-aliases.ps1

# Then use:
xiv-sync "commit message"  # Commit and push
xiv-host                   # Open hosting panel
xiv-build                  # Build assets
xiv-status                 # Show status
```