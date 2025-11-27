# License Server Implementation Guide - Chunked Update System

## Overview

This document provides complete implementation details for adding chunked download and manifest-based update system to the license server. The goal is to eliminate timeout issues when clients download large update files (100MB+).

## Current System

```
Current Flow:
1. Admin uploads update.zip via dashboard
2. ZIP stored as-is in storage
3. Client requests download → Server streams entire ZIP in one request
4. Large files (100MB+) cause timeout errors
```

## Target System

```
New Flow:
1. Admin uploads update.zip via dashboard
2. Server automatically:
   → Validates ZIP structure
   → Extracts and analyzes all files
   → Generates manifest.json with file hashes
   → Splits ZIP into 10MB chunks
   → Stores everything in database + storage
3. Client can:
   → Fetch manifest to compare files
   → Download individual chunks (10MB each)
   → Request only changed files (incremental update)
```

---

## Database Schema

### Table: `product_version_manifests`

```sql
CREATE TABLE `product_version_manifests` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `version` VARCHAR(50) NOT NULL,
    `manifest_json` LONGTEXT NOT NULL,
    `total_files` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `chunk_size` INT UNSIGNED NOT NULL DEFAULT 10485760,
    `total_chunks` INT UNSIGNED NOT NULL DEFAULT 0,
    `zip_hash` VARCHAR(128) NULL,
    `skip_files` TEXT NULL,
    `skip_directories` TEXT NULL,
    `php_version_required` VARCHAR(20) NULL,
    `mysql_version_required` VARCHAR(20) NULL,
    `status` ENUM('processing', 'ready', 'failed') DEFAULT 'processing',
    `processing_error` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,

    INDEX `idx_product_version` (`product_id`, `version`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table: `product_version_chunks`

```sql
CREATE TABLE `product_version_chunks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `manifest_id` BIGINT UNSIGNED NOT NULL,
    `chunk_index` INT UNSIGNED NOT NULL,
    `chunk_hash` VARCHAR(128) NOT NULL,
    `chunk_size` INT UNSIGNED NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `created_at` TIMESTAMP NULL,

    INDEX `idx_manifest_chunk` (`manifest_id`, `chunk_index`),
    FOREIGN KEY (`manifest_id`) REFERENCES `product_version_manifests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table: `product_version_files`

```sql
CREATE TABLE `product_version_files` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `manifest_id` BIGINT UNSIGNED NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_hash` VARCHAR(128) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL,

    INDEX `idx_manifest_file` (`manifest_id`, `file_path`(255)),
    FOREIGN KEY (`manifest_id`) REFERENCES `product_version_manifests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Storage Structure

```
storage/
└── app/
    └── product-updates/
        └── {product_id}/
            └── {version}/
                ├── original.zip           # Original uploaded ZIP (keep for re-processing)
                ├── manifest.json          # Generated manifest (also stored in DB)
                └── chunks/
                    ├── chunk_000.bin      # First 10MB
                    ├── chunk_001.bin      # Second 10MB
                    ├── chunk_002.bin      # Third 10MB
                    └── ...                # Continue until EOF
```

---

## Laravel Migrations

### Migration 1: Create product_version_manifests table

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_version_manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('version', 50);
            $table->longText('manifest_json');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedBigInteger('total_size')->default(0);
            $table->unsignedInteger('chunk_size')->default(10485760); // 10MB
            $table->unsignedInteger('total_chunks')->default(0);
            $table->string('zip_hash', 128)->nullable();
            $table->text('skip_files')->nullable();
            $table->text('skip_directories')->nullable();
            $table->string('php_version_required', 20)->nullable();
            $table->string('mysql_version_required', 20)->nullable();
            $table->enum('status', ['processing', 'ready', 'failed'])->default('processing');
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'version']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_version_manifests');
    }
};
```

### Migration 2: Create product_version_chunks table

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_version_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->constrained('product_version_manifests')->onDelete('cascade');
            $table->unsignedInteger('chunk_index');
            $table->string('chunk_hash', 128);
            $table->unsignedInteger('chunk_size');
            $table->string('file_path', 500);
            $table->timestamp('created_at')->nullable();

            $table->index(['manifest_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_version_chunks');
    }
};
```

### Migration 3: Create product_version_files table

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_version_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->constrained('product_version_manifests')->onDelete('cascade');
            $table->string('file_path', 500);
            $table->string('file_hash', 128);
            $table->unsignedInteger('file_size');
            $table->timestamp('created_at')->nullable();

            $table->index(['manifest_id', 'file_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_version_files');
    }
};
```

