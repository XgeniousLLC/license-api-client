# XgApiClient V2 - Project Integration Guide

## Overview

This guide walks you through integrating the XgApiClient V2 chunked update system into your Laravel project. Once integrated, your application will be able to receive updates using the new reliable, resumable chunked update system.

## Prerequisites

- Laravel 8.x or higher
- Composer installed
- Existing XgApiClient package (or fresh installation)
- Admin panel with update functionality

## Step 1: Install/Update XgApiClient Package

First, ensure you have the latest version of the XgApiClient package that includes V2 support.

### For New Installation

```bash
composer require xgenious/xgapiclient
```

### For Existing Installation (Update)

```bash
composer update xgenious/xgapiclient
```

Verify the package version supports V2:

```bash
composer show xgenious/xgapiclient
```

## Step 2: Publish Package Assets

### Publish Configuration

If you haven't already published the config file, do so now:

```bash
php artisan vendor:publish --tag=xgapiclient-config
```

### Publish V2 JavaScript Assets

**This is required for V2 functionality:**

```bash
php artisan vendor:publish --tag=xgapiclient-assets
```

This publishes `UpdateManager.js` to `assets/vendor/xgapiclient/js/`.

### Publish Views (Optional)

If you want to customize the update UI:

```bash
php artisan vendor:publish --tag=xgapiclient-views
```


## Step 3: Create Update Storage Directory

Ensure the update storage directory exists and is writable:

```bash
mkdir -p storage/app/xg-update
chmod 755 storage/app/xg-update
```

## Step 4: Configure Environment Variables

Add the following to your `.env` file:

```env
# License Server Configuration
XG_LICENSE_API_URL=https://license.xgenious.com
XG_PRODUCT_TOKEN=your-unique-product-token

# V2 Update System Settings (optional, defaults shown)
XG_UPDATE_CHUNK_SIZE=10485760        # 10MB chunk size
XG_UPDATE_DOWNLOAD_TIMEOUT=300       # 5 minutes per chunk
XG_UPDATE_EXTRACTION_BATCH=100       # Files per extraction batch
XG_UPDATE_REPLACEMENT_BATCH=50       # Files per replacement batch
XG_UPDATE_ENABLE_BACKUP=true         # Backup files before replacing
XG_UPDATE_SMART_VENDOR=true          # Smart vendor package replacement
XG_UPDATE_MAX_RETRIES=3              # Retry attempts for failed chunks
```

## Step 5: Add Route to Admin Panel

### Option A: Add New Route (Recommended)

Add this route to your admin routes file (typically `routes/admin.php` or a custom admin routes file):

```php
use App\Http\Controllers\Admin\GeneralSettingsController;

Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'admin']], function () {
    // ... your existing admin routes ...
    
    // V2 Chunked Update System
    Route::get('/update-v2', [GeneralSettingsController::class, 'updateV2Page'])
        ->name('admin.general.update.v2')
        ->permission('software-update-settings'); // Adjust permission as needed
});
```

### Option B: Add to Existing Route Group

If you already have an admin route group, simply add:

```php
// V2 Chunked Update System
Route::get('/update-v2', [GeneralSettingsController::class, 'updateV2Page'])
    ->name('admin.general.update.v2')
    ->permission('software-update-settings');
```

> **Note**: Adjust the permission middleware according to your application's permission system. If you don't use permissions, you can remove the `->permission()` part.

## Step 6: Add Controller Method

