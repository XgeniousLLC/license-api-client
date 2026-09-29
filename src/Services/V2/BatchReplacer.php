<?php

namespace Xgenious\XgApiClient\Services\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BatchReplacer
{
    /**
     * Path segment used both to enable maintenance mode's bypass and, via
     * getMaintenanceBypassUrl(), to let the client pre-authorize itself
     * before it makes another request while maintenance mode is active.
     */
    protected const MAINTENANCE_BYPASS_SECRET = 'xg-update-in-progress';

    protected UpdateStatusManager $statusManager;
    protected ComposerDiffHandler $composerDiff;
    protected array $fileList = [];
    protected bool $fileListBuilt = false;
    protected array $skipFiles = [];
    protected array $skipDirectories = [];
    protected array $skipFilesWithPath = [];
    protected bool $composerAnalyzed = false;
    protected $shouldReplaceCallback = null;

    public function __construct(UpdateStatusManager $statusManager)
    {
        $this->statusManager = $statusManager;
        $this->composerDiff = new ComposerDiffHandler($statusManager);
        $this->loadSkipLists();
    }

    /**
     * Load skip lists from status
     */
    protected function loadSkipLists(): void
    {
        $status = $this->statusManager->getStatus();

        if ($status) {
            $this->skipFiles = array_filter(array_map('trim', explode(',', $status['skip_files'] ?? '')));
            $this->skipDirectories = array_filter(array_map('trim', explode(',', $status['skip_directories'] ?? '')));

            if (isset($status['composer_analysis'])) {
                $this->composerAnalyzed = true;
            }
        }

        // Add default skip files if not already present
        $defaultSkipFiles = ['.env', '.htaccess', 'dynamic-style.css', 'dynamic-script.js', '.DS_Store'];
        $this->skipFiles = array_unique(array_merge($this->skipFiles, $defaultSkipFiles));

        // Add default skip directories if not already present
        $defaultSkipDirs = ['lang', 'custom-fonts', '.git', '.idea', '.vscode', '.fleet', 'node_modules'];
        $this->skipDirectories = array_unique(array_merge($this->skipDirectories, $defaultSkipDirs));
    }

    public function analyzeComposerChanges(string $extractedPath): array
    {
        $realRoot = $this->detectUpdateRoot($extractedPath);
        $result = $this->composerDiff->analyze($realRoot);
        $this->composerAnalyzed = true;

        $this->statusManager->update([
            'composer_analysis' => $result,
        ]);

        return $result;
    }

