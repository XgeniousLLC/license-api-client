# Client Package Implementation Guide - Chunked Update System

## Overview

This document provides complete implementation details for updating the XgApiClient package to support chunked downloads and JavaScript-driven update process. This eliminates timeout issues when downloading large update files (100MB+).

## Prerequisites

The license server must be updated first with the chunked download system. See `LICENSE-SERVER-IMPLEMENTATION.md` for details.

## Current System

```
Current Flow:
1. User clicks "Update"
2. Single PHP request downloads entire ZIP
3. PHP extracts and replaces all files
4. Large files cause timeout errors
```

## Target System

```
New Flow:
1. User clicks "Update"
2. JavaScript orchestrates the entire process:
   → Fetches manifest from server
   → Downloads ZIP in 10MB chunks
   → Triggers PHP endpoints for each step
   → Shows real-time progress
3. Each PHP request handles small, fast operations
4. Status tracked in JSON file for resume capability
```

---

## New File Structure

```
src/
├── XgApiClient.php                         # Existing (minor modifications)
├── XgApiClientServiceProvider.php          # Existing (add new routes, assets)
├── CacheCleaner.php                        # Existing (keep as-is)
├── Facades/XgApiClient.php                 # Existing (keep as-is)
├── Http/
│   └── Controllers/
│       ├── Controller.php                  # Existing
│       ├── ActivationController.php        # Existing
│       ├── SystemUpgradeController.php     # Existing (keep for backward compat)
│       └── V2/                             # NEW
│           ├── UpdateStatusController.php
│           ├── ManifestController.php
│           ├── ChunkController.php
│           ├── ExtractionController.php
│           ├── ReplacementController.php
│           └── MigrationController.php
├── Services/                               # NEW
│   ├── UpdateStatusManager.php
│   ├── ManifestComparator.php
│   ├── ChunkDownloader.php
│   ├── ChunkMerger.php
│   ├── BatchExtractor.php
│   ├── BatchReplacer.php
│   └── LocalManifestGenerator.php
└── Commands/
    └── XgApiClientCommand.php              # Existing

resources/
├── views/
│   ├── check-update.blade.php              # Existing (heavily modify)
│   ├── license-activation.blade.php        # Existing
│   ├── v2/                                 # NEW
│   │   └── update-progress.blade.php
│   └── partials/
│       └── message.blade.php               # Existing

routes/
└── web.php                                 # Add new routes

public/                                     # NEW (publishable assets)
└── vendor/
    └── xgapiclient/
        ├── js/
        │   └── update-manager.js
        └── css/
            └── update-progress.css
```

---

## Configuration Updates

### config/xgapiclient.php

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Base API URL
    |--------------------------------------------------------------------------
    */
    "base_api_url" => env('XG_API_URL', "https://license.xgenious.com"),

    /*
    |--------------------------------------------------------------------------
    | API Token
    |--------------------------------------------------------------------------
    */
    "has_token" => env('XG_API_TOKEN', ""),

    /*
    |--------------------------------------------------------------------------
    | Chunked Update Settings
    |--------------------------------------------------------------------------
    */
    "update" => [
        // Enable the new chunked update system
        "chunked_enabled" => env('XG_CHUNKED_UPDATE', true),

        // Chunk size in bytes (10MB default)
        "chunk_size" => env('XG_CHUNK_SIZE', 10485760),

        // Number of files to extract per batch
        "extraction_batch_size" => env('XG_EXTRACTION_BATCH', 100),

        // Number of files to replace per batch
        "replacement_batch_size" => env('XG_REPLACEMENT_BATCH', 50),

        // Maximum retry attempts for failed chunks
        "max_retries" => env('XG_MAX_RETRIES', 3),

        // Delay between retries in milliseconds
        "retry_delay" => env('XG_RETRY_DELAY', 2000),

        // Create backup of files before replacing
        "enable_backup" => env('XG_ENABLE_BACKUP', false),

        // Allow incremental updates (only changed files)
        "enable_incremental" => env('XG_INCREMENTAL', true),

        // Use incremental if less than this % of files changed
        "incremental_threshold" => env('XG_INCREMENTAL_THRESHOLD', 0.3),
    ],
];
```

---

## Service Provider Updates

### XgApiClientServiceProvider.php

```php
<?php

namespace Xgenious\XgApiClient;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Xgenious\XgApiClient\Commands\XgApiClientCommand;
use Xgenious\XgApiClient\Services\UpdateStatusManager;
use Xgenious\XgApiClient\Services\ChunkDownloader;
use Xgenious\XgApiClient\Services\ChunkMerger;
use Xgenious\XgApiClient\Services\BatchExtractor;
use Xgenious\XgApiClient\Services\BatchReplacer;
use Xgenious\XgApiClient\Services\ManifestComparator;
use Xgenious\XgApiClient\Services\LocalManifestGenerator;

class XgApiClientServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('xgapiclient')
            ->hasConfigFile('xgapiclient')
            ->hasViews('XgApiClient')
            ->hasRoute('web')
            ->hasMigration('create_xg_ftp_infos_table')
            ->hasCommand(XgApiClientCommand::class)
            ->hasAssets(); // Add this for JS/CSS assets
    }

    public function boot()
    {
        // Register facade
        app()->bind('XgApiClient', function () {
            return new XgApiClient();
        });

        // Register services as singletons
        $this->app->singleton(UpdateStatusManager::class);
        $this->app->singleton(ChunkDownloader::class);
        $this->app->singleton(ChunkMerger::class);
        $this->app->singleton(BatchExtractor::class);
        $this->app->singleton(BatchReplacer::class);
        $this->app->singleton(ManifestComparator::class);
        $this->app->singleton(LocalManifestGenerator::class);

        // Publish assets
        $this->publishes([
            __DIR__ . '/../public' => public_path('vendor/xgapiclient'),
        ], 'xgapiclient-assets');

        return parent::boot();
    }
}
```

---

## Routes

### routes/web.php

```php
<?php

use Xgenious\XgApiClient\Http\Controllers\ActivationController;
use Xgenious\XgApiClient\Http\Controllers\SystemUpgradeController;
use Xgenious\XgApiClient\Http\Controllers\V2\UpdateStatusController;
use Xgenious\XgApiClient\Http\Controllers\V2\ManifestController;
use Xgenious\XgApiClient\Http\Controllers\V2\ChunkController;
use Xgenious\XgApiClient\Http\Controllers\V2\ExtractionController;
use Xgenious\XgApiClient\Http\Controllers\V2\ReplacementController;
use Xgenious\XgApiClient\Http\Controllers\V2\MigrationController;

// Existing routes (keep for backward compatibility)
Route::get('/license-activation', [ActivationController::class, 'licenseActivation'])->name('license.activation');
Route::post('/license-activation-update', [ActivationController::class, 'licenseActivationUpdate'])->name('license.activation.update');
Route::get('/check-update', [SystemUpgradeController::class, 'checkSystemUpdate'])->name('check.system.update');
Route::post('/download-update/{productId}/{tenant}', [SystemUpgradeController::class, 'updateDownloadLatestVersion'])->name('update.download');

// NEW: V2 chunked update routes
Route::prefix('xg-update')->name('xg-update.')->group(function () {
    // Status & Info
    Route::get('/status', [UpdateStatusController::class, 'status'])->name('status');
    Route::get('/check', [UpdateStatusController::class, 'check'])->name('check');
    Route::post('/reset', [UpdateStatusController::class, 'reset'])->name('reset');
    Route::post('/cancel', [UpdateStatusController::class, 'cancel'])->name('cancel');

    // Manifest operations
    Route::get('/remote-manifest', [ManifestController::class, 'fetchRemote'])->name('manifest.remote');
    Route::get('/local-manifest', [ManifestController::class, 'generateLocal'])->name('manifest.local');
    Route::get('/compare', [ManifestController::class, 'compare'])->name('manifest.compare');

    // Chunk operations
    Route::post('/download-chunk', [ChunkController::class, 'download'])->name('chunk.download');
    Route::post('/verify-chunk', [ChunkController::class, 'verify'])->name('chunk.verify');
    Route::post('/merge-chunks', [ChunkController::class, 'merge'])->name('chunk.merge');
    Route::post('/download-selective', [ChunkController::class, 'downloadSelective'])->name('chunk.selective');

    // Extraction & Replacement
    Route::post('/extract-batch', [ExtractionController::class, 'extractBatch'])->name('extract.batch');
    Route::post('/replace-batch', [ReplacementController::class, 'replaceBatch'])->name('replace.batch');

    // Migration & Cleanup
    Route::post('/migrate', [MigrationController::class, 'run'])->name('migrate');
    Route::post('/cleanup', [MigrationController::class, 'cleanup'])->name('cleanup');
    Route::post('/finalize', [MigrationController::class, 'finalize'])->name('finalize');

    // UI
    Route::get('/progress', [UpdateStatusController::class, 'progressPage'])->name('progress');
});
```

---

## Services

### Service: UpdateStatusManager

```php
<?php