Add this method to your `GeneralSettingsController` (or wherever you handle admin settings):

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class GeneralSettingsController extends Controller
{
    // ... your existing methods ...
    
    /**
     * Display the V2 Chunked Update System page
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function updateV2Page(Request $request)
    {
        $statusFile = config('xgapiclient.update.status_file', storage_path('app/xg-update/.update-status.json'));
        
        // Check if there's an existing update in progress
        $existingStatus = null;
        $canResume = false;
        
        if (file_exists($statusFile)) {
            $statusContent = @file_get_contents($statusFile);
            if ($statusContent) {
                $existingStatus = json_decode($statusContent, true);
                $canResume = $existingStatus && 
                            isset($existingStatus['phase']) && 
                            $existingStatus['phase'] !== 'completed';
            }
        }
        
        // Get current version from license file or static option
        // Adjust this based on how your application stores version info
        $currentVersion = get_static_option('site_script_version');
        
        return view('XgApiClient::v2.update', [
            'existingStatus' => $existingStatus,
            'canResume' => $canResume,
            'currentVersion' => $currentVersion,
        ]);
    }
}
```

> **Important**: Adjust the `$currentVersion` retrieval based on your application's method of storing version information. Common alternatives:
> - Database query
> - Reading from a version file

## Step 7: Update Maintenance Mode Middleware

To allow the update system to function during maintenance mode, update your `PreventRequestsDuringMaintenance` middleware:

**File**: `app/Http/Middleware/PreventRequestsDuringMaintenance.php`

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * The URIs that should be reachable while maintenance mode is enabled.
     *
     * @var array<int, string>
     */
    protected $except = [
        'admin/general-settings/*',  // Your admin settings routes
        'admin/update-v2',            // V2 update page
        'update/v2/*',                // All V2 update API endpoints
    ];
}
```

> **Note**: Adjust the admin path (`admin/general-settings/*` and `admin/update-v2`) based on your actual admin route structure.

## Step 8: Add Navigation Link (Optional)

Add a link to the V2 update page in your admin navigation menu:

```blade
<!-- Example for Blade template -->
<li class="nav-item">
    <a href="{{ route('admin.general.update.v2') }}" class="nav-link">
        <i class="fas fa-sync-alt"></i>
        <span>System Update (V2)</span>
    </a>
</li>
```

## Step 9: Test the Integration

### 1. Access the Update Page

Navigate to your V2 update page:
```
https://yourdomain.com/admin/update-v2
```

### 2. Check for Updates

Click the "Check for Updates" button to verify the connection to the license server.

### 3. Test Update Process (Optional)

If an update is available, you can test the update process in a staging environment first.

## Verification Checklist

Before deploying to production, verify:

- [ ] Package is installed and up to date
- [ ] Config file is published and configured
- [ ] JavaScript assets are published to `assets/vendor/xgapiclient/js/`
- [ ] Storage directory `storage/app/xg-update` exists and is writable
- [ ] Environment variables are set in `.env`
- [ ] Route is added and accessible
- [ ] Controller method is implemented
- [ ] Maintenance mode middleware is updated
- [ ] Navigation link is added (if applicable)
- [ ] Update page loads without errors
- [ ] "Check for Updates" functionality works

## Migration from V1 to V2

If you're currently using the V1 update system:

1. **Both systems can coexist**: V1 and V2 routes are separate and don't conflict
2. **No breaking changes**: Your existing V1 update functionality will continue to work
3. **Gradual migration**: You can keep V1 as a fallback while testing V2
4. **User choice**: Optionally, you can provide both options in your admin panel

### Recommended Approach

1. Implement V2 alongside V1
2. Test V2 thoroughly in staging
3. Use V2 for new updates
4. Keep V1 as a backup option
5. Eventually deprecate V1 once V2 is proven stable

## Troubleshooting

### Update Page Not Loading

**Issue**: 404 error when accessing `/admin/update-v2`

**Solution**: 
- Clear route cache: `php artisan route:clear`
- Verify route is registered: `php artisan route:list | grep update-v2`

### JavaScript Not Loading

**Issue**: UpdateManager.js not found (404)

**Solution**:
```bash
php artisan vendor:publish --tag=xgapiclient-assets --force
```

### Permission Denied on Storage

**Issue**: Cannot write to `storage/app/xg-update`

**Solution**:
```bash
chmod -R 755 storage/app/xg-update
chown -R www-data:www-data storage/app/xg-update  # Adjust user as needed
```

### Maintenance Mode Blocking Updates

**Issue**: Update endpoints return 503 during maintenance

**Solution**: Verify `PreventRequestsDuringMaintenance` middleware includes `update/v2/*`

### Config Not Loading

**Issue**: Config values not being read

**Solution**:
```bash
php artisan config:clear
php artisan config:cache
```

## Production Deployment

### Pre-Deployment Checklist

- [ ] Test complete update flow in staging environment
- [ ] Backup database before first V2 update
- [ ] Backup application files
- [ ] Verify storage permissions on production server
- [ ] Test maintenance mode exceptions
- [ ] Document rollback procedure


## Future Updates

Once V2 is integrated into your current version:

1. **All future updates** can use the V2 system
2. **Users updating from your current version** will automatically have V2 available
3. **No additional integration needed** for subsequent updates
4. **Seamless update experience** for end users

## Support & Resources

- **Package Documentation**: [V2-IMPLEMENTATION-GUIDE.md](./V2-IMPLEMENTATION-GUIDE.md)
- **API Reference**: See V2-IMPLEMENTATION-GUIDE.md for complete API endpoint documentation

## Credits

Implementation guide by [Rakibul Hasan](https://github.com/rakib01)

---

**Congratulations!** 🎉 Your project is now ready to use the V2 chunked update system. Future updates will be more reliable, resumable, and handle large files without timeout issues.