---

## Eloquent Models

### Model: ProductVersionManifest

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVersionManifest extends Model
{
    protected $fillable = [
        'product_id',
        'version',
        'manifest_json',
        'total_files',
        'total_size',
        'chunk_size',
        'total_chunks',
        'zip_hash',
        'skip_files',
        'skip_directories',
        'php_version_required',
        'mysql_version_required',
        'status',
        'processing_error',
    ];

    protected $casts = [
        'total_files' => 'integer',
        'total_size' => 'integer',
        'chunk_size' => 'integer',
        'total_chunks' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ProductVersionChunk::class, 'manifest_id')->orderBy('chunk_index');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProductVersionFile::class, 'manifest_id');
    }

    public function getManifestAttribute(): array
    {
        return json_decode($this->manifest_json, true) ?? [];
    }

    public function getSkipFilesArrayAttribute(): array
    {
        return $this->skip_files ? explode(',', $this->skip_files) : [];
    }

    public function getSkipDirectoriesArrayAttribute(): array
    {
        return $this->skip_directories ? explode(',', $this->skip_directories) : [];
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function getStoragePath(): string
    {
        return "product-updates/{$this->product_id}/{$this->version}";
    }
}
```

### Model: ProductVersionChunk

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductVersionChunk extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'manifest_id',
        'chunk_index',
        'chunk_hash',
        'chunk_size',
        'file_path',
        'created_at',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'chunk_size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(ProductVersionManifest::class, 'manifest_id');
    }

    public function getContent(): ?string
    {
        if (Storage::exists($this->file_path)) {
            return Storage::get($this->file_path);
        }
        return null;
    }

    public function getFullPath(): string
    {
        return Storage::path($this->file_path);
    }
}
```

### Model: ProductVersionFile

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVersionFile extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'manifest_id',
        'file_path',
        'file_hash',
        'file_size',
        'created_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(ProductVersionManifest::class, 'manifest_id');
    }
}
```

---

## Service Classes

### Service: UpdateProcessingService

This is the main orchestrator that processes uploaded ZIP files.

```php
<?php

namespace App\Services\UpdateProcessing;

