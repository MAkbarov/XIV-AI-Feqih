# XIV AI v1.0.5 - CRITICAL MIGRATION FIX DEPLOYMENT

## 🚨 URGENT DEPLOYMENT REQUIRED

This release fixes the critical migration execution order issue that prevents the `source_url` column from being properly added to the database on hosting environments.

## What Was Fixed

**Problem**: Version 1.0.4 and earlier would run migration reconciliation BEFORE actually executing pending migrations. This caused the system to mark migrations as "run" without actually applying them to the database schema.

**Solution**: Version 1.0.5 now:
1. ✅ **Checks for pending migrations first** using `getPendingMigrations()` method
2. ✅ **Runs real migrations ONLY if pending migrations exist**
3. ✅ **Only runs reconciliation if no pending migrations** (safe fallback)
4. ✅ **Prevents fake marking of new migrations**

## Key Changes Made

### 1. SystemUpdateController.php (lines 561-581)
```php
// NEW: Smart migration order
$pendingMigrations = $this->getPendingMigrations();

if (empty($pendingMigrations)) {
    // Only reconcile if nothing pending
    $this->reconcileMigrationsIfNeeded();
} else {
    // Run real migrations first
    Artisan::call('migrate', ['--force' => true]);
}
```

### 2. New Method: getPendingMigrations() (lines 1189-1220)
```php
private function getPendingMigrations(): array
{
    // Parses `php artisan migrate:status` output
    // Returns array of pending migration names
}
```

### 3. Version Updated
- `version.php`: 1.0.4 → 1.0.5
- `version.json`: Updated with fix notes

## Deployment Steps

### 1. Commit and Push (Manual if no Git CLI)
```
Files changed:
- app/Http/Controllers/Admin/SystemUpdateController.php
- version.php 
- version.json
- scripts/create-aliases.ps1 (new)
- DEPLOY-v1.0.5-CRITICAL.md (new)

Commit message: "v1.0.5 - CRITICAL FIX: Migration execution order corrected"
```

### 2. Deploy to Hosting
1. Go to: https://ai.dexiv.me/admin/system/update
2. Click "Check for Updates"
3. Deploy version 1.0.5
4. **The system will now properly run pending migrations**

### 3. Verify Fix
After deployment, check the hosting logs for:
```
🔍 Found 2 pending migrations
🗄️ Found 2 pending migrations, running fresh migrations...
✅ Fresh migrations completed
```

Instead of the old behavior:
```
ℹ️ No pending migrations, running reconciliation...
(migrations fake-marked as run)
```

## Expected Results

✅ **source_url column will be properly added to knowledge_base table**
✅ **Chat system will work without "Column not found: source_url" errors** 
✅ **All pending migrations will be physically applied**
✅ **Database schema will match code expectations**

## Aliases Setup (Optional)

The new PowerShell aliases are available:
```powershell
xiv-sync   # Commit and push to GitHub
xiv-host   # Open hosting deployment panel
xiv-build  # Build frontend assets
xiv-status # Show current status
```

Run: `powershell -ExecutionPolicy Bypass -File scripts/create-aliases.ps1`

## Verification Commands (On Hosting After Deployment)

```bash
cd /www/wwwroot/ai.dexiv.me
php artisan migrate:status
# Should show all migrations as "Ran"

# Check database schema
php artisan tinker
>>> Schema::hasColumn('knowledge_base', 'source_url')
# Should return: true
```

---

**This is the definitive fix for the migration issues preventing proper database schema updates on hosting environments.**