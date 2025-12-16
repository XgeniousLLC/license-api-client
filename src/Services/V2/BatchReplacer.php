<?php

namespace Xgenious\XgApiClient\Services\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BatchReplacer
{
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
        $batchSize = $batchSize ?? Config::get('xgapiclient.update.replacement_batch_size', 50);
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
                } catch (\Exception $e) {
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
                } catch (\Exception $e) {
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
            ];

        } catch (\Exception $e) {
            Log::error("Replacement failed", [
                'batch' => $batchNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->statusManager->recordError('replacement_failed', $e->getMessage(), [
                'batch' => $batchNumber,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'batch' => $batchNumber,
            ];
        }
    }

    /**
     * Build list of files to replace from extracted directory
     */
    protected function buildFileList(string $extractedPath): void
    {
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

        // Update status with totals
        $batchSize = Config::get('xgapiclient.update.replacement_batch_size', 50);
        $totalBatches = (int) ceil(count($this->fileList) / $batchSize);

        $this->statusManager->updatePhase('replacement', [
            'total_files' => count($this->fileList),
            'total_batches' => $totalBatches,
        ]);
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            Log::warning("Failed to backup file: {$relativePath}", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Enable maintenance mode
     */
    protected function enableMaintenanceMode(): void
    {
        try {
            // Enable maintenance mode with secret to allow update routes
            Artisan::call('down', [
                '--secret' => 'xg-update-in-progress',
                '--render' => 'errors::503',
            ]);
            $this->statusManager->update(['maintenance_mode' => true]);
            $this->statusManager->addLog('Maintenance mode enabled with update bypass');
        } catch (\Exception $e) {
            Log::warning("Failed to enable maintenance mode", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Disable maintenance mode
     */
    public function disableMaintenanceMode(): void
    {
        try {
            Artisan::call('up');
            $this->statusManager->update(['maintenance_mode' => false]);
            $this->statusManager->addLog('Maintenance mode disabled');
        } catch (\Exception $e) {
            Log::warning("Failed to disable maintenance mode", ['error' => $e->getMessage()]);
        }
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
                } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