use App\Models\Product;
use App\Models\ProductVersionManifest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UpdateProcessingService
{
    protected ManifestGenerator $manifestGenerator;
    protected ChunkSplitter $chunkSplitter;

    protected int $chunkSize = 10485760; // 10MB

    public function __construct(
        ManifestGenerator $manifestGenerator,
        ChunkSplitter $chunkSplitter
    ) {
        $this->manifestGenerator = $manifestGenerator;
        $this->chunkSplitter = $chunkSplitter;
    }

    /**
     * Process an uploaded update ZIP file
     */
    public function process(
        Product $product,
        string $version,
        UploadedFile $zipFile,
        array $options = []
    ): ProductVersionManifest {

        $storagePath = "product-updates/{$product->id}/{$version}";
        $tempPath = "temp/processing_" . Str::uuid();

        // Create manifest record
        $manifest = ProductVersionManifest::create([
            'product_id' => $product->id,
            'version' => $version,
            'manifest_json' => '{}',
            'chunk_size' => $this->chunkSize,
            'skip_files' => $options['skip_files'] ?? '.env,.htaccess,dynamic-style.css,dynamic-script.js,.DS_Store',
            'skip_directories' => $options['skip_directories'] ?? 'lang,custom-fonts,.git,.idea,.vscode,.fleet',
            'php_version_required' => $options['php_version'] ?? '8.1',
            'mysql_version_required' => $options['mysql_version'] ?? '5.7',
            'status' => 'processing',
        ]);

        try {
            // Step 1: Store original ZIP
            Log::info("Processing update: Storing original ZIP", ['product' => $product->id, 'version' => $version]);
            Storage::makeDirectory($storagePath);
            $zipFile->storeAs($storagePath, 'original.zip');
            $originalZipPath = Storage::path("{$storagePath}/original.zip");

            // Step 2: Validate ZIP
            Log::info("Processing update: Validating ZIP");
            $this->validateZip($originalZipPath);

            // Step 3: Calculate ZIP hash
            $zipHash = hash_file('sha256', $originalZipPath);
            $zipSize = filesize($originalZipPath);

            // Step 4: Extract and generate manifest
            Log::info("Processing update: Generating manifest");
            Storage::makeDirectory($tempPath);
            $extractPath = Storage::path($tempPath);

            $manifestData = $this->manifestGenerator->generate(
                $originalZipPath,
                $extractPath,
                $manifest->skip_files_array,
                $manifest->skip_directories_array
            );

            // Step 5: Split into chunks
            Log::info("Processing update: Splitting into chunks");
            Storage::makeDirectory("{$storagePath}/chunks");
            $chunks = $this->chunkSplitter->split(
                $originalZipPath,
                Storage::path("{$storagePath}/chunks"),
                $this->chunkSize
            );

            // Step 6: Store in database
            Log::info("Processing update: Storing in database");
            DB::transaction(function () use ($manifest, $manifestData, $chunks, $zipHash, $zipSize, $storagePath) {
                // Update manifest record
                $manifest->update([
                    'manifest_json' => json_encode($manifestData),
                    'total_files' => count($manifestData['files']),
                    'total_size' => $zipSize,
                    'total_chunks' => count($chunks),
                    'zip_hash' => $zipHash,
                    'status' => 'ready',
                ]);

                // Store chunk records
                foreach ($chunks as $index => $chunkData) {
                    $manifest->chunks()->create([
                        'chunk_index' => $index,
                        'chunk_hash' => $chunkData['hash'],
                        'chunk_size' => $chunkData['size'],
                        'file_path' => "{$storagePath}/chunks/chunk_" . str_pad($index, 3, '0', STR_PAD_LEFT) . ".bin",
                        'created_at' => now(),
                    ]);
                }

                // Store file records (for selective downloads)
                foreach ($manifestData['files'] as $filePath => $fileData) {
                    $manifest->files()->create([
                        'file_path' => $filePath,
                        'file_hash' => $fileData['hash'],
                        'file_size' => $fileData['size'],
                        'created_at' => now(),
                    ]);
                }
            });

            // Step 7: Save manifest.json file
            Storage::put("{$storagePath}/manifest.json", json_encode($manifestData, JSON_PRETTY_PRINT));

            // Step 8: Cleanup temp files
            Log::info("Processing update: Cleaning up");
            Storage::deleteDirectory($tempPath);

            Log::info("Processing update: Complete", [
                'product' => $product->id,
                'version' => $version,
                'files' => count($manifestData['files']),
                'chunks' => count($chunks),
            ]);

            return $manifest->fresh();

        } catch (\Exception $e) {
            Log::error("Processing update: Failed", [
                'product' => $product->id,
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            $manifest->update([
                'status' => 'failed',
                'processing_error' => $e->getMessage(),
            ]);

            // Cleanup on failure
            Storage::deleteDirectory($tempPath);

            throw $e;
        }
    }

    /**
     * Validate ZIP file structure
     */
    protected function validateZip(string $zipPath): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw new \Exception("Invalid ZIP file: Cannot open (error code: {$result})");
        }

        // Check for path traversal attempts
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (str_contains($filename, '..')) {
                $zip->close();
                throw new \Exception("Invalid ZIP file: Contains path traversal attempt");
            }
        }

        // Check for reasonable file count (prevent zip bombs)
        if ($zip->numFiles > 50000) {
            $zip->close();
            throw new \Exception("Invalid ZIP file: Too many files (max 50,000)");
        }

        $zip->close();
    }

    /**
     * Reprocess an existing version (regenerate chunks and manifest)
     */
    public function reprocess(ProductVersionManifest $manifest): ProductVersionManifest
    {
        $storagePath = $manifest->getStoragePath();
        $originalZipPath = Storage::path("{$storagePath}/original.zip");

        if (!file_exists($originalZipPath)) {
            throw new \Exception("Original ZIP file not found");
        }

        // Delete existing chunks
        Storage::deleteDirectory("{$storagePath}/chunks");
        $manifest->chunks()->delete();
        $manifest->files()->delete();

        // Reprocess
        $manifest->update(['status' => 'processing']);

        // ... (same processing logic as above)

        return $manifest->fresh();
    }
}
```

### Service: ManifestGenerator

```php
<?php

namespace App\Services\UpdateProcessing;

