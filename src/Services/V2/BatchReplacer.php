<?php

namespace Xgenious\XgApiClient\Services\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BatchReplacer
{
    protected UpdateStatusManager $statusManager;
    protected array $fileList = [];
    protected bool $fileListBuilt = false;
    protected array $skipFiles = [];
    protected array $skipDirectories = [];
    protected array $skipFilesWithPath = [];

    public function __construct(UpdateStatusManager $statusManager)
    {
        $this->statusManager = $statusManager;
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
        }

        // Add default skip files if not already present
        $defaultSkipFiles = ['.env', '.htaccess', 'dynamic-style.css', 'dynamic-script.js', '.DS_Store'];
        $this->skipFiles = array_unique(array_merge($this->skipFiles, $defaultSkipFiles));

        // Add default skip directories if not already present
        $defaultSkipDirs = ['lang', 'custom-fonts', '.git', '.idea', '.vscode', '.fleet', 'node_modules'];
        $this->skipDirectories = array_unique(array_merge($this->skipDirectories, $defaultSkipDirs));
    }

    /**
     * Replace a batch of files
     */
    public function replaceBatch(int $batchNumber, ?int $batchSize = null): array
    {
        $batchSize = $batchSize ?? Config::get('xgapiclient.update.replacement_batch_size', 50);
        $paths = $this->statusManager->getPaths();

        try {
            // Build file list on first call
            if (!$this->fileListBuilt) {
                $this->buildFileList($paths['extracted']);
            }

            // Enable maintenance mode on first batch
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

            for ($i = $startIndex; $i < $endIndex; $i++) {
                $relativePath = $this->fileList[$i];
                $sourcePath = $paths['extracted'] . '/' . $relativePath;

                // Check if should skip
                if ($this->shouldSkip($relativePath)) {
                    $skippedInBatch++;
                    continue;
                }

                // Determine destination path
                $destPath = $this->getDestinationPath($relativePath);

                if (!$destPath) {
                    $skippedInBatch++;
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

                    // Copy file
                    if (File::exists($sourcePath)) {
                        File::copy($sourcePath, $destPath);
                        $replacedInBatch++;
                    } else {
                        $errors[] = $relativePath;
                    }

                } catch (\Exception $e) {
                    $errors[] = $relativePath;
                    Log::warning("Failed to replace file: {$relativePath}", ['error' => $e->getMessage()]);
                }
            }

            // Calculate progress
            $status = $this->statusManager->getStatus();
            $previousReplaced = $status['replacement']['replaced_files'] ?? 0;
            $previousSkipped = $status['replacement']['skipped_files'] ?? 0;

            $totalReplaced = $previousReplaced + $replacedInBatch;
            $totalSkipped = $previousSkipped + $skippedInBatch;
            $processed = $startIndex + $replacedInBatch + $skippedInBatch;
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
            ];

        } catch (\Exception $e) {
            Log::error("Replacement failed", ['batch' => $batchNumber, 'error' => $e->getMessage()]);
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractedPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relativePath = str_replace($extractedPath . '/', '', $file->getPathname());
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

            if (str_contains($relativePath, "/{$skipDir}/") ||
                str_starts_with($relativePath, "{$skipDir}/")) {
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

    /**
     * Get destination path for a file
     */
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
            // For now, skip custom folder files
            // They require change-logs.json mapping
            return null;
        }

        // Handle assets/ directory - goes to root assets folder
        if (str_starts_with($relativePath, 'assets/')) {
            return base_path($relativePath);
        }

        // Handle Modules/ directory
        if (str_starts_with($relativePath, 'Modules/')) {
            return base_path($relativePath);
        }

        // Handle plugins/ directory
        if (str_starts_with($relativePath, 'plugins/')) {
            return base_path($relativePath);
        }

        // Default: relative to base path
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
            Artisan::call('down');
            $this->statusManager->update(['maintenance_mode' => true]);
            $this->statusManager->addLog('Maintenance mode enabled');
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

    /**
     * Reset state
     */
    public function reset(): void
    {
        $this->fileList = [];
        $this->fileListBuilt = false;
    }
}