    public function replaceBatch(int $batchNumber, ?int $batchSize = null): array
    {
        $batchSize = $batchSize ?? $this->resolveBatchSize();
        $paths = $this->statusManager->getPaths();

        try {
            $realRoot = $this->detectUpdateRoot($paths['extracted']);

            if (!$this->fileListBuilt) {
                $this->buildFileList($paths['extracted']);
            }

            if ($batchNumber === 0 && !$this->composerAnalyzed) {
                $composerAnalysis = $this->analyzeComposerChanges($paths['extracted']);

                if ($composerAnalysis['has_changes']) {
                    $this->composerDiff->removeObsoletePackages();
                }
            }

            if ($batchNumber === 0) {
                $this->enableMaintenanceMode();
            }

            $totalFiles = count($this->fileList);
            $startIndex = $batchNumber * $batchSize;
            $endIndex = min($startIndex + $batchSize, $totalFiles);

            // Check if we've already processed all files
            if ($startIndex >= $totalFiles) {
                return [
                    'success' => true,
                    'replaced' => 0,
                    'skipped' => 0,
                    'replaced_total' => $totalFiles,
                    'total_files' => $totalFiles,
                    'has_more' => false,
                    'percent' => 100,
                ];
            }

            $replacedInBatch = 0;
            $skippedInBatch = 0;
            $errors = [];

            $realRoot = $this->detectUpdateRoot($paths['extracted']);

            for ($i = $startIndex; $i < $endIndex; $i++) {
                $relativePath = $this->fileList[$i];

                $sourcePath = $realRoot . '/' . $relativePath;

                // Check if should skip
                if ($this->shouldSkip($relativePath)) {
                    $skippedInBatch++;
                    continue;
                }

                // vendor package handling
                if (str_starts_with($relativePath, 'vendor/')) {
                    // Always skip XgApiClient package
                    if (str_starts_with($relativePath, 'vendor/xgenious/xgapiclient/')) {
                        $skippedInBatch++;
                        continue;
                    }

                    // Use filtering for other vendor files
                    // Only replaces: new packages, updated packages, or missing files
                    // Skips: unchanged packages that already exist
                    if (!$this->composerDiff->shouldReplaceVendorPath($relativePath)) {
                        $skippedInBatch++;
                        continue;
                    }
                }

                if ($this->shouldReplaceCallback && is_callable($this->shouldReplaceCallback)) {
                    if (!call_user_func($this->shouldReplaceCallback, $relativePath)) {
                        $skippedInBatch++;
                        continue;
                    }
                }

                try {
                    $destPath = $this->getDestinationPath($relativePath);
                } catch (\Throwable $e) {
                    Log::error("getDestinationPath exception for {$relativePath}: " . $e->getMessage());
                    $destPath = null;
                }

                if (!$destPath) {
                    $skippedInBatch++;
                    Log::warning("Destination path is null for: {$relativePath}");
                    continue;
                }

                try {
                    // Ensure destination directory exists
                    $destDir = dirname($destPath);
                    if (!File::isDirectory($destDir)) {
                        File::makeDirectory($destDir, 0755, true);
                    }

                    // Backup original file if enabled and exists
                    if (Config::get('xgapiclient.update.enable_backup', false) && File::exists($destPath)) {
                        $this->backupFile($destPath, $relativePath);
                    }

                    if (File::exists($sourcePath)) {

                        if (function_exists('opcache_invalidate') && str_ends_with($destPath, '.php')) {
                            @opcache_invalidate($destPath, true);
                        }

                        clearstatcache(true, $destPath);

                        $copyResult = File::copy($sourcePath, $destPath);

                        if ($copyResult) {
                            clearstatcache(true, $destPath);
                            if (function_exists('opcache_invalidate') && str_ends_with($destPath, '.php')) {
                                @opcache_invalidate($destPath, true);
                            }

                            $replacedInBatch++;
                        } else {
                            $errors[] = $relativePath;
                            Log::error("File::copy returned false for: {$relativePath}");
                        }
                    } else {
                        $errors[] = $relativePath;
                        Log::error("Source file not found: {$sourcePath}");
                    }
                } catch (\Throwable $e) {
                    $errors[] = $relativePath;
                    Log::error("Failed to replace file: {$relativePath}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // Calculate progress
            $status = $this->statusManager->getStatus();
            $previousReplaced = $status['replacement']['replaced_files'] ?? 0;
            $previousSkipped = $status['replacement']['skipped_files'] ?? 0;

            $totalReplaced = $previousReplaced + $replacedInBatch;
            $totalSkipped = $previousSkipped + $skippedInBatch;
            $percent = $totalFiles > 0 ? round(($endIndex / $totalFiles) * 100) : 100;

            // Update status
            $this->statusManager->updatePhase('replacement', [
                'status' => 'in_progress',
                'replaced_files' => $totalReplaced,
                'skipped_files' => $totalSkipped,
                'current_batch' => $batchNumber,
                'percent' => $percent,
                'current_file' => $relativePath ?? null,
            ]);

            // Log progress every 5 batches or on last batch
            if ($batchNumber % 5 === 0 || !($endIndex < $totalFiles)) {
                $this->statusManager->addLog("Replaced {$totalReplaced} files, skipped {$totalSkipped} ({$percent}%)");
            }

            return [
                'success' => true,
                'replaced' => $replacedInBatch,
                'skipped' => $skippedInBatch,
                'replaced_total' => $totalReplaced,
                'skipped_total' => $totalSkipped,
                'total_files' => $totalFiles,
                'has_more' => $endIndex < $totalFiles,
                'percent' => $percent,
                'next_batch' => $batchNumber + 1,
                'errors' => $errors,
                'composer_update_required' => $this->composerDiff->requiresComposerUpdate(),
                'maintenance_bypass_url' => $batchNumber === 0 ? $this->getMaintenanceBypassUrl() : null,
            ];

        } catch (\Throwable $e) {
            Log::error("Replacement failed", [
                'batch' => $batchNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->statusManager->recordError('replacement_failed', $e->getMessage(), [
                'batch' => $batchNumber,
            ]);

            // A failed batch must never leave the public site stuck behind
            // a maintenance page - restore access immediately rather than
            // waiting on a client that may never come back.
            // (recordError() already attempts this too - this is a second,
            // explicit guarantee at the site of the failure.)
            $this->disableMaintenanceMode();

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'batch' => $batchNumber,
            ];
        }
    }

    /**
     * Build list of files to replace from extracted directory
     *
     * The list is built once per update run and cached to disk: without
     * this, every batch request re-scans tens of thousands of extracted
     * files, and that fixed per-request cost alone can push a batch past
     * the host's request timeout (killed process = no log, client sees
     * "Failed to fetch"). The cache is keyed by update_id, so a new
     * update always rebuilds it.
     */
    protected function buildFileList(string $extractedPath): void
    {
        if ($this->loadCachedFileList()) {
            $this->fileListBuilt = true;
            $this->refreshFileListTotals();
            return;
        }

        $this->fileList = [];

        if (!File::isDirectory($extractedPath)) {
            $this->fileListBuilt = true;
            return;
        }

        // Detect actual root of the update
        $realRoot = $this->detectUpdateRoot($extractedPath);
        
        // Log the detected root for debugging
        if ($realRoot !== $extractedPath) {
            $this->statusManager->addLog("Detected nested update structure. Root: " . basename($realRoot));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            // Calculate relative path from the REAL root
            $relativePath = str_replace($realRoot . '/', '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath); // Normalize for Windows
            $this->fileList[] = $relativePath;
        }

        $this->fileListBuilt = true;

        $this->saveCachedFileList();
        $this->refreshFileListTotals();
    }

    /**
     * Update status with file totals (also re-applied when the list is
     * restored from cache, so resume always reports correct totals).
     */
    protected function refreshFileListTotals(): void
    {
        // Update status with totals
        $batchSize = $this->resolveBatchSize();
        $totalBatches = (int) ceil(count($this->fileList) / $batchSize);

        $this->statusManager->updatePhase('replacement', [
            'total_files' => count($this->fileList),
            'total_batches' => $totalBatches,
        ]);
    }

    protected function fileListCachePath(): string
    {
        return $this->statusManager->getPaths()['base'] . '/.replacement-file-list.json';
    }

    /**
     * Restore the file list built by an earlier batch of the same update.
     * Returns false when there is no usable cache (new update, cache
     * wiped, or corrupt) so the caller falls back to a full scan.
     */
    protected function loadCachedFileList(): bool
    {
        try {
            $cachePath = $this->fileListCachePath();

            if (!is_file($cachePath)) {
                return false;
            }

            $cached = json_decode((string) @file_get_contents($cachePath), true);

            if (!is_array($cached) || empty($cached['files']) || !is_array($cached['files'])) {
                return false;
            }

            $status = $this->statusManager->getStatus();

            if (!$status || ($cached['update_id'] ?? null) !== ($status['update_id'] ?? null)) {
                return false;
            }

            $this->fileList = array_values($cached['files']);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to load cached replacement file list, rescanning', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Persist the just-built file list. Best-effort: a cache failure must
     * never break the update, it just means the next batch rescans.
     */
    protected function saveCachedFileList(): void
    {
        try {
            $status = $this->statusManager->getStatus();

            File::put($this->fileListCachePath(), json_encode([
                'update_id' => $status['update_id'] ?? null,
                'built_at' => date('c'),
                'files' => $this->fileList,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Failed to cache replacement file list', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve the configured replacement batch size, guarding against a
     * malformed env override. `env('XG_UPDATE_REPLACEMENT_BATCH', 50)` only
     * falls back to 50 when the key is unset - if it's set to something
     * like "50 # Files per replacement batch" (e.g. pasted verbatim into a
     * host's env editor that doesn't strip inline comments) it comes back
     * as that literal string, a leading-numeric value PHP accepts in
     * arithmetic with only a warning that Laravel promotes to a fatal
     * ErrorException ("A non-numeric value encountered").
     */
    protected function resolveBatchSize(): int
    {
        $batchSize = (int) Config::get('xgapiclient.update.replacement_batch_size', 50);

        return $batchSize > 0 ? $batchSize : 50;
    }

    /**
     * Detect the actual root directory of the update
     * Skips wrapper folders like "HelpNest-update-v1.1.0/update/"
     */
    protected function detectUpdateRoot(string $path): string
    {
        $currentPath = $path;
        
        // Safety limit to prevent infinite loops
        for ($i = 0; $i < 5; $i++) {
            $files = File::files($currentPath);
            $directories = File::directories($currentPath);

            // If there are files, this is likely the root (or contains files we need)
            if (count($files) > 0) {
                return $currentPath;
            }

            // If there are multiple directories, this is likely the root
            if (count($directories) > 1) {
                return $currentPath;
            }

            // If there is exactly one directory and no files, drill down
            if (count($directories) === 1) {
                $currentPath = $directories[0];
                continue;
            }

            // Empty directory? Return original path
            return $path;
        }

        return $currentPath;
    }

    /**
     * Check if file/path should be skipped
     */
    protected function shouldSkip(string $relativePath): bool
    {
        $filename = basename($relativePath);

        // Check skip files
        if (in_array($filename, $this->skipFiles, true)) {
            return true;
        }

        // Check skip directories
        foreach ($this->skipDirectories as $skipDir) {
            $skipDir = trim($skipDir);
            if (empty($skipDir)) {
                continue;
            }

            if (
                str_contains($relativePath, "/{$skipDir}/") ||
                str_starts_with($relativePath, "{$skipDir}/")
            ) {
                return true;
            }
        }

        // Check skip files with path
        foreach ($this->skipFilesWithPath as $skipPath) {
            $skipPath = trim($skipPath);
            if (empty($skipPath)) {
                continue;
            }

            if ($relativePath === $skipPath) {
                return true;
            }
        }

        // Skip .git folder contents
        if (str_contains($relativePath, '.git/')) {
            return true;
        }

        return false;
    }

    protected function handleCustomFile(string $extractedPath, string $relativePath): ?string
    {
        $changeLogsPath = $extractedPath . '/change-logs.json';

        if (!File::exists($changeLogsPath)) {
            Log::warning("change-logs.json not found, skipping custom file: {$relativePath}");
            return null;
        }

        try {
            $changeLogs = json_decode(File::get($changeLogsPath), true);
            $customFiles = $changeLogs['custom'] ?? [];

            $filename = basename($relativePath);

            foreach ($customFiles as $customFile) {
                if (($customFile['filename'] ?? '') === $filename) {
                    $destinationPath = $customFile['path'] ?? null;
                    if ($destinationPath) {
                        return storage_path('../../' . $destinationPath . '/' . $filename);
                    }
                }
            }

            Log::warning("No mapping found in change-logs.json for: {$relativePath}");
            return null;
        } catch (\Throwable $e) {
            Log::error("Failed to parse change-logs.json", ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function getDestinationPath(string $relativePath): ?string
    {
        // Handle public/ directory - goes to Laravel public folder
        if (str_starts_with($relativePath, 'public/')) {
            return public_path(substr($relativePath, 7));
        }

        // Handle __rootFiles/ - goes to Laravel root
        if (str_starts_with($relativePath, '__rootFiles/')) {
            return base_path(substr($relativePath, 12));
        }

        // Handle custom/ folder - needs special handling based on change-logs.json
        if (str_starts_with($relativePath, 'custom/')) {
            $paths = $this->statusManager->getPaths();
            $realRoot = $this->detectUpdateRoot($paths['extracted']);
            return $this->handleCustomFile($realRoot, $relativePath);
        }

        // Handle assets/ directory - goes to root assets folder (one level up from core)
        if (str_starts_with($relativePath, 'assets/')) {
            return base_path('../' . $relativePath);
        }

        // Handle Modules/ directory
        if (str_starts_with($relativePath, 'Modules/')) {
            return base_path($relativePath);
        }

        // Handle plugins/ directory
        if (str_starts_with($relativePath, 'plugins/')) {
            return base_path($relativePath);
        }

        if (str_starts_with($relativePath, 'vendor/')) {
            $destPath = base_path($relativePath);
            return $destPath;
        }

        return base_path($relativePath);
    }

    /**
     * Backup a file before replacing
     */
    protected function backupFile(string $originalPath, string $relativePath): void
    {
        $paths = $this->statusManager->getPaths();
        $backupPath = $paths['backup'] . '/' . $relativePath;
        $backupDir = dirname($backupPath);

        try {
            if (!File::isDirectory($backupDir)) {
                File::makeDirectory($backupDir, 0755, true);
            }

            File::copy($originalPath, $backupPath);
        } catch (\Throwable $e) {
            Log::warning("Failed to backup file: {$relativePath}", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Enable maintenance mode
     *
     * Also registers a shutdown fallback so that even a fatal error
     * (memory limit, max execution time, uncatchable engine error) during
     * file replacement restores public access instead of leaving the
     * `down` file behind indefinitely.
     */
    protected function enableMaintenanceMode(): void
    {
        try {
            // Enable maintenance mode with secret to allow update routes
            Artisan::call('down', [
                '--secret' => self::MAINTENANCE_BYPASS_SECRET,
                '--render' => 'errors::503',
            ]);
            $this->statusManager->update(['maintenance_mode' => true]);
            $this->statusManager->addLog('Maintenance mode enabled with update bypass');
            $this->registerMaintenanceShutdownFallback();
        } catch (\Throwable $e) {
            Log::warning("Failed to enable maintenance mode", ['error' => $e->getMessage()]);
        }
    }

    /**
     * URL the client visits once to acquire Laravel's maintenance-mode
     * bypass cookie for its own tab - without it, the wizard's own next
     * request would be blocked by the maintenance mode it just enabled.
     */
    public function getMaintenanceBypassUrl(): string
    {
        return url('/' . self::MAINTENANCE_BYPASS_SECRET);
    }

    /**
     * Disable maintenance mode
     *
     * Best-effort and never throws: a failure here must not leave the site
     * down. If `artisan up` fails, falls back to removing the `down` file
     * directly, then records the outcome in the update status either way.
     */
    public function disableMaintenanceMode(): void
    {
        try {
            Artisan::call('up');
        } catch (\Throwable $e) {
            Log::warning("Artisan up failed, trying direct down-file removal", ['error' => $e->getMessage()]);
        }

        if ($this->isAppDown()) {
            try {
                $downFile = storage_path('framework/down');
                if (is_file($downFile)) {
                    @unlink($downFile);
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to remove maintenance down file", ['error' => $e->getMessage()]);
            }
        }

        try {
            $this->statusManager->update(['maintenance_mode' => false]);
            $this->statusManager->addLog(
                $this->isAppDown()
                    ? 'Maintenance mode disable attempted but site may still be down - check manually with `php artisan up`'
                    : 'Maintenance mode disabled'
            );
        } catch (\Throwable $e) {
            Log::warning("Failed to record maintenance mode state", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check whether the app is currently down, without letting a broken
     * app state turn the check itself into a fatal.
     */
    protected function isAppDown(): bool
    {
        try {
            return app()->isDownForMaintenance();
        } catch (\Throwable $e) {
            return is_file(storage_path('framework/down'));
        }
    }

    /**
     * Last-resort net for fatals (time/memory limit) mid-replacement.
     * Only lifts maintenance that this update enabled (status flag), never
     * maintenance put up by someone else. Registered once per request.
     */
    protected function registerMaintenanceShutdownFallback(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }
        $registered = true;

        $statusFile = $this->statusManager->getPaths()['status_file'];

        register_shutdown_function(function () use ($statusFile) {
            try {
                $status = is_file($statusFile)
                    ? json_decode((string) @file_get_contents($statusFile), true)
                    : null;

                if (!is_array($status) || ($status['maintenance_mode'] ?? false) !== true) {
                    return;
                }

                $downFile = storage_path('framework/down');
                $stillDown = true;

                try {
                    if (function_exists('app') && app()->isDownForMaintenance()) {
                        try {
                            Artisan::call('up');
                        } catch (\Throwable $ignored) {
                        }
                        $stillDown = app()->isDownForMaintenance();
                    } else {
                        $stillDown = is_file($downFile);
                    }
                } catch (\Throwable $ignored) {
                    $stillDown = is_file($downFile);
                }

                if ($stillDown && is_file($downFile)) {
                    @unlink($downFile);
                }
            } catch (\Throwable $ignored) {
                // Shutdown fallback must never break the response.
            }
        });
    }

    /**
     * Set custom skip files
     */
    public function setSkipFiles(array $files): void
    {
        $this->skipFiles = array_unique(array_merge($this->skipFiles, $files));
    }

    /**
     * Set custom skip directories
     */
    public function setSkipDirectories(array $directories): void
    {
        $this->skipDirectories = array_unique(array_merge($this->skipDirectories, $directories));
    }

    /**
     * Check if replacement is complete
     */
    public function isReplacementComplete(): bool
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return false;
        }

        return $status['replacement']['status'] === 'completed';
    }

    /**
     * Get file list
     */
    public function getFileList(): array
    {
        return $this->fileList;
    }

    public function getComposerReport(): array
    {
        return $this->composerDiff->getDetailedReport();
    }

    public function reset(): void
    {
        $this->fileList = [];
        $this->fileListBuilt = false;
        $this->composerAnalyzed = false;
        $this->shouldReplaceCallback = null;

        try {
            $cachePath = $this->fileListCachePath();
            if (is_file($cachePath)) {
                @unlink($cachePath);
            }
        } catch (\Throwable $ignored) {
        }
    }

    public function setShouldReplaceCallback(callable $callback): void
    {
        $this->shouldReplaceCallback = $callback;
    }

    public function clearOpcache(): bool
    {
        $cleared = false;

        if (function_exists('opcache_reset')) {
            $cleared = @opcache_reset();
        }

        if (function_exists('opcache_invalidate')) {
        }

        return $cleared;
    }

    public function updateSelfPackage(): array
    {
        $paths = $this->statusManager->getPaths();
        $realRoot = $this->detectUpdateRoot($paths['extracted']);

        $replaced = 0;
        $skipped = 0;
        $errors = [];

        try {
            $xgPackagePath = $realRoot . '/vendor/xgenious/xgapiclient';

            if (!File::isDirectory($xgPackagePath)) {
                return [
                    'success' => true,
                    'message' => 'No XgApiClient package updates found',
                    'replaced' => 0,
                    'skipped' => 0,
                ];
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($xgPackagePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $relativePath = str_replace($realRoot . '/', '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);

                $destPath = base_path($relativePath);

                try {
                    $destDir = dirname($destPath);
                    if (!File::isDirectory($destDir)) {
                        File::makeDirectory($destDir, 0755, true);
                    }

                    if (Config::get('xgapiclient.update.enable_backup', false) && File::exists($destPath)) {
                        $this->backupFile($destPath, $relativePath);
                    }

                    File::copy($file->getPathname(), $destPath);
                    $replaced++;
                } catch (\Throwable $e) {
                    $errors[] = $relativePath;
                    Log::warning("Failed to update XgApiClient file: {$relativePath}", ['error' => $e->getMessage()]);
                }
            }

            $this->statusManager->addLog("Updated XgApiClient package: {$replaced} files replaced");

            return [
                'success' => true,
                'message' => "XgApiClient package updated successfully",
                'replaced' => $replaced,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to update XgApiClient package', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to update XgApiClient package: ' . $e->getMessage(),
                'replaced' => $replaced,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }
    }
}