use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ManifestGenerator
{
    /**
     * Generate manifest from ZIP file
     */
    public function generate(
        string $zipPath,
        string $extractPath,
        array $skipFiles = [],
        array $skipDirectories = []
    ): array {
        // Extract ZIP
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \Exception("Cannot open ZIP file");
        }

        $zip->extractTo($extractPath);
        $zip->close();

        // Find the update folder (usually "update/" inside ZIP)
        $updateFolder = $this->findUpdateFolder($extractPath);

        $files = [];
        $totalSize = 0;

        // Iterate through all files
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($updateFolder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $fullPath = $file->getPathname();
            $relativePath = $this->getRelativePath($fullPath, $updateFolder);

            // Check skip rules
            if ($this->shouldSkip($relativePath, $file->getFilename(), $skipFiles, $skipDirectories)) {
                continue;
            }

            $fileHash = hash_file('sha256', $fullPath);
            $fileSize = $file->getSize();

            $files[$relativePath] = [
                'hash' => $fileHash,
                'size' => $fileSize,
            ];

            $totalSize += $fileSize;
        }

        return [
            'version' => basename(dirname($zipPath)),
            'generated_at' => now()->toIso8601String(),
            'total_files' => count($files),
            'total_size' => $totalSize,
            'files' => $files,
            'skip_files' => $skipFiles,
            'skip_directories' => $skipDirectories,
        ];
    }

    /**
     * Find the update folder inside extracted ZIP
     */
    protected function findUpdateFolder(string $extractPath): string
    {
        // Check if "update" folder exists
        if (is_dir("{$extractPath}/update")) {
            return "{$extractPath}/update";
        }

        // Check for single root folder
        $items = scandir($extractPath);
        $items = array_diff($items, ['.', '..']);

        if (count($items) === 1) {
            $singleItem = reset($items);
            $singlePath = "{$extractPath}/{$singleItem}";
            if (is_dir($singlePath)) {
                return $singlePath;
            }
        }

        // Return extract path itself
        return $extractPath;
    }

    /**
     * Get relative path from base
     */
    protected function getRelativePath(string $fullPath, string $basePath): string
    {
        $basePath = rtrim($basePath, '/') . '/';
        return str_replace($basePath, '', $fullPath);
    }

    /**
     * Check if file/directory should be skipped
     */
    protected function shouldSkip(
        string $relativePath,
        string $filename,
        array $skipFiles,
        array $skipDirectories
    ): bool {
        // Skip dot files
        if (str_starts_with($filename, '.') && $filename !== '.htaccess') {
            return true;
        }

        // Check skip files
        foreach ($skipFiles as $skipFile) {
            $skipFile = trim($skipFile);
            if ($filename === $skipFile) {
                return true;
            }
        }

        // Check skip directories
        foreach ($skipDirectories as $skipDir) {
            $skipDir = trim($skipDir);
            if (str_contains($relativePath, "/{$skipDir}/") || str_starts_with($relativePath, "{$skipDir}/")) {
                return true;
            }
        }

        return false;
    }
}
```

### Service: ChunkSplitter

```php
<?php

namespace App\Services\UpdateProcessing;

