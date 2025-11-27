<?php

namespace Xgenious\XgApiClient\Services\V2;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class BatchExtractor
{
    protected UpdateStatusManager $statusManager;
    protected ?\ZipArchive $zip = null;
    protected bool $zipOpened = false;
    protected array $fileList = [];
    protected bool $fileListBuilt = false;

    public function __construct(UpdateStatusManager $statusManager)
    {
        $this->statusManager = $statusManager;
    }

    /**
     * Extract a batch of files from the ZIP
     */
    public function extractBatch(int $batchNumber, ?int $batchSize = null): array
    {
        $batchSize = $batchSize ?? Config::get('xgapiclient.update.extraction_batch_size', 100);
        $paths = $this->statusManager->getPaths();

        try {
            // Open ZIP if not already open
            if (!$this->zipOpened) {
                $this->zip = new \ZipArchive();
                $result = $this->zip->open($paths['zip']);

                if ($result !== true) {
                    throw new \Exception("Cannot open ZIP file (error code: {$result})");
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

            // Check if we've already processed all files
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
            $errors = [];

            for ($i = $startIndex; $i < $endIndex; $i++) {
                $fileName = $this->fileList[$i];

                try {
                    // Get file content from ZIP
                    $content = $this->zip->getFromName($fileName);

                    if ($content === false) {
                        $errors[] = $fileName;
                        Log::warning("Could not extract file from ZIP: {$fileName}");
                        continue;
                    }

                    // Determine output path (normalize path, remove 'update/' prefix if present)
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

                } catch (\Exception $e) {
                    $errors[] = $fileName;
                    Log::warning("Failed to extract file: {$fileName}", ['error' => $e->getMessage()]);
                }
            }

            $totalExtracted = $endIndex;
            $percent = $totalFiles > 0 ? round(($totalExtracted / $totalFiles) * 100) : 100;

            // Update status
            $this->statusManager->updatePhase('extraction', [
                'status' => 'in_progress',
                'extracted_files' => $totalExtracted,
                'current_batch' => $batchNumber,
                'percent' => $percent,
            ]);

            // Log progress every 5 batches
            if ($batchNumber % 5 === 0 || !($endIndex < $totalFiles)) {
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
                'errors' => $errors,
            ];

        } catch (\Exception $e) {
            Log::error("Extraction failed", ['batch' => $batchNumber, 'error' => $e->getMessage()]);
            $this->statusManager->recordError('extraction_failed', $e->getMessage(), [
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
     * Build list of files in the ZIP (excluding directories and system files)
     */
    protected function buildFileList(): void
    {
        if ($this->fileListBuilt || !$this->zip) {
            return;
        }

        $this->fileList = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            $name = $stat['name'];

            // Skip directories (end with /)
            if (str_ends_with($name, '/')) {
                continue;
            }

            // Skip system/hidden files
            if ($this->shouldSkipFile($name)) {
                continue;
            }

            $this->fileList[] = $name;
        }

        $this->fileListBuilt = true;

        // Calculate total batches and update status
        $batchSize = Config::get('xgapiclient.update.extraction_batch_size', 100);
        $totalBatches = (int) ceil(count($this->fileList) / $batchSize);

        $this->statusManager->updatePhase('extraction', [
            'total_files' => count($this->fileList),
            'total_batches' => $totalBatches,
        ]);
    }

    /**
     * Normalize file path - remove common prefixes like 'update/'
     */
    protected function normalizeFilePath(string $path): string
    {
        $prefixes = ['update/', 'Update/', 'UPDATE/'];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return $path;
    }

    /**
     * Check if file should be skipped during extraction
     */
    protected function shouldSkipFile(string $path): bool
    {
        $filename = basename($path);

        // Skip hidden files (except .htaccess)
        if (str_starts_with($filename, '.') && $filename !== '.htaccess') {
            return true;
        }

        // Skip macOS system files
        if ($filename === '.DS_Store' || str_contains($path, '__MACOSX')) {
            return true;
        }

        // Skip Thumbs.db (Windows)
        if ($filename === 'Thumbs.db') {
            return true;
        }

        return false;
    }

    /**
     * Get total number of files in ZIP
     */
    public function getTotalFiles(): int
    {
        if (!$this->fileListBuilt) {
            $paths = $this->statusManager->getPaths();

            if (!File::exists($paths['zip'])) {
                return 0;
            }

            $zip = new \ZipArchive();
            if ($zip->open($paths['zip']) === true) {
                $this->zip = $zip;
                $this->zipOpened = true;
                $this->buildFileList();
            }
        }

        return count($this->fileList);
    }

    /**
     * Get list of all files in ZIP
     */
    public function getFileList(): array
    {
        if (!$this->fileListBuilt) {
            $this->getTotalFiles();
        }

        return $this->fileList;
    }

    /**
     * Check if extraction is complete
     */
    public function isExtractionComplete(): bool
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return false;
        }

        return $status['extraction']['status'] === 'completed' ||
               ($status['extraction']['extracted_files'] >= $status['extraction']['total_files'] &&
                $status['extraction']['total_files'] > 0);
    }

    /**
     * Close the ZIP file
     */
    public function close(): void
    {
        if ($this->zipOpened && $this->zip) {
            $this->zip->close();
            $this->zipOpened = false;
            $this->zip = null;
        }
    }

    /**
     * Reset state for fresh extraction
     */
    public function reset(): void
    {
        $this->close();
        $this->fileList = [];
        $this->fileListBuilt = false;

        $paths = $this->statusManager->getPaths();
        if (File::isDirectory($paths['extracted'])) {
            File::deleteDirectory($paths['extracted']);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
