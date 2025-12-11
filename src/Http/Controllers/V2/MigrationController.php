<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\V2\UpdateStatusManager;

class MigrationController extends Controller
{
    protected UpdateStatusManager $statusManager;

    public function __construct()
    {
        $this->statusManager = new UpdateStatusManager();
    }

    /**
     * Run database migrations
     */
    public function migrate(Request $request): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'No active update',
            ], 400);
        }

        // Check if replacement is complete
        if ($status['replacement']['status'] !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'File replacement not complete. Please complete replacement first.',
            ], 400);
        }

        $isTenant = $request->boolean('is_tenant', false);

        $this->statusManager->update(['phase' => 'migration']);
        $this->statusManager->updatePhase('migration', ['status' => 'in_progress']);
        $this->statusManager->addLog('Starting database migration...');

        try {
            // Set environment to local temporarily
            XGsetEnvValue(['APP_ENV' => 'local']);

            // Run migrations based on tenant status
            if ($isTenant) {
                $this->runTenantMigrations();
            } else {
                $this->runStandardMigrations();
            }

            // Clear caches
            $this->clearCaches();

            // Set environment back to production
            XGsetEnvValue(['APP_ENV' => 'production']);

            $this->statusManager->updatePhase('migration', ['status' => 'completed']);
            $this->statusManager->addLog('Database migration completed successfully');

            return response()->json([
                'success' => true,
                'message' => 'Database migration completed',
            ]);

        } catch (\Exception $e) {
            Log::error('Migration failed', ['error' => $e->getMessage()]);
            $this->statusManager->recordError('migration_failed', $e->getMessage());

            // Try to restore production environment
            try {
                XGsetEnvValue(['APP_ENV' => 'production']);
            } catch (\Exception $envError) {
                Log::warning('Failed to restore APP_ENV', ['error' => $envError->getMessage()]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Migration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Run standard migrations
     */
    protected function runStandardMigrations(): void
    {
        $this->statusManager->addLog('Running standard migrations...');

        Artisan::call('migrate', ['--force' => true]);
        $this->statusManager->addLog('Migrations completed');

        Artisan::call('db:seed', ['--force' => true]);
        $this->statusManager->addLog('Database seeding completed');
    }

    /**
     * Run tenant migrations
     */
    protected function runTenantMigrations(): void
    {
        $this->statusManager->addLog('Running tenant migrations...');

        // Standard migrations first
        Artisan::call('migrate', ['--force' => true]);
        $this->statusManager->addLog('Main migrations completed');

        Artisan::call('db:seed', ['--force' => true]);
        $this->statusManager->addLog('Main database seeding completed');

        // Tenant-specific migrations
        try {
            Artisan::call('tenants:migrate', ['--force' => true]);
            $this->statusManager->addLog('Tenant migrations completed');
        } catch (\Exception $e) {
            Log::warning('Tenant migration warning', ['error' => $e->getMessage()]);
            $this->statusManager->addLog('Tenant migration: ' . $e->getMessage());
        }
    }

    /**
     * Clear application caches
     */
    protected function clearCaches(): void
    {
        $this->statusManager->addLog('Clearing caches...');

        $cacheCommands = [
            'cache:clear' => 'Application cache cleared',
            'config:clear' => 'Config cache cleared',
            'route:clear' => 'Route cache cleared',
            'view:clear' => 'View cache cleared',
        ];

        foreach ($cacheCommands as $command => $message) {
            try {
                Artisan::call($command);
                $this->statusManager->addLog($message);
            } catch (\Exception $e) {
                Log::warning("Failed to run {$command}", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Complete the update process
     */
    public function complete(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'No active update',
            ], 400);
        }

        // Update version in license info and database
        $targetVersion = $status['version']['target'] ?? null;
        if ($targetVersion) {
            try {
                // Update the item_version in the license file
                $this->updateVersionInLicenseFile($targetVersion);
                $this->statusManager->addLog("Version updated to {$targetVersion} in license file");

                // Update database version
                $this->updateDatabaseVersion($targetVersion);
                $this->statusManager->addLog("Database version updated to {$targetVersion}");
            } catch (\Exception $e) {
                Log::warning('Failed to update version info', ['error' => $e->getMessage()]);
                $this->statusManager->addLog("Warning: Failed to update version info: " . $e->getMessage());
            }
        }

        // Mark as complete
        $this->statusManager->update([
            'phase' => 'completed',
            'completed_at' => now()->toIso8601String(),
        ]);

        $this->statusManager->addLog('Update completed successfully!');

        // Cleanup
        $cleanupResult = $this->statusManager->cleanup();

        return response()->json([
            'success' => true,
            'message' => 'Update completed successfully',
            'version' => $targetVersion,
            'cleanup' => $cleanupResult,
        ]);
    }

    /**
     * Update version in license file
     */
    protected function updateVersionInLicenseFile(string $newVersion): void
    {
        // Try common license file locations
        $possiblePaths = [
            base_path('xg-ftp-info.json'),
            storage_path('app/xg-ftp-info.json'),
        ];

        $updated = false;

        // First try to update existing file
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $content = json_decode(file_get_contents($path), true);
                if ($content) {
                    $content['item_version'] = $newVersion;
                    file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT));
                    $updated = true;
                    // Keep looking to update all copies if multiple exist
                }
            }
        }

        // If no file was updated, create one in root
        if (!$updated) {
            $path = base_path('xg-ftp-info.json');
            $content = [
                'item_version' => $newVersion,
                'created_at' => now()->toIso8601String(),
                'updated_by' => 'XgApiClient V2',
            ];
            file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Update version in database
     */
    protected function updateDatabaseVersion(string $newVersion): void
    {
        try {
            \Illuminate\Support\Facades\DB::table('static_options')
                ->updateOrInsert(
                    ['option_name' => 'site_script_version'],
                    ['option_value' => $newVersion]
                );
        } catch (\Exception $e) {
            Log::error('Failed to update database version', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get migration status
     */
    public function status(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'No active update',
            ], 400);
        }

        $migration = $status['migration'] ?? [];

        return response()->json([
            'success' => true,
            'status' => $migration['status'] ?? 'pending',
            'phase' => $status['phase'],
            'can_run' => $status['replacement']['status'] === 'completed',
        ]);
    }

    /**
     * Run only cache clearing
     */
    public function clearCachesOnly(): JsonResponse
    {
        try {
            $this->clearCaches();

            return response()->json([
                'success' => true,
                'message' => 'Caches cleared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear caches: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Finalize and cleanup after update
     */
    public function finalize(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => true,
                'message' => 'No update to finalize',
            ]);
        }

        // Cleanup temporary files
        $cleanupResult = $this->statusManager->cleanup();

        // Reset status file
        $this->statusManager->reset();

        return response()->json([
            'success' => true,
            'message' => 'Update finalized and cleaned up',
            'cleanup' => $cleanupResult,
        ]);
    }
}