class ChunkSplitter
{
    /**
     * Split a file into chunks
     *
     * @return array Array of chunk data with hash and size
     */
    public function split(string $sourcePath, string $outputDir, int $chunkSize = 10485760): array
    {
        if (!file_exists($sourcePath)) {
            throw new \Exception("Source file not found: {$sourcePath}");
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $fileSize = filesize($sourcePath);
        $totalChunks = (int) ceil($fileSize / $chunkSize);
        $chunks = [];

        $sourceHandle = fopen($sourcePath, 'rb');
        if (!$sourceHandle) {
            throw new \Exception("Cannot open source file");
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFilename = sprintf('chunk_%03d.bin', $i);
            $chunkPath = "{$outputDir}/{$chunkFilename}";

            // Read chunk
            $chunkData = fread($sourceHandle, $chunkSize);
            $actualSize = strlen($chunkData);

            // Write chunk
            $written = file_put_contents($chunkPath, $chunkData);
            if ($written === false) {
                fclose($sourceHandle);
                throw new \Exception("Failed to write chunk {$i}");
            }

            // Calculate hash
            $chunkHash = hash('sha256', $chunkData);

            $chunks[] = [
                'index' => $i,
                'hash' => $chunkHash,
                'size' => $actualSize,
                'filename' => $chunkFilename,
            ];

            // Free memory
            unset($chunkData);
        }

        fclose($sourceHandle);

        // Verify total size
        $totalChunkSize = array_sum(array_column($chunks, 'size'));
        if ($totalChunkSize !== $fileSize) {
            throw new \Exception("Chunk size mismatch: expected {$fileSize}, got {$totalChunkSize}");
        }

        return $chunks;
    }

    /**
     * Merge chunks back into original file (for verification)
     */
    public function merge(string $outputPath, string $chunksDir): void
    {
        $outputHandle = fopen($outputPath, 'wb');
        if (!$outputHandle) {
            throw new \Exception("Cannot create output file");
        }

        $chunkIndex = 0;
        while (true) {
            $chunkFilename = sprintf('chunk_%03d.bin', $chunkIndex);
            $chunkPath = "{$chunksDir}/{$chunkFilename}";

            if (!file_exists($chunkPath)) {
                break;
            }

            $chunkData = file_get_contents($chunkPath);
            fwrite($outputHandle, $chunkData);
            unset($chunkData);

            $chunkIndex++;
        }

        fclose($outputHandle);
    }
}
```

### Service: SelectiveZipBuilder

```php
<?php

namespace App\Services\UpdateProcessing;

use Illuminate\Support\Str;

class SelectiveZipBuilder
{
    /**
     * Build a ZIP containing only requested files
     */
    public function build(string $originalZipPath, array $requestedFiles): string
    {
        $tempZipPath = storage_path('app/temp/selective_' . Str::uuid() . '.zip');

        // Ensure temp directory exists
        $tempDir = dirname($tempZipPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $sourceZip = new \ZipArchive();
        if ($sourceZip->open($originalZipPath) !== true) {
            throw new \Exception("Cannot open source ZIP");
        }

        $targetZip = new \ZipArchive();
        if ($targetZip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $sourceZip->close();
            throw new \Exception("Cannot create target ZIP");
        }

        // Find the update folder prefix in source ZIP
        $prefix = $this->findUpdatePrefix($sourceZip);

        foreach ($requestedFiles as $filePath) {
            $zipPath = $prefix . $filePath;

            $content = $sourceZip->getFromName($zipPath);
            if ($content === false) {
                // Try without prefix
                $content = $sourceZip->getFromName($filePath);
            }

            if ($content !== false) {
                $targetZip->addFromString($filePath, $content);
            }
        }

        $targetZip->close();
        $sourceZip->close();

        return $tempZipPath;
    }

    /**
     * Find the update folder prefix in ZIP
     */
    protected function findUpdatePrefix(\ZipArchive $zip): string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'update/')) {
                return 'update/';
            }
        }
        return '';
    }

    /**
     * Delete temporary ZIP file
     */
    public function cleanup(string $zipPath): void
    {
        if (file_exists($zipPath) && str_contains($zipPath, 'selective_')) {
            unlink($zipPath);
        }
    }
}
```

---

## API Controllers

### Controller: UpdateInfoController

```php
<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVersionManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateInfoController extends Controller
{
    /**
     * GET /api/v2/update-info/{license_key}/{product_uid}
     *
     * Check for available updates and return basic info
     */
    public function __invoke(Request $request, string $licenseKey, string $productUid): JsonResponse
    {
        // Validate license (use your existing license validation logic)
        $license = $this->validateLicense($licenseKey, $request->header('X-Site-Url'));
        if (!$license) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid license key',
            ], 401);
        }

        // Get product
        $product = Product::where('uid', $productUid)->first();
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        // Get latest manifest
        $manifest = ProductVersionManifest::where('product_id', $product->id)
            ->where('status', 'ready')
            ->orderByDesc('created_at')
            ->first();

        if (!$manifest) {
            return response()->json([
                'success' => false,
                'message' => 'No update available',
            ], 404);
        }

        // Get client's current version from request
        $clientVersion = $request->input('current_version', '0.0.0');

        return response()->json([
            'success' => true,
            'data' => [
                'update_available' => version_compare($manifest->version, $clientVersion, '>'),
                'current_version' => $clientVersion,
                'latest_version' => $manifest->version,
                'release_date' => $manifest->created_at->toDateString(),
                'total_size' => $manifest->total_size,
                'total_files' => $manifest->total_files,
                'total_chunks' => $manifest->total_chunks,
                'chunk_size' => $manifest->chunk_size,
                'zip_hash' => $manifest->zip_hash,
                'php_version_required' => $manifest->php_version_required,
                'mysql_version_required' => $manifest->mysql_version_required,
                'changelog' => $product->changelog ?? '',
            ],
        ]);
    }

    protected function validateLicense(string $licenseKey, ?string $siteUrl): ?object
    {
        // Implement your existing license validation logic here
        // Return license object if valid, null if invalid
        return null; // Placeholder
    }
}
```

### Controller: ManifestController

```php
<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVersionManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManifestController extends Controller
{
    /**
     * GET /api/v2/update-manifest/{license_key}/{product_uid}
     *
     * Return full manifest with all file hashes
     */
    public function __invoke(Request $request, string $licenseKey, string $productUid): JsonResponse
    {
        // Validate license
        $license = $this->validateLicense($licenseKey, $request->header('X-Site-Url'));
        if (!$license) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid license key',
            ], 401);
        }

        // Get product
        $product = Product::where('uid', $productUid)->first();
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        // Get specific version or latest
        $version = $request->input('version');

        $query = ProductVersionManifest::where('product_id', $product->id)
            ->where('status', 'ready');

        if ($version) {
            $query->where('version', $version);
        } else {
            $query->orderByDesc('created_at');
        }

        $manifest = $query->first();

        if (!$manifest) {
            return response()->json([
                'success' => false,
                'message' => 'Manifest not found',
            ], 404);
        }

        $manifestData = json_decode($manifest->manifest_json, true);

        return response()->json([
            'success' => true,
            'data' => [
                'version' => $manifest->version,
                'generated_at' => $manifest->created_at->toIso8601String(),
                'total_files' => $manifest->total_files,
                'total_size' => $manifest->total_size,
                'chunk_size' => $manifest->chunk_size,
                'total_chunks' => $manifest->total_chunks,
                'zip_hash' => $manifest->zip_hash,
                'files' => $manifestData['files'] ?? [],
                'skip_files' => $manifest->skip_files_array,
                'skip_directories' => $manifest->skip_directories_array,
            ],
        ]);
    }

    protected function validateLicense(string $licenseKey, ?string $siteUrl): ?object
    {
        // Implement your existing license validation logic here
        return null; // Placeholder
    }
}
```

### Controller: ChunkDownloadController

```php
<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVersionManifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChunkDownloadController extends Controller
{
    /**
     * GET /api/v2/download-chunk/{license_key}/{product_uid}/{chunk_index}
     *
     * Download a specific chunk
     */
    public function download(Request $request, string $licenseKey, string $productUid, int $chunkIndex): StreamedResponse
    {
        // Validate license
        $license = $this->validateLicense($licenseKey, $request->header('X-Site-Url'));
        if (!$license) {
            abort(401, 'Invalid license key');
        }

        // Get product
        $product = Product::where('uid', $productUid)->firstOrFail();

        // Get manifest (latest or specific version)
        $version = $request->input('version');

        $query = ProductVersionManifest::where('product_id', $product->id)
            ->where('status', 'ready');

        if ($version) {
            $query->where('version', $version);
        } else {
            $query->orderByDesc('created_at');
        }

        $manifest = $query->firstOrFail();

        // Get chunk
        $chunk = $manifest->chunks()->where('chunk_index', $chunkIndex)->firstOrFail();

        // Verify chunk file exists
        if (!Storage::exists($chunk->file_path)) {
            abort(404, 'Chunk file not found');
        }

        // Stream the chunk
        return response()->stream(
            function () use ($chunk) {
                $stream = Storage::readStream($chunk->file_path);
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => $chunk->chunk_size,
                'Content-Disposition' => 'attachment; filename="chunk_' . str_pad($chunkIndex, 3, '0', STR_PAD_LEFT) . '.bin"',
                'X-Chunk-Index' => $chunkIndex,
                'X-Chunk-Hash' => $chunk->chunk_hash,
                'X-Total-Chunks' => $manifest->total_chunks,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]
        );
    }

    /**
     * GET /api/v2/verify-chunk/{license_key}/{product_uid}/{chunk_index}
     *
     * Get chunk hash for verification
     */
    public function verify(Request $request, string $licenseKey, string $productUid, int $chunkIndex)
    {
        // Validate license
        $license = $this->validateLicense($licenseKey, $request->header('X-Site-Url'));
        if (!$license) {
            return response()->json(['success' => false, 'message' => 'Invalid license key'], 401);
        }

        // Get product
        $product = Product::where('uid', $productUid)->first();
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        // Get manifest
        $manifest = ProductVersionManifest::where('product_id', $product->id)
            ->where('status', 'ready')
            ->orderByDesc('created_at')
            ->first();

        if (!$manifest) {
            return response()->json(['success' => false, 'message' => 'Manifest not found'], 404);
        }

        // Get chunk
        $chunk = $manifest->chunks()->where('chunk_index', $chunkIndex)->first();

        if (!$chunk) {
            return response()->json(['success' => false, 'message' => 'Chunk not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'chunk_index' => $chunk->chunk_index,
                'chunk_hash' => $chunk->chunk_hash,
                'chunk_size' => $chunk->chunk_size,
            ],
        ]);
    }

    protected function validateLicense(string $licenseKey, ?string $siteUrl): ?object
    {
        // Implement your existing license validation logic here
        return null; // Placeholder
    }
}
```

### Controller: SelectiveDownloadController

```php
<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVersionManifest;
use App\Services\UpdateProcessing\SelectiveZipBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SelectiveDownloadController extends Controller
{
    protected SelectiveZipBuilder $zipBuilder;

    public function __construct(SelectiveZipBuilder $zipBuilder)
    {
        $this->zipBuilder = $zipBuilder;
    }

    /**
     * POST /api/v2/download-selective/{license_key}/{product_uid}
     *
     * Download ZIP containing only requested files
     *
     * Body: { "files": ["app/Models/User.php", "app/Http/Controllers/HomeController.php"] }
     */
    public function __invoke(Request $request, string $licenseKey, string $productUid): StreamedResponse
    {
        // Validate license
        $license = $this->validateLicense($licenseKey, $request->header('X-Site-Url'));
        if (!$license) {
            abort(401, 'Invalid license key');
        }

        // Validate request
        $validated = $request->validate([
            'files' => 'required|array|min:1|max:1000',
            'files.*' => 'required|string|max:500',
        ]);

        // Get product
        $product = Product::where('uid', $productUid)->firstOrFail();

        // Get manifest
        $version = $request->input('version');

        $query = ProductVersionManifest::where('product_id', $product->id)
            ->where('status', 'ready');

        if ($version) {
            $query->where('version', $version);
        } else {
            $query->orderByDesc('created_at');
        }

        $manifest = $query->firstOrFail();

        // Build selective ZIP
        $storagePath = $manifest->getStoragePath();
        $originalZipPath = Storage::path("{$storagePath}/original.zip");

        if (!file_exists($originalZipPath)) {
            abort(404, 'Original ZIP not found');
        }

        $selectiveZipPath = $this->zipBuilder->build($originalZipPath, $validated['files']);
        $selectiveZipSize = filesize($selectiveZipPath);
        $selectiveZipHash = hash_file('sha256', $selectiveZipPath);

        // Stream and cleanup
        return response()->stream(
            function () use ($selectiveZipPath) {
                $stream = fopen($selectiveZipPath, 'rb');
                fpassthru($stream);
                fclose($stream);

                // Cleanup after streaming
                $this->zipBuilder->cleanup($selectiveZipPath);
            },
            200,
            [
                'Content-Type' => 'application/zip',
                'Content-Length' => $selectiveZipSize,
                'Content-Disposition' => 'attachment; filename="selective-update.zip"',
                'X-Files-Count' => count($validated['files']),
                'X-Zip-Hash' => $selectiveZipHash,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]
        );
    }

    protected function validateLicense(string $licenseKey, ?string $siteUrl): ?object
    {
        // Implement your existing license validation logic here
        return null; // Placeholder
    }
}
```

---

## API Routes

```php
<?php