namespace Xgenious\XgApiClient\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class UpdateStatusManager
{
    protected string $statusDir;
    protected string $statusFile;

    public function __construct()
    {
        $this->statusDir = storage_path('app/xg-update');
        $this->statusFile = $this->statusDir . '/.update-status.json';
    }

    /**
     * Initialize a new update session
     */
    public function initiate(string $targetVersion, array $updateInfo): array
    {
        $this->ensureDirectoryExists();

        $status = [
            'update_id' => 'upd_' . date('Ymd_His') . '_' . Str::random(6),
            'started_at' => now()->toIso8601String(),
            'last_activity' => now()->toIso8601String(),

            'version' => [
                'current' => $updateInfo['current_version'] ?? 'unknown',
                'target' => $targetVersion,
            ],

            'mode' => 'chunked', // or 'incremental'

            'phase' => 'initialized',

            'download' => [
                'method' => 'chunked',
                'total_size' => $updateInfo['total_size'] ?? 0,
                'downloaded_size' => 0,
                'total_chunks' => $updateInfo['total_chunks'] ?? 0,
                'completed_chunks' => [],
                'current_chunk' => null,
                'chunk_hashes' => [],
                'percent' => 0,
            ],

            'extraction' => [
                'status' => 'pending',
                'total_files' => $updateInfo['total_files'] ?? 0,
                'extracted_files' => 0,
                'current_batch' => 0,
                'total_batches' => 0,
                'percent' => 0,
            ],

            'replacement' => [
                'status' => 'pending',
                'total_files' => 0,
                'replaced_files' => 0,
                'skipped_files' => 0,
                'current_batch' => 0,
                'total_batches' => 0,
                'percent' => 0,
                'current_file' => null,
            ],

            'migration' => [
                'status' => 'pending',
                'migrations_run' => 0,
                'seeders_run' => 0,
                'tenant_progress' => [
                    'total' => 0,
                    'completed' => 0,
                ],
            ],

            'maintenance_mode' => false,

            'errors' => [],

            'log' => [
                ['time' => now()->format('H:i:s'), 'message' => 'Update initialized'],
            ],
        ];

        $this->save($status);
        return $status;
    }

    /**
     * Get current status
     */
    public function getStatus(): ?array
    {
        if (!File::exists($this->statusFile)) {
            return null;
        }

        $content = File::get($this->statusFile);
        return json_decode($content, true);
    }

    /**
     * Update status
     */
    public function update(array $data): array
    {
        $status = $this->getStatus() ?? [];
        $status = array_merge($status, $data);
        $status['last_activity'] = now()->toIso8601String();
        $this->save($status);
        return $status;
    }

    /**
     * Update a specific phase
     */
    public function updatePhase(string $phase, array $data): array
    {
        $status = $this->getStatus() ?? [];
        $status['phase'] = $phase;
        $status[$phase] = array_merge($status[$phase] ?? [], $data);
        $status['last_activity'] = now()->toIso8601String();
        $this->save($status);
        return $status;
    }

    /**
     * Mark a chunk as completed
     */
    public function markChunkCompleted(int $chunkIndex, string $hash, int $size): array
    {
        $status = $this->getStatus();

        if (!in_array($chunkIndex, $status['download']['completed_chunks'])) {
            $status['download']['completed_chunks'][] = $chunkIndex;
            sort($status['download']['completed_chunks']);
        }

        $status['download']['chunk_hashes'][$chunkIndex] = $hash;
        $status['download']['downloaded_size'] += $size;
        $status['download']['percent'] = $status['download']['total_chunks'] > 0
            ? round((count($status['download']['completed_chunks']) / $status['download']['total_chunks']) * 100)
            : 0;

        $status['last_activity'] = now()->toIso8601String();
        $this->save($status);

        return $status;
    }

    /**
     * Add log entry
     */
    public function addLog(string $message): void
    {
        $status = $this->getStatus();
        if ($status) {
            $status['log'][] = [
                'time' => now()->format('H:i:s'),
                'message' => $message,
            ];

            // Keep only last 100 log entries
            if (count($status['log']) > 100) {
                $status['log'] = array_slice($status['log'], -100);
            }

            $this->save($status);
        }
    }

    /**
     * Record an error
     */
    public function recordError(string $type, string $message, array $context = []): void
    {
        $status = $this->getStatus();
        if ($status) {
            $status['errors'][] = [
                'type' => $type,
                'message' => $message,
                'context' => $context,
                'occurred_at' => now()->toIso8601String(),
            ];
            $status['phase'] = 'error';
            $this->save($status);
        }
    }

    /**
     * Check if update can be resumed
     */
    public function canResume(): bool
    {
        $status = $this->getStatus();

        if (!$status) {
            return false;
        }

        // Can resume if not in error state or completed
        return !in_array($status['phase'], ['completed', 'error']);
    }

    /**
     * Get resume point
     */
    public function getResumePoint(): ?array
    {
        $status = $this->getStatus();

        if (!$status || !$this->canResume()) {
            return null;
        }

        return [
            'phase' => $status['phase'],
            'download' => [
                'completed_chunks' => $status['download']['completed_chunks'],
                'next_chunk' => count($status['download']['completed_chunks']),
            ],
            'extraction' => [
                'current_batch' => $status['extraction']['current_batch'],
            ],
            'replacement' => [
                'current_batch' => $status['replacement']['current_batch'],
            ],
        ];
    }

    /**
     * Mark as complete
     */
    public function markComplete(): array
    {
        $status = $this->getStatus();
        $status['phase'] = 'completed';
        $status['completed_at'] = now()->toIso8601String();
        $this->addLog('Update completed successfully');
        $this->save($status);
        return $status;
    }

    /**
     * Reset status (for fresh start)
     */
    public function reset(): void
    {
        if (File::exists($this->statusFile)) {
            File::delete($this->statusFile);
        }

        // Clean up update directory
        $updateDir = $this->statusDir;
        if (File::isDirectory($updateDir)) {
            // Keep the directory but remove contents
            $this->cleanDirectory($updateDir);
        }
    }

    /**
     * Save status to file
     */
    protected function save(array $status): void
    {
        $this->ensureDirectoryExists();
        File::put($this->statusFile, json_encode($status, JSON_PRETTY_PRINT));
    }

    /**
     * Ensure directory exists
     */
    protected function ensureDirectoryExists(): void
    {
        if (!File::isDirectory($this->statusDir)) {
            File::makeDirectory($this->statusDir, 0755, true);
        }
    }

