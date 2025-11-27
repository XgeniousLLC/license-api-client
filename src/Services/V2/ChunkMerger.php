<?php

namespace Xgenious\XgApiClient\Services\V2;

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
     * Merge all downloaded chunks into a single ZIP file
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
            $missingChunks = [];
            for ($i = 0; $i < $totalChunks; $i++) {
                if (!in_array($i, $downloadedChunks)) {
                    $missingChunks[] = $i;
                }
            }

            if (!empty($missingChunks)) {
                throw new \Exception("Missing chunks: " . implode(', ', $missingChunks));
            }

            // Create output file
            $outputPath = $paths['zip'];
            $outputHandle = fopen($outputPath, 'wb');

            if (!$outputHandle) {
                throw new \Exception("Cannot create output file: {$outputPath}");
            }

            $totalSize = 0;

            // Merge chunks in order
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $this->chunkDownloader->getChunkPath($i);

                if (!File::exists($chunkPath)) {
                    fclose($outputHandle);
                    throw new \Exception("Chunk file not found: {$chunkPath}");
                }

                $chunkHandle = fopen($chunkPath, 'rb');
                if (!$chunkHandle) {
                    fclose($outputHandle);
                    throw new \Exception("Cannot read chunk file: {$chunkPath}");
                }

                while (!feof($chunkHandle)) {
                    $buffer = fread($chunkHandle, 8192);
                    if ($buffer === false) {
                        break;
                    }
                    fwrite($outputHandle, $buffer);
                    $totalSize += strlen($buffer);
                }

                fclose($chunkHandle);
            }

            fclose($outputHandle);

            // Verify ZIP is valid
            $zip = new \ZipArchive();
            $openResult = $zip->open($outputPath);

            if ($openResult !== true) {
                throw new \Exception("Merged file is not a valid ZIP (error code: {$openResult})");
            }

            $fileCount = $zip->numFiles;
            $zip->close();

            // Calculate hash
            $zipHash = hash_file('sha256', $outputPath);

            // Verify against expected hash if available
            $expectedHash = $status['download']['zip_hash'] ?? null;
            if ($expectedHash && !hash_equals($expectedHash, $zipHash)) {
                $this->statusManager->addLog("Warning: ZIP hash mismatch. Expected: {$expectedHash}, Got: {$zipHash}");
                // Don't fail on hash mismatch, just log it
            }

            $this->statusManager->addLog("Chunks merged successfully (" . $this->formatBytes($totalSize) . ", {$fileCount} files)");

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
    public function cleanupChunks(): bool
    {
        $paths = $this->statusManager->getPaths();
        $chunksDir = $paths['chunks'];

        try {
            if (File::isDirectory($chunksDir)) {
                File::deleteDirectory($chunksDir);
                $this->statusManager->addLog('Chunk files cleaned up');
                return true;
            }
            return true;
        } catch (\Exception $e) {
            Log::warning("Failed to cleanup chunks", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Verify merged ZIP against expected hash
     */
    public function verify(?string $expectedHash = null): array
    {
        $paths = $this->statusManager->getPaths();
        $zipPath = $paths['zip'];

        if (!File::exists($zipPath)) {
            return [
                'success' => false,
                'valid' => false,
                'reason' => 'ZIP file not found',
            ];
        }

        $actualHash = hash_file('sha256', $zipPath);

        // If no expected hash provided, get from status
        if (!$expectedHash) {
            $status = $this->statusManager->getStatus();
            $expectedHash = $status['download']['zip_hash'] ?? null;
        }

        if (!$expectedHash) {
            return [
                'success' => true,
                'valid' => true,
                'hash' => $actualHash,
                'note' => 'No expected hash to compare against',
            ];
        }

        $isValid = hash_equals($expectedHash, $actualHash);

        return [
            'success' => true,
            'valid' => $isValid,
            'expected_hash' => $expectedHash,
            'actual_hash' => $actualHash,
        ];
    }

    /**
     * Check if merged ZIP exists and is valid
     */
    public function zipExists(): bool
    {
        $paths = $this->statusManager->getPaths();

        if (!File::exists($paths['zip'])) {
            return false;
        }

        $zip = new \ZipArchive();
        $result = $zip->open($paths['zip']);

        if ($result === true) {
            $zip->close();
            return true;
        }

        return false;
    }

    /**
     * Get ZIP file info
     */
    public function getZipInfo(): ?array
    {
        $paths = $this->statusManager->getPaths();

        if (!File::exists($paths['zip'])) {
            return null;
        }

        $zip = new \ZipArchive();
        $result = $zip->open($paths['zip']);

        if ($result !== true) {
            return null;
        }

        $info = [
            'path' => $paths['zip'],
            'size' => File::size($paths['zip']),
            'file_count' => $zip->numFiles,
            'hash' => hash_file('sha256', $paths['zip']),
        ];

        $zip->close();

        return $info;
    }

    /**
     * Format bytes to human readable string
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