// routes/api.php

use App\Http\Controllers\Api\V2\UpdateInfoController;
use App\Http\Controllers\Api\V2\ManifestController;
use App\Http\Controllers\Api\V2\ChunkDownloadController;
use App\Http\Controllers\Api\V2\SelectiveDownloadController;

Route::prefix('v2')->group(function () {
    // Update info
    Route::get('/update-info/{license_key}/{product_uid}', UpdateInfoController::class);

    // Manifest
    Route::get('/update-manifest/{license_key}/{product_uid}', ManifestController::class);

    // Chunk download
    Route::get('/download-chunk/{license_key}/{product_uid}/{chunk_index}', [ChunkDownloadController::class, 'download']);
    Route::get('/verify-chunk/{license_key}/{product_uid}/{chunk_index}', [ChunkDownloadController::class, 'verify']);

    // Selective download
    Route::post('/download-selective/{license_key}/{product_uid}', SelectiveDownloadController::class);
});
```

---

## Admin Controller Changes

Modify your existing admin controller to use the new processing service:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\UpdateProcessing\UpdateProcessingService;
use Illuminate\Http\Request;

class ProductVersionController extends Controller
{
    protected UpdateProcessingService $processingService;

    public function __construct(UpdateProcessingService $processingService)
    {
        $this->processingService = $processingService;
    }

    /**
     * Upload new version
     */
    public function store(Request $request, Product $product)
    {
        $validated = $request->validate([
            'version' => 'required|string|max:50',
            'zip_file' => 'required|file|mimes:zip|max:512000', // 500MB max
            'changelog' => 'nullable|string',
            'skip_files' => 'nullable|string',
            'skip_directories' => 'nullable|string',
            'php_version' => 'nullable|string|max:20',
            'mysql_version' => 'nullable|string|max:20',
        ]);

        try {
            $manifest = $this->processingService->process(
                $product,
                $validated['version'],
                $request->file('zip_file'),
                [
                    'skip_files' => $validated['skip_files'] ?? null,
                    'skip_directories' => $validated['skip_directories'] ?? null,
                    'php_version' => $validated['php_version'] ?? '8.1',
                    'mysql_version' => $validated['mysql_version'] ?? '5.7',
                ]
            );

            // Update product changelog if provided
            if (!empty($validated['changelog'])) {
                $product->update(['changelog' => $validated['changelog']]);
            }

            return redirect()
                ->route('admin.products.versions.index', $product)
                ->with('success', "Version {$validated['version']} processed successfully. {$manifest->total_files} files, {$manifest->total_chunks} chunks.");

        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', "Failed to process version: {$e->getMessage()}");
        }
    }

    /**
     * Show version details
     */
    public function show(Product $product, string $version)
    {
        $manifest = $product->manifests()
            ->where('version', $version)
            ->with('chunks')
            ->firstOrFail();

        return view('admin.products.versions.show', [
            'product' => $product,
            'manifest' => $manifest,
        ]);
    }

    /**
     * Reprocess version
     */
    public function reprocess(Product $product, string $version)
    {
        $manifest = $product->manifests()
            ->where('version', $version)
            ->firstOrFail();

        try {
            $this->processingService->reprocess($manifest);

            return redirect()
                ->back()
                ->with('success', 'Version reprocessed successfully.');

        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', "Failed to reprocess: {$e->getMessage()}");
        }
    }
}
```