    /**
     * Clean directory contents
     */
    protected function cleanDirectory(string $directory): void
    {
        $items = File::allFiles($directory);
        foreach ($items as $item) {
            File::delete($item);
        }

        $directories = File::directories($directory);
        foreach ($directories as $dir) {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Get storage paths
     */
    public function getPaths(): array
    {
        return [
            'base' => $this->statusDir,
            'status_file' => $this->statusFile,
            'chunks' => $this->statusDir . '/chunks',
            'extracted' => $this->statusDir . '/extracted',
            'backup' => $this->statusDir . '/backup',
            'zip' => $this->statusDir . '/update.zip',
        ];
    }
}
```

### Service: ChunkDownloader

```php
<?php

namespace Xgenious\XgApiClient\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChunkDownloader
{
    protected UpdateStatusManager $statusManager;
    protected string $chunksDir;

    public function __construct(UpdateStatusManager $statusManager)
    {
        $this->statusManager = $statusManager;
        $this->chunksDir = storage_path('app/xg-update/chunks');
    }

    /**
     * Download a single chunk
     */
    public function download(int $chunkIndex, string $licenseKey, string $productUid): array
    {
        $this->ensureChunksDirExists();

        $baseUrl = Config::get('xgapiclient.base_api_url');
        $url = "{$baseUrl}/api/v2/download-chunk/{$licenseKey}/{$productUid}/{$chunkIndex}";

        $this->statusManager->addLog("Downloading chunk {$chunkIndex}...");
        $this->statusManager->updatePhase('download', ['current_chunk' => $chunkIndex]);

        try {
            $response = Http::timeout(300) // 5 minute timeout per chunk
                ->withHeaders([
                    'X-Site-Url' => url('/'),
                    'Accept' => 'application/octet-stream',
                ])
                ->get($url);

            if (!$response->successful()) {
                throw new \Exception("Failed to download chunk {$chunkIndex}: " . $response->status());
            }

            // Get chunk info from headers
            $expectedHash = $response->header('X-Chunk-Hash');
            $totalChunks = (int) $response->header('X-Total-Chunks');

            // Save chunk to file
            $chunkPath = $this->getChunkPath($chunkIndex);
            $chunkData = $response->body();
            File::put($chunkPath, $chunkData);

            // Verify hash
            $actualHash = hash('sha256', $chunkData);
            if ($expectedHash && $actualHash !== $expectedHash) {
                File::delete($chunkPath);
                throw new \Exception("Chunk {$chunkIndex} hash mismatch");
            }

            $chunkSize = strlen($chunkData);
            unset($chunkData); // Free memory

            // Update status
            $this->statusManager->markChunkCompleted($chunkIndex, $actualHash, $chunkSize);
            $this->statusManager->addLog("Chunk {$chunkIndex} downloaded ({$this->formatBytes($chunkSize)})");

            return [
                'success' => true,
                'chunk_index' => $chunkIndex,
                'chunk_size' => $chunkSize,
                'chunk_hash' => $actualHash,
                'total_chunks' => $totalChunks,
            ];

        } catch (\Exception $e) {
            Log::error("Chunk download failed", [
                'chunk' => $chunkIndex,
                'error' => $e->getMessage(),
            ]);

            $this->statusManager->recordError('download_failed', $e->getMessage(), [
                'chunk_index' => $chunkIndex,
            ]);

            return [
                'success' => false,
                'chunk_index' => $chunkIndex,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify a downloaded chunk
     */
    public function verify(int $chunkIndex, string $expectedHash): bool
    {
        $chunkPath = $this->getChunkPath($chunkIndex);

        if (!File::exists($chunkPath)) {
            return false;
        }

        $actualHash = hash_file('sha256', $chunkPath);
        return $actualHash === $expectedHash;
    }

    /**
     * Get list of downloaded chunks
     */
    public function getDownloadedChunks(): array
    {
        if (!File::isDirectory($this->chunksDir)) {
            return [];
        }

        $chunks = [];
        $files = File::files($this->chunksDir);

        foreach ($files as $file) {
            if (preg_match('/chunk_(\d+)\.bin$/', $file->getFilename(), $matches)) {
                $chunks[] = (int) $matches[1];
            }
        }

        sort($chunks);
        return $chunks;
    }

    /**
     * Get chunk file path
     */
    public function getChunkPath(int $chunkIndex): string
    {
        return $this->chunksDir . '/chunk_' . str_pad($chunkIndex, 3, '0', STR_PAD_LEFT) . '.bin';
    }

    /**
     * Ensure chunks directory exists
     */
    protected function ensureChunksDirExists(): void
    {
        if (!File::isDirectory($this->chunksDir)) {
            File::makeDirectory($this->chunksDir, 0755, true);
        }
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
```

### Service: ChunkMerger

```php
<?php

namespace Xgenious\XgApiClient\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ChunkMerger
{
    protected UpdateStatusManager $statusManager;
    protected ChunkDownloader $chunkDownloader;

    public function __construct(UpdateStatusManager $statusManager, ChunkDownloader $chunkDownloader)
    {
        $this->statusManager = $statusManager;
        $this->chunkDownloader = $chunkDownloader;
    }

    /**
     * Merge all chunks into single ZIP file
     */
    public function merge(): array
    {
        $paths = $this->statusManager->getPaths();
        $status = $this->statusManager->getStatus();

        $this->statusManager->addLog('Merging chunks into ZIP file...');
        $this->statusManager->update(['phase' => 'merging']);

        try {
            $totalChunks = $status['download']['total_chunks'];
            $downloadedChunks = $this->chunkDownloader->getDownloadedChunks();

            // Verify all chunks exist
            for ($i = 0; $i < $totalChunks; $i++) {
                if (!in_array($i, $downloadedChunks)) {
                    throw new \Exception("Missing chunk {$i}");
                }
            }

            // Create output file
            $outputPath = $paths['zip'];
            $outputHandle = fopen($outputPath, 'wb');

            if (!$outputHandle) {
                throw new \Exception("Cannot create output file");
            }

            $totalSize = 0;

            // Merge chunks in order
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $this->chunkDownloader->getChunkPath($i);
                $chunkData = File::get($chunkPath);
                fwrite($outputHandle, $chunkData);
                $totalSize += strlen($chunkData);
                unset($chunkData);
            }

            fclose($outputHandle);

            // Verify ZIP is valid
            $zip = new \ZipArchive();
            if ($zip->open($outputPath) !== true) {
                throw new \Exception("Merged file is not a valid ZIP");
            }
            $fileCount = $zip->numFiles;
            $zip->close();

            // Calculate hash
            $zipHash = hash_file('sha256', $outputPath);

            $this->statusManager->addLog("Chunks merged successfully ({$this->formatBytes($totalSize)})");

            return [
                'success' => true,
                'zip_path' => $outputPath,
                'zip_size' => $totalSize,
                'zip_hash' => $zipHash,
                'file_count' => $fileCount,
            ];

        } catch (\Exception $e) {
            Log::error("Chunk merge failed", ['error' => $e->getMessage()]);
            $this->statusManager->recordError('merge_failed', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cleanup chunk files after successful merge
     */
    public function cleanupChunks(): void
    {
        $paths = $this->statusManager->getPaths();
        $chunksDir = $paths['chunks'];

        if (File::isDirectory($chunksDir)) {
            File::deleteDirectory($chunksDir);
        }

        $this->statusManager->addLog('Chunk files cleaned up');
    }

    /**
     * Verify merged ZIP against expected hash
     */
    public function verify(string $expectedHash): bool
    {
        $paths = $this->statusManager->getPaths();
        $zipPath = $paths['zip'];

        if (!File::exists($zipPath)) {
            return false;
        }

        $actualHash = hash_file('sha256', $zipPath);
        return $actualHash === $expectedHash;
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
```

### Service: BatchExtractor

```php
<?php

namespace Xgenious\XgApiClient\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BatchExtractor
{
    protected UpdateStatusManager $statusManager;
    protected \ZipArchive $zip;
    protected bool $zipOpened = false;
    protected array $fileList = [];

    public function __construct(UpdateStatusManager $statusManager)
    {
        $this->statusManager = $statusManager;
        $this->zip = new \ZipArchive();
    }

    /**
     * Extract a batch of files from ZIP
     */
    public function extractBatch(int $batchNumber, ?int $batchSize = null): array
    {
        $batchSize = $batchSize ?? Config::get('xgapiclient.update.extraction_batch_size', 100);
        $paths = $this->statusManager->getPaths();

        try {
            // Open ZIP if not already open
            if (!$this->zipOpened) {
                $result = $this->zip->open($paths['zip']);
                if ($result !== true) {
                    throw new \Exception("Cannot open ZIP file (error: {$result})");
                }
                $this->zipOpened = true;
                $this->buildFileList();
            }

            // Ensure extract directory exists
            if (!File::isDirectory($paths['extracted'])) {
                File::makeDirectory($paths['extracted'], 0755, true);
            }

            $totalFiles = count($this->fileList);
            $startIndex = $batchNumber * $batchSize;
            $endIndex = min($startIndex + $batchSize, $totalFiles);

            if ($startIndex >= $totalFiles) {
                return [
                    'success' => true,
                    'extracted' => 0,
                    'extracted_total' => $totalFiles,
                    'total_files' => $totalFiles,
                    'has_more' => false,
                    'percent' => 100,
                ];
            }

            $extractedInBatch = 0;

            for ($i = $startIndex; $i < $endIndex; $i++) {
                $fileName = $this->fileList[$i];

                // Extract file
                $content = $this->zip->getFromName($fileName);
                if ($content === false) {
                    Log::warning("Could not extract file: {$fileName}");
                    continue;
                }

                // Determine output path (remove 'update/' prefix if present)
                $relativePath = $this->normalizeFilePath($fileName);
                $outputPath = $paths['extracted'] . '/' . $relativePath;

                // Ensure directory exists
                $outputDir = dirname($outputPath);
                if (!File::isDirectory($outputDir)) {
                    File::makeDirectory($outputDir, 0755, true);
                }

                // Write file
                File::put($outputPath, $content);
                $extractedInBatch++;
            }

            $totalExtracted = $endIndex;
            $percent = round(($totalExtracted / $totalFiles) * 100);

            // Update status
            $this->statusManager->updatePhase('extraction', [
                'status' => 'in_progress',
                'extracted_files' => $totalExtracted,
                'current_batch' => $batchNumber,
                'percent' => $percent,
            ]);

            if ($batchNumber % 5 === 0) {
                $this->statusManager->addLog("Extracted {$totalExtracted} of {$totalFiles} files ({$percent}%)");
            }

            return [
                'success' => true,
                'extracted' => $extractedInBatch,
                'extracted_total' => $totalExtracted,
                'total_files' => $totalFiles,
                'has_more' => $endIndex < $totalFiles,
                'percent' => $percent,
                'next_batch' => $batchNumber + 1,
            ];

        } catch (\Exception $e) {
            Log::error("Extraction failed", ['batch' => $batchNumber, 'error' => $e->getMessage()]);
            $this->statusManager->recordError('extraction_failed', $e->getMessage(), [
                'batch' => $batchNumber,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build list of files in ZIP (excluding directories)
     */
    protected function buildFileList(): void
    {
        $this->fileList = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            $name = $stat['name'];

            // Skip directories
            if (str_ends_with($name, '/')) {
                continue;
            }

            // Skip system files
            if ($this->shouldSkipFile($name)) {
                continue;
            }

            $this->fileList[] = $name;
        }

        // Update status with total files
        $totalBatches = ceil(count($this->fileList) / Config::get('xgapiclient.update.extraction_batch_size', 100));
        $this->statusManager->updatePhase('extraction', [
            'total_files' => count($this->fileList),
            'total_batches' => $totalBatches,
        ]);
    }

    /**
     * Normalize file path (remove update/ prefix)
     */
    protected function normalizeFilePath(string $path): string
    {
        // Remove common prefixes
        $prefixes = ['update/', 'Update/'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }
        return $path;
    }

    /**
     * Check if file should be skipped
     */
    protected function shouldSkipFile(string $path): bool
    {
        $filename = basename($path);

        // Skip dot files (except .htaccess)
        if (str_starts_with($filename, '.') && $filename !== '.htaccess') {
            return true;
        }

        // Skip macOS files
        if ($filename === '.DS_Store' || str_contains($path, '__MACOSX')) {
            return true;
        }

        return false;
    }

    /**
     * Get total file count in ZIP
     */
    public function getTotalFiles(): int
    {
        return count($this->fileList);
    }

    /**
     * Close ZIP file
     */
    public function close(): void
    {
        if ($this->zipOpened) {
            $this->zip->close();
            $this->zipOpened = false;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
```

### Service: BatchReplacer

```php
<?php

namespace Xgenious\XgApiClient\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BatchReplacer
{
    protected UpdateStatusManager $statusManager;
    protected array $fileList = [];
    protected bool $listBuilt = false;
    protected array $skipFiles;
    protected array $skipDirectories;

    public function __construct(UpdateStatusManager $statusManager)
    {
        $this->statusManager = $statusManager;

        // Default skip lists
        $this->skipFiles = [
            '.env',
            '.htaccess',
            'dynamic-style.css',
            'dynamic-script.js',
            '.DS_Store',
            'phpunit.xml',
            'phpstan.neon',
        ];

        $this->skipDirectories = [
            'lang',
            'custom-fonts',
            '.git',
            '.idea',
            '.vscode',
            '.fleet',
            'node_modules',
        ];
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
            if (!$this->listBuilt) {
                $this->buildFileList($paths['extracted']);
            }

            // Enable maintenance mode on first batch
            if ($batchNumber === 0) {
                Artisan::call('down');
                $this->statusManager->update(['maintenance_mode' => true]);
                $this->statusManager->addLog('Maintenance mode enabled');
            }

            $totalFiles = count($this->fileList);
            $startIndex = $batchNumber * $batchSize;
            $endIndex = min($startIndex + $batchSize, $totalFiles);

            if ($startIndex >= $totalFiles) {
                return [
                    'success' => true,
                    'replaced' => 0,
                    'replaced_total' => $totalFiles,
                    'total_files' => $totalFiles,
                    'has_more' => false,
                    'percent' => 100,
                ];
            }

            $replacedInBatch = 0;
            $skippedInBatch = 0;

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

                // Ensure destination directory exists
                $destDir = dirname($destPath);
                if (!File::isDirectory($destDir)) {
                    File::makeDirectory($destDir, 0755, true);
                }

                // Backup original file if enabled and exists
                if (Config::get('xgapiclient.update.enable_backup', false) && File::exists($destPath)) {
                    $this->backupFile($destPath, $relativePath, $paths['backup']);
                }

                // Copy file
                File::copy($sourcePath, $destPath);
                $replacedInBatch++;

                // Update current file in status
                $this->statusManager->updatePhase('replacement', [
                    'current_file' => $relativePath,
                ]);
            }

            $totalReplaced = min($endIndex, $totalFiles);
            $percent = round(($totalReplaced / $totalFiles) * 100);

            // Update status
            $status = $this->statusManager->getStatus();
            $this->statusManager->updatePhase('replacement', [
                'status' => 'in_progress',
                'replaced_files' => ($status['replacement']['replaced_files'] ?? 0) + $replacedInBatch,
                'skipped_files' => ($status['replacement']['skipped_files'] ?? 0) + $skippedInBatch,
                'current_batch' => $batchNumber,
                'percent' => $percent,
            ]);

            if ($batchNumber % 5 === 0) {
                $this->statusManager->addLog("Replaced {$totalReplaced} of {$totalFiles} files ({$percent}%)");
            }

            return [
                'success' => true,
                'replaced' => $replacedInBatch,
                'skipped' => $skippedInBatch,
                'replaced_total' => $totalReplaced,
                'total_files' => $totalFiles,
                'has_more' => $endIndex < $totalFiles,
                'percent' => $percent,
                'next_batch' => $batchNumber + 1,
            ];

        } catch (\Exception $e) {
            Log::error("Replacement failed", ['batch' => $batchNumber, 'error' => $e->getMessage()]);
            $this->statusManager->recordError('replacement_failed', $e->getMessage(), [
                'batch' => $batchNumber,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build list of files to replace
     */
    protected function buildFileList(string $extractedPath): void
    {
        $this->fileList = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractedPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relativePath = str_replace($extractedPath . '/', '', $file->getPathname());
            $this->fileList[] = $relativePath;
        }

        $this->listBuilt = true;

        // Update status
        $totalBatches = ceil(count($this->fileList) / Config::get('xgapiclient.update.replacement_batch_size', 50));
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
        if (in_array($filename, $this->skipFiles)) {
            return true;
        }

        // Check skip directories
        foreach ($this->skipDirectories as $skipDir) {
            if (str_contains($relativePath, "/{$skipDir}/") || str_starts_with($relativePath, "{$skipDir}/")) {
                return true;
            }
        }

        // Skip .git folder
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
        // Handle special directories
        if (str_starts_with($relativePath, 'public/')) {
            // Goes to Laravel public directory
            return public_path(substr($relativePath, 7));
        }

        if (str_starts_with($relativePath, '__rootFiles/')) {
            // Goes to Laravel root
            return base_path(substr($relativePath, 12));
        }

        if (str_starts_with($relativePath, 'custom/')) {
            // Custom files - check change-logs.json for mapping
            // For now, skip custom folder files
            return null;
        }

        // Default: relative to base path
        return base_path($relativePath);
    }

    /**
     * Backup a file before replacing
     */
    protected function backupFile(string $originalPath, string $relativePath, string $backupDir): void
    {
        $backupPath = $backupDir . '/' . $relativePath;
        $backupFileDir = dirname($backupPath);

        if (!File::isDirectory($backupFileDir)) {
            File::makeDirectory($backupFileDir, 0755, true);
        }

        File::copy($originalPath, $backupPath);
    }

    /**
     * Set custom skip files
     */
    public function setSkipFiles(array $files): void
    {
        $this->skipFiles = $files;
    }

    /**
     * Set custom skip directories
     */
    public function setSkipDirectories(array $directories): void
    {
        $this->skipDirectories = $directories;
    }
}
```

---

## Controllers

### Controller: UpdateStatusController

```php
<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\UpdateStatusManager;

class UpdateStatusController extends Controller
{
    protected UpdateStatusManager $statusManager;

    public function __construct(UpdateStatusManager $statusManager)
    {
        $this->statusManager = $statusManager;
    }

    /**
     * GET /xg-update/status
     * Get current update status
     */
    public function status(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No update in progress',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * GET /xg-update/check
     * Check for available updates
     */
    public function check(): JsonResponse
    {
        $licenseKey = $this->getLicenseKey();
        $productUid = $this->getProductUid();
        $currentVersion = $this->getCurrentVersion();

        if (!$licenseKey || !$productUid) {
            return response()->json([
                'success' => false,
                'message' => 'License not activated',
            ], 400);
        }

        $baseUrl = Config::get('xgapiclient.base_api_url');
        $url = "{$baseUrl}/api/v2/update-info/{$licenseKey}/{$productUid}";

        try {
            $response = Http::timeout(30)
                ->withHeaders(['X-Site-Url' => url('/')])
                ->get($url, ['current_version' => $currentVersion]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to check for updates',
                ], $response->status());
            }

            $data = $response->json('data');

            // Check if can resume existing update
            $canResume = $this->statusManager->canResume();
            $resumePoint = $canResume ? $this->statusManager->getResumePoint() : null;

            return response()->json([
                'success' => true,
                'data' => array_merge($data, [
                    'can_resume' => $canResume,
                    'resume_point' => $resumePoint,
                ]),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to update server: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /xg-update/reset
     * Reset update status for fresh start
     */
    public function reset(): JsonResponse
    {
        $this->statusManager->reset();

        return response()->json([
            'success' => true,
            'message' => 'Update status reset',
        ]);
    }

    /**
     * POST /xg-update/cancel
     * Cancel ongoing update
     */
    public function cancel(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if ($status && $status['maintenance_mode']) {
            \Artisan::call('up');
        }

        $this->statusManager->reset();

        return response()->json([
            'success' => true,
            'message' => 'Update cancelled',
        ]);
    }

    /**
     * GET /xg-update/progress
     * Show progress page
     */
    public function progressPage()
    {
        $status = $this->statusManager->getStatus();

        return view('XgApiClient::v2.update-progress', [
            'status' => $status,
            'licenseKey' => $this->getLicenseKey(),
            'productUid' => $this->getProductUid(),
        ]);
    }

    /**
     * Get license key from database
     */
    protected function getLicenseKey(): ?string
    {
        return \DB::table('xg_ftp_infos')->value('item_license_key');
    }

    /**
     * Get product UID from config or database
     */
    protected function getProductUid(): ?string
    {
        // This should come from your product configuration
        return Config::get('xgapiclient.product_uid');
    }

    /**
     * Get current version
     */
    protected function getCurrentVersion(): string
    {
        return \DB::table('xg_ftp_infos')->value('item_version') ?? '0.0.0';
    }
}
```

### Controller: ChunkController

```php
<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\ChunkDownloader;
use Xgenious\XgApiClient\Services\ChunkMerger;
use Xgenious\XgApiClient\Services\UpdateStatusManager;

class ChunkController extends Controller
{
    protected UpdateStatusManager $statusManager;
    protected ChunkDownloader $downloader;
    protected ChunkMerger $merger;

    public function __construct(
        UpdateStatusManager $statusManager,
        ChunkDownloader $downloader,
        ChunkMerger $merger
    ) {
        $this->statusManager = $statusManager;
        $this->downloader = $downloader;
        $this->merger = $merger;
    }

    /**
     * POST /xg-update/download-chunk
     * Download a single chunk
     */
    public function download(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chunk_index' => 'required|integer|min:0',
        ]);

        $licenseKey = $this->getLicenseKey();
        $productUid = $this->getProductUid();

        if (!$licenseKey || !$productUid) {
            return response()->json([
                'success' => false,
                'message' => 'License not configured',
            ], 400);
        }

        // Initialize status if not exists
        $status = $this->statusManager->getStatus();
        if (!$status) {
            // Need to initialize first - this shouldn't happen in normal flow
            return response()->json([
                'success' => false,
                'message' => 'Update not initialized. Call /xg-update/check first.',
            ], 400);
        }

        // Update phase
        $this->statusManager->update(['phase' => 'downloading']);

        $result = $this->downloader->download(
            $validated['chunk_index'],
            $licenseKey,
            $productUid
        );

        return response()->json($result);
    }

    /**
     * POST /xg-update/verify-chunk
     * Verify a downloaded chunk
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chunk_index' => 'required|integer|min:0',
            'expected_hash' => 'required|string',
        ]);

        $isValid = $this->downloader->verify(
            $validated['chunk_index'],
            $validated['expected_hash']
        );

        return response()->json([
            'success' => true,
            'valid' => $isValid,
            'chunk_index' => $validated['chunk_index'],
        ]);
    }

    /**
     * POST /xg-update/merge-chunks
     * Merge all chunks into single ZIP
     */
    public function merge(): JsonResponse
    {
        $result = $this->merger->merge();

        if ($result['success']) {
            // Cleanup chunks after successful merge
            $this->merger->cleanupChunks();
        }

        return response()->json($result);
    }

    /**
     * POST /xg-update/download-selective
     * Download only changed files (incremental update)
     */
    public function downloadSelective(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|string',
        ]);

        // TODO: Implement selective download
        // This calls the license server's selective download endpoint

        return response()->json([
            'success' => false,
            'message' => 'Selective download not yet implemented',
        ], 501);
    }

    protected function getLicenseKey(): ?string
    {
        return \DB::table('xg_ftp_infos')->value('item_license_key');
    }

    protected function getProductUid(): ?string
    {
        return \Config::get('xgapiclient.product_uid');
    }
}
```

### Controller: ExtractionController

```php
<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\BatchExtractor;
use Xgenious\XgApiClient\Services\UpdateStatusManager;

class ExtractionController extends Controller
{
    protected UpdateStatusManager $statusManager;
    protected BatchExtractor $extractor;

    public function __construct(UpdateStatusManager $statusManager, BatchExtractor $extractor)
    {
        $this->statusManager = $statusManager;
        $this->extractor = $extractor;
    }

    /**
     * POST /xg-update/extract-batch
     * Extract a batch of files from ZIP
     */
    public function extractBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch' => 'required|integer|min:0',
            'size' => 'nullable|integer|min:1|max:500',
        ]);

        // Update phase
        $this->statusManager->update(['phase' => 'extraction']);

        $result = $this->extractor->extractBatch(
            $validated['batch'],
            $validated['size'] ?? null
        );

        // Mark extraction complete if no more batches
        if ($result['success'] && !$result['has_more']) {
            $this->statusManager->updatePhase('extraction', ['status' => 'completed']);
            $this->statusManager->addLog('File extraction completed');
        }

        return response()->json($result);
    }
}
```

### Controller: ReplacementController

```php
<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\BatchReplacer;
use Xgenious\XgApiClient\Services\UpdateStatusManager;

class ReplacementController extends Controller
{
    protected UpdateStatusManager $statusManager;
    protected BatchReplacer $replacer;

    public function __construct(UpdateStatusManager $statusManager, BatchReplacer $replacer)
    {
        $this->statusManager = $statusManager;
        $this->replacer = $replacer;
    }

    /**
     * POST /xg-update/replace-batch
     * Replace a batch of files
     */
    public function replaceBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch' => 'required|integer|min:0',
            'size' => 'nullable|integer|min:1|max:200',
        ]);

        // Update phase
        $this->statusManager->update(['phase' => 'replacement']);

        $result = $this->replacer->replaceBatch(
            $validated['batch'],
            $validated['size'] ?? null
        );

        // Mark replacement complete if no more batches
        if ($result['success'] && !$result['has_more']) {
            $this->statusManager->updatePhase('replacement', ['status' => 'completed']);
            $this->statusManager->addLog('File replacement completed');
        }

        return response()->json($result);
    }
}
```

### Controller: MigrationController

```php
<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Xgenious\XgApiClient\CacheCleaner;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\UpdateStatusManager;

class MigrationController extends Controller
{
    protected UpdateStatusManager $statusManager;

    public function __construct(UpdateStatusManager $statusManager)
    {
        $this->statusManager = $statusManager;
    }

    /**
     * POST /xg-update/migrate
     * Run database migrations
     */
    public function run(): JsonResponse
    {
        $this->statusManager->update(['phase' => 'migration']);
        $this->statusManager->updatePhase('migration', ['status' => 'in_progress']);
        $this->statusManager->addLog('Running database migrations...');

        try {
            // Set environment to local for migrations
            $this->setEnvValue(['APP_ENV' => 'local']);

            // Run migrations
            Artisan::call('migrate', ['--force' => true]);
            $migrationsRun = $this->countMigrationsRun();

            $this->statusManager->updatePhase('migration', [
                'migrations_run' => $migrationsRun,
            ]);
            $this->statusManager->addLog("Ran {$migrationsRun} migrations");

            // Run seeders
            try {
                Artisan::call('db:seed', ['--force' => true]);
                $this->statusManager->updatePhase('migration', ['seeders_run' => 1]);
                $this->statusManager->addLog('Database seeded');
            } catch (\Exception $e) {
                // Seeders are optional
            }

            // Handle multi-tenant if applicable
            $isTenant = $this->isTenantApplication();
            if ($isTenant) {
                $this->runTenantMigrations();
            }

            // Clear caches
            Artisan::call('optimize:clear');

            // Set environment back to production
            $this->setEnvValue(['APP_ENV' => 'production']);

            // Update version in database
            $status = $this->statusManager->getStatus();
            $this->updateVersion($status['version']['target']);

            $this->statusManager->updatePhase('migration', ['status' => 'completed']);
            $this->statusManager->addLog('Database migrations completed');

            return response()->json([
                'success' => true,
                'migrations_run' => $migrationsRun,
            ]);

        } catch (\Exception $e) {
            $this->setEnvValue(['APP_ENV' => 'production']);

            $this->statusManager->recordError('migration_failed', $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /xg-update/cleanup
     * Cleanup temporary files
     */
    public function cleanup(): JsonResponse
    {
        $this->statusManager->addLog('Cleaning up...');

        $paths = $this->statusManager->getPaths();

        try {
            // Delete extracted files
            if (File::isDirectory($paths['extracted'])) {
                File::deleteDirectory($paths['extracted']);
            }

            // Delete ZIP file
            if (File::exists($paths['zip'])) {
                File::delete($paths['zip']);
            }

            // Delete chunks directory (should already be deleted)
            if (File::isDirectory($paths['chunks'])) {
                File::deleteDirectory($paths['chunks']);
            }

            // Clear Laravel caches
            CacheCleaner::clearBootstrapCache();
            CacheCleaner::clearAllCaches();

            $this->statusManager->addLog('Cleanup completed');

            return response()->json([
                'success' => true,
                'message' => 'Cleanup completed',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /xg-update/finalize
     * Finalize update and bring site back online
     */
    public function finalize(): JsonResponse
    {
        try {
            // Bring site back online
            Artisan::call('up');
            $this->statusManager->update(['maintenance_mode' => false]);

            // Mark update complete
            $this->statusManager->markComplete();

            // Get final status
            $status = $this->statusManager->getStatus();

            return response()->json([
                'success' => true,
                'message' => 'Update completed successfully',
                'new_version' => $status['version']['target'],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Run tenant migrations
     */
    protected function runTenantMigrations(): void
    {
        if (!class_exists('App\Models\Tenant')) {
            return;
        }

        $tenants = Tenant::all();
        $total = $tenants->count();
        $completed = 0;

        $this->statusManager->updatePhase('migration', [
            'tenant_progress' => ['total' => $total, 'completed' => 0],
        ]);

        $tenants->chunk(50, function ($chunk) use (&$completed, $total) {
            foreach ($chunk as $tenant) {
                try {
                    Config::set("database.connections.mysql.engine", "InnoDB");
                    Artisan::call('tenants:migrate', [
                        '--force' => true,
                        '--tenants' => $tenant->id,
                    ]);
                    $completed++;

                    $this->statusManager->updatePhase('migration', [
                        'tenant_progress' => ['total' => $total, 'completed' => $completed],
                    ]);
                } catch (\Exception $e) {
                    // Log but continue with other tenants
                }
            }
        });

        $this->statusManager->addLog("Migrated {$completed} of {$total} tenants");
    }

    /**
     * Check if this is a tenant application
     */
    protected function isTenantApplication(): bool
    {
        return class_exists('App\Models\Tenant') &&
               DB::table('xg_ftp_infos')->value('is_tenant') == 1;
    }

    /**
     * Update version in database
     */
    protected function updateVersion(string $version): void
    {
        $version = trim($version, 'vV-');

        DB::table('xg_ftp_infos')->update([
            'item_version' => $version,
        ]);

        // Also update static option if function exists
        if (function_exists('update_static_option')) {
            update_static_option('site_script_version', $version);
        }
    }

    /**
     * Count migrations that were run
     */
    protected function countMigrationsRun(): int
    {
        $output = Artisan::output();
        preg_match_all('/Migrating/', $output, $matches);
        return count($matches[0]);
    }

    /**
     * Set environment value
     */
    protected function setEnvValue(array $values): void
    {
        if (function_exists('setEnvValue')) {
            setEnvValue($values);
        } elseif (function_exists('XGsetEnvValue')) {
            XGsetEnvValue($values);
        }
    }
}
```

---

## JavaScript Update Manager

### public/vendor/xgapiclient/js/update-manager.js

```javascript
/**
 * XgApiClient Update Manager
 * Handles chunked downloads and update orchestration
 */
class XgUpdateManager {
    constructor(options = {}) {
        this.baseUrl = options.baseUrl || '/xg-update';
        this.licenseKey = options.licenseKey || '';
        this.productUid = options.productUid || '';
        this.csrfToken = options.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';

        // Callbacks
        this.onProgress = options.onProgress || (() => {});
        this.onPhaseChange = options.onPhaseChange || (() => {});
        this.onLog = options.onLog || (() => {});
        this.onError = options.onError || (() => {});
        this.onComplete = options.onComplete || (() => {});

        // Settings
        this.maxRetries = options.maxRetries || 3;
        this.retryDelay = options.retryDelay || 2000;

        // State
        this.isRunning = false;
        this.isCancelled = false;
        this.updateInfo = null;
    }

    /**
     * Make API request
     */
    async request(endpoint, method = 'GET', data = null) {
        const url = `${this.baseUrl}${endpoint}`;
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
            },
        };

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(url, options);
        const json = await response.json();

        if (!response.ok) {
            throw new Error(json.message || `Request failed: ${response.status}`);
        }

        return json;
    }

    /**
     * Check for updates
     */
    async checkForUpdate() {
        this.onLog('Checking for updates...');

        const result = await this.request('/check');
        this.updateInfo = result.data;

        return result.data;
    }

    /**
     * Start the update process
     */
    async startUpdate() {
        if (this.isRunning) {
            throw new Error('Update already in progress');
        }

        this.isRunning = true;
        this.isCancelled = false;

        try {
            // Phase 1: Check for updates
            this.onPhaseChange('checking', 'Checking for updates...');
            const updateInfo = await this.checkForUpdate();

            if (!updateInfo.update_available) {
                this.onComplete({ status: 'no_update', message: 'Already up to date' });
                this.isRunning = false;
                return;
            }

            // Check if resuming
            if (updateInfo.can_resume) {
                const shouldResume = await this.confirmResume(updateInfo.resume_point);
                if (!shouldResume) {
                    await this.request('/reset', 'POST');
                }
            }

            // Phase 2: Download chunks
            this.onPhaseChange('downloading', 'Downloading update...');
            await this.downloadChunks(updateInfo.total_chunks);

            if (this.isCancelled) return;

            // Phase 3: Merge chunks
            this.onPhaseChange('merging', 'Merging downloaded files...');
            await this.mergeChunks();

            if (this.isCancelled) return;

            // Phase 4: Extract files
            this.onPhaseChange('extracting', 'Extracting files...');
            await this.extractFiles();

            if (this.isCancelled) return;

            // Phase 5: Replace files
            this.onPhaseChange('replacing', 'Updating files...');
            await this.replaceFiles();

            if (this.isCancelled) return;

            // Phase 6: Run migrations
            this.onPhaseChange('migrating', 'Running database updates...');
            await this.runMigrations();

            if (this.isCancelled) return;

            // Phase 7: Cleanup
            this.onPhaseChange('cleanup', 'Cleaning up...');
            await this.cleanup();

            // Phase 8: Finalize
            this.onPhaseChange('finalizing', 'Finalizing update...');
            await this.finalize();

            // Done!
            this.onComplete({
                status: 'success',
                message: `Successfully updated to version ${updateInfo.latest_version}`,
                version: updateInfo.latest_version,
            });

        } catch (error) {
            this.onError(error);
            throw error;
        } finally {
            this.isRunning = false;
        }
    }

    /**
     * Download all chunks
     */
    async downloadChunks(totalChunks) {
        for (let i = 0; i < totalChunks; i++) {
            if (this.isCancelled) return;

            let retries = this.maxRetries;
            let success = false;

            while (retries > 0 && !success) {
                try {
                    this.onLog(`Downloading chunk ${i + 1} of ${totalChunks}...`);

                    const result = await this.request('/download-chunk', 'POST', {
                        chunk_index: i,
                    });

                    if (result.success) {
                        success = true;
                        this.onProgress({
                            phase: 'download',
                            current: i + 1,
                            total: totalChunks,
                            percent: Math.round(((i + 1) / totalChunks) * 100),
                            chunkSize: result.chunk_size,
                        });
                    } else {
                        throw new Error(result.error || 'Download failed');
                    }
                } catch (error) {
                    retries--;
                    if (retries === 0) {
                        throw new Error(`Failed to download chunk ${i} after ${this.maxRetries} attempts: ${error.message}`);
                    }
                    this.onLog(`Chunk ${i} failed, retrying... (${retries} attempts left)`);
                    await this.sleep(this.retryDelay);
                }
            }
        }

        this.onLog('All chunks downloaded successfully');
    }

    /**
     * Merge downloaded chunks
     */
    async mergeChunks() {
        this.onLog('Merging chunks into single file...');

        const result = await this.request('/merge-chunks', 'POST');

        if (!result.success) {
            throw new Error(result.error || 'Failed to merge chunks');
        }

        this.onLog(`Chunks merged (${this.formatBytes(result.zip_size)})`);
        this.onProgress({ phase: 'merge', percent: 100 });
    }

    /**
     * Extract files in batches
     */
    async extractFiles() {
        let batch = 0;
        let hasMore = true;

        while (hasMore && !this.isCancelled) {
            const result = await this.request('/extract-batch', 'POST', {
                batch: batch,
            });

            if (!result.success) {
                throw new Error(result.error || 'Extraction failed');
            }

            this.onProgress({
                phase: 'extraction',
                current: result.extracted_total,
                total: result.total_files,
                percent: result.percent,
            });

            hasMore = result.has_more;
            batch++;
        }

        this.onLog('Files extracted successfully');
    }

    /**
     * Replace files in batches
     */
    async replaceFiles() {
        let batch = 0;
        let hasMore = true;

        while (hasMore && !this.isCancelled) {
            const result = await this.request('/replace-batch', 'POST', {
                batch: batch,
            });

            if (!result.success) {
                throw new Error(result.error || 'File replacement failed');
            }

            this.onProgress({
                phase: 'replacement',
                current: result.replaced_total,
                total: result.total_files,
                percent: result.percent,
            });

            hasMore = result.has_more;
            batch++;
        }

        this.onLog('Files replaced successfully');
    }

    /**
     * Run database migrations
     */
    async runMigrations() {
        this.onLog('Running database migrations...');

        const result = await this.request('/migrate', 'POST');

        if (!result.success) {
            throw new Error(result.error || 'Migration failed');
        }

        this.onLog(`Ran ${result.migrations_run} migrations`);
        this.onProgress({ phase: 'migration', percent: 100 });
    }

    /**
     * Cleanup temporary files
     */
    async cleanup() {
        this.onLog('Cleaning up temporary files...');

        const result = await this.request('/cleanup', 'POST');

        if (!result.success) {
            throw new Error(result.error || 'Cleanup failed');
        }

        this.onLog('Cleanup completed');
    }

    /**
     * Finalize update
     */
    async finalize() {
        this.onLog('Finalizing update...');

        const result = await this.request('/finalize', 'POST');

        if (!result.success) {
            throw new Error(result.error || 'Finalization failed');
        }

        this.onLog('Update finalized');
    }

    /**
     * Cancel ongoing update
     */
    async cancel() {
        this.isCancelled = true;

        try {
            await this.request('/cancel', 'POST');
            this.onLog('Update cancelled');
        } catch (error) {
            console.error('Failed to cancel update:', error);
        }

        this.isRunning = false;
    }

    /**
     * Get current status
     */
    async getStatus() {
        const result = await this.request('/status');
        return result.data;
    }

    /**
     * Confirm resume with user
     */
    async confirmResume(resumePoint) {
        // This should be overridden by the UI
        return confirm(`A previous update was interrupted at ${resumePoint.phase}. Resume?`);
    }

    /**
     * Sleep helper
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * Format bytes to human readable
     */
    formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return `${bytes.toFixed(2)} ${units[i]}`;
    }
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = XgUpdateManager;
}
```

---

## Blade Views

### resources/views/v2/update-progress.blade.php

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>System Update</title>
    <link href="{{ asset('vendor/xgapiclient/css/update-progress.css') }}" rel="stylesheet">
</head>
<body>
    <div class="update-container">
        <div class="update-header">
            <h1>System Update</h1>
            <div id="version-info">
                <span class="current-version">Current: <strong>{{ $status['version']['current'] ?? 'Unknown' }}</strong></span>
                <span class="arrow">→</span>
                <span class="target-version">Target: <strong id="target-version">Checking...</strong></span>
            </div>
        </div>

        <div class="update-content">
            <!-- Status Steps -->
            <div class="update-steps" id="update-steps">
                <div class="step" data-step="checking">
                    <div class="step-icon">○</div>
                    <div class="step-content">
                        <div class="step-title">Check for updates</div>
                        <div class="step-status">Pending</div>
                    </div>
                </div>
                <div class="step" data-step="downloading">
                    <div class="step-icon">○</div>
                    <div class="step-content">
                        <div class="step-title">Download update</div>
                        <div class="step-status">Pending</div>
                        <div class="step-progress" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 0%"></div>
                            </div>
                            <div class="progress-text">0%</div>
                        </div>
                    </div>
                </div>
                <div class="step" data-step="merging">
                    <div class="step-icon">○</div>
                    <div class="step-content">
                        <div class="step-title">Merge files</div>
                        <div class="step-status">Pending</div>
                    </div>
                </div>
                <div class="step" data-step="extracting">
                    <div class="step-icon">○</div>
                    <div class="step-content">
                        <div class="step-title">Extract files</div>
                        <div class="step-status">Pending</div>
                        <div class="step-progress" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 0%"></div>
                            </div>
                            <div class="progress-text">0%</div>
                        </div>
                    </div>
                </div>
                <div class="step" data-step="replacing">
                    <div class="step-icon">○</div>
                    <div class="step-content">
                        <div class="step-title">Update files</div>
                        <div class="step-status">Pending</div>
                        <div class="step-progress" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 0%"></div>
                            </div>
                            <div class="progress-text">0%</div>
                        </div>
                    </div>
                </div>
                <div class="step" data-step="migrating">
                    <div class="step-icon">○</div>
                    <div class="step-content">
                        <div class="step-title">Database migration</div>
                        <div class="step-status">Pending</div>
                    </div>
                </div>
                <div class="step" data-step="cleanup">
                    <div class="step-icon">○</div>
                    <div class="step-content">
                        <div class="step-title">Cleanup</div>
                        <div class="step-status">Pending</div>
                    </div>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="activity-log">
                <div class="log-header">
                    <span>Activity Log</span>
                    <button type="button" id="clear-log" class="btn-small">Clear</button>
                </div>
                <div class="log-content" id="log-content">
                    <div class="log-entry">Initializing...</div>
                </div>
            </div>
        </div>

        <div class="update-footer">
            <div class="warning-message" id="warning-message">
                ⚠️ Do not close this page during the update process
            </div>
            <div class="action-buttons">
                <button type="button" id="btn-start" class="btn btn-primary">Start Update</button>
                <button type="button" id="btn-cancel" class="btn btn-danger" style="display: none;">Cancel</button>
            </div>
        </div>
    </div>

    <script src="{{ asset('vendor/xgapiclient/js/update-manager.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const updateManager = new XgUpdateManager({
                baseUrl: '/xg-update',
                licenseKey: '{{ $licenseKey }}',
                productUid: '{{ $productUid }}',

                onProgress: function(data) {
                    updateProgress(data);
                },

                onPhaseChange: function(phase, message) {
                    updatePhase(phase, message);
                },

                onLog: function(message) {
                    addLogEntry(message);
                },

                onError: function(error) {
                    showError(error.message);
                },

                onComplete: function(result) {
                    showComplete(result);
                }
            });

            // UI Elements
            const btnStart = document.getElementById('btn-start');
            const btnCancel = document.getElementById('btn-cancel');
            const logContent = document.getElementById('log-content');
            const clearLog = document.getElementById('clear-log');

            // Event Listeners
            btnStart.addEventListener('click', async function() {
                btnStart.style.display = 'none';
                btnCancel.style.display = 'inline-block';

                try {
                    await updateManager.startUpdate();
                } catch (error) {
                    btnStart.style.display = 'inline-block';
                    btnCancel.style.display = 'none';
                }
            });

            btnCancel.addEventListener('click', async function() {
                if (confirm('Are you sure you want to cancel the update?')) {
                    await updateManager.cancel();
                    btnStart.style.display = 'inline-block';
                    btnCancel.style.display = 'none';
                }
            });

            clearLog.addEventListener('click', function() {
                logContent.innerHTML = '';
            });

            // UI Update Functions
            function updatePhase(phase, message) {
                // Reset all steps
                document.querySelectorAll('.step').forEach(step => {
                    step.classList.remove('active', 'completed');
                });

                // Mark previous steps as completed
                const steps = ['checking', 'downloading', 'merging', 'extracting', 'replacing', 'migrating', 'cleanup'];
                const currentIndex = steps.indexOf(phase);

                steps.forEach((stepName, index) => {
                    const stepEl = document.querySelector(`[data-step="${stepName}"]`);
                    if (index < currentIndex) {
                        stepEl.classList.add('completed');
                        stepEl.querySelector('.step-icon').textContent = '✓';
                        stepEl.querySelector('.step-status').textContent = 'Complete';
                    } else if (index === currentIndex) {
                        stepEl.classList.add('active');
                        stepEl.querySelector('.step-icon').textContent = '●';
                        stepEl.querySelector('.step-status').textContent = message || 'In Progress';

                        // Show progress bar for steps that have one
                        const progressEl = stepEl.querySelector('.step-progress');
                        if (progressEl) {
                            progressEl.style.display = 'block';
                        }
                    }
                });
            }

            function updateProgress(data) {
                const stepEl = document.querySelector(`[data-step="${getStepFromPhase(data.phase)}"]`);
                if (!stepEl) return;

                const progressEl = stepEl.querySelector('.step-progress');
                if (progressEl) {
                    progressEl.style.display = 'block';
                    progressEl.querySelector('.progress-fill').style.width = `${data.percent}%`;
                    progressEl.querySelector('.progress-text').textContent = `${data.percent}%`;
                }

                stepEl.querySelector('.step-status').textContent = `${data.current} / ${data.total}`;
            }

            function getStepFromPhase(phase) {
                const mapping = {
                    'download': 'downloading',
                    'merge': 'merging',
                    'extraction': 'extracting',
                    'replacement': 'replacing',
                    'migration': 'migrating'
                };
                return mapping[phase] || phase;
            }

            function addLogEntry(message) {
                const entry = document.createElement('div');
                entry.className = 'log-entry';
                entry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
                logContent.appendChild(entry);
                logContent.scrollTop = logContent.scrollHeight;
            }

            function showError(message) {
                addLogEntry(`ERROR: ${message}`);
                document.getElementById('warning-message').innerHTML = `❌ Error: ${message}`;
                document.getElementById('warning-message').classList.add('error');
            }

            function showComplete(result) {
                document.querySelectorAll('.step').forEach(step => {
                    step.classList.remove('active');
                    step.classList.add('completed');
                    step.querySelector('.step-icon').textContent = '✓';
                });

                document.getElementById('warning-message').innerHTML = `✅ ${result.message}`;
                document.getElementById('warning-message').classList.remove('error');
                document.getElementById('warning-message').classList.add('success');

                btnCancel.style.display = 'none';
                btnStart.textContent = 'Go to Dashboard';
                btnStart.style.display = 'inline-block';
                btnStart.onclick = function() {
                    window.location.href = '/admin';
                };
            }

            // Initial check
            updateManager.checkForUpdate().then(info => {
                if (info) {
                    document.getElementById('target-version').textContent = info.latest_version;
                    if (!info.update_available) {
                        btnStart.textContent = 'Already Up to Date';
                        btnStart.disabled = true;
                    }
                }
            });
        });
    </script>
</body>
</html>
```

---

## CSS Styles

### public/vendor/xgapiclient/css/update-progress.css

```css
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    background: #f5f5f5;
    min-height: 100vh;
    padding: 20px;
}

.update-container {
    max-width: 800px;
    margin: 0 auto;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.update-header {
    background: #2563eb;
    color: white;
    padding: 24px;
}

.update-header h1 {
    font-size: 24px;
    margin-bottom: 8px;
}

#version-info {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    opacity: 0.9;
}

.arrow {
    font-size: 18px;
}

.update-content {
    padding: 24px;
}

.update-steps {
    margin-bottom: 24px;
}

.step {
    display: flex;
    align-items: flex-start;
    padding: 16px;
    border-left: 3px solid #e5e7eb;
    margin-left: 12px;
    position: relative;
}

.step:last-child {
    border-left-color: transparent;
}

.step-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    position: absolute;
    left: -16px;
    color: #6b7280;
}

.step.active .step-icon {
    background: #2563eb;
    color: white;
}

.step.completed .step-icon {
    background: #10b981;
    color: white;
}

.step-content {
    margin-left: 24px;
    flex: 1;
}

.step-title {
    font-weight: 600;
    color: #374151;
    margin-bottom: 4px;
}

.step-status {
    font-size: 13px;
    color: #6b7280;
}

.step.active .step-title {
    color: #2563eb;
}

.step.completed .step-title {
    color: #10b981;
}

.step-progress {
    margin-top: 8px;
}

.progress-bar {
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: #2563eb;
    transition: width 0.3s ease;
}

.progress-text {
    font-size: 12px;
    color: #6b7280;
    margin-top: 4px;
}

.activity-log {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
}

.log-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 600;
    font-size: 14px;
}

.log-content {
    height: 200px;
    overflow-y: auto;
    padding: 12px 16px;
    font-family: monospace;
    font-size: 13px;
    background: #1f2937;
    color: #d1d5db;
}

.log-entry {
    padding: 4px 0;
    border-bottom: 1px solid #374151;
}

.log-entry:last-child {
    border-bottom: none;
}

.update-footer {
    padding: 24px;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}

.warning-message {
    text-align: center;
    padding: 12px;
    background: #fef3c7;
    color: #92400e;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 14px;
}

.warning-message.error {
    background: #fee2e2;
    color: #dc2626;
}

.warning-message.success {
    background: #d1fae5;
    color: #059669;
}

.action-buttons {
    display: flex;
    justify-content: center;
    gap: 12px;
}

.btn {
    padding: 12px 32px;
    border: none;
    border-radius: 6px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-primary:disabled {
    background: #93c5fd;
    cursor: not-allowed;
}

.btn-danger {
    background: #dc2626;
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
}

.btn-small {
    padding: 4px 12px;
    font-size: 12px;
    background: #e5e7eb;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-small:hover {
    background: #d1d5db;
}
```

---

## Testing Checklist

- [ ] Publish assets: `php artisan vendor:publish --tag=xgapiclient-assets`
- [ ] Test `/xg-update/check` endpoint
- [ ] Test `/xg-update/status` endpoint
- [ ] Test chunk download with small file
- [ ] Test chunk download with large file (100MB+)
- [ ] Test merge chunks
- [ ] Test batch extraction
- [ ] Test batch replacement
- [ ] Test migrations
- [ ] Test cleanup
- [ ] Test cancel functionality
- [ ] Test resume after interruption
- [ ] Test error handling
- [ ] Test multi-tenant migrations

---

## Notes

1. **CSRF Protection**: All POST routes require CSRF token
2. **Memory**: Chunks are processed one at a time to minimize memory usage
3. **Timeout**: Each PHP request handles small operations, avoiding timeouts
4. **Resume**: Status file allows resuming interrupted updates
5. **Backward Compatibility**: Old routes still work for non-chunked updates