---

## Testing the Implementation

### Test 1: Upload Processing

```bash
# Upload a test ZIP and verify processing
curl -X POST "http://license-server/admin/products/1/versions" \
  -F "version=2.5.0" \
  -F "zip_file=@update.zip" \
  -F "skip_files=.env,.htaccess" \
  -F "skip_directories=lang,custom-fonts"
```

### Test 2: Fetch Update Info

```bash
curl -X GET "http://license-server/api/v2/update-info/{license_key}/{product_uid}?current_version=2.4.0" \
  -H "X-Site-Url: https://client-site.com"
```

### Test 3: Fetch Manifest

```bash
curl -X GET "http://license-server/api/v2/update-manifest/{license_key}/{product_uid}" \
  -H "X-Site-Url: https://client-site.com"
```

### Test 4: Download Chunk

```bash
curl -X GET "http://license-server/api/v2/download-chunk/{license_key}/{product_uid}/0" \
  -H "X-Site-Url: https://client-site.com" \
  -o chunk_000.bin
```

### Test 5: Selective Download

```bash
curl -X POST "http://license-server/api/v2/download-selective/{license_key}/{product_uid}" \
  -H "X-Site-Url: https://client-site.com" \
  -H "Content-Type: application/json" \
  -d '{"files": ["app/Models/User.php", "app/Http/Controllers/HomeController.php"]}' \
  -o selective.zip
```

---

## Checklist

- [ ] Run database migrations
- [ ] Create storage directories with proper permissions
- [ ] Register service providers
- [ ] Add API routes
- [ ] Update admin upload form
- [ ] Test with small ZIP file
- [ ] Test with large ZIP file (100MB+)
- [ ] Verify chunk integrity
- [ ] Test selective download
- [ ] Update existing products to use new system

---

## Notes

1. **Backward Compatibility**: Keep existing download endpoints working for older clients
2. **Storage**: Ensure adequate disk space for storing chunks (approximately 2x the ZIP size during processing)
3. **Memory**: The chunk splitter uses streaming to avoid memory issues
4. **Timeout**: Processing large ZIPs may take time; consider using queue jobs for production
5. **Security**: Always validate license before allowing downloads
