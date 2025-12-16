<?php

namespace Xgenious\XgApiClient\Services\V2;

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
     * Download a single chunk from the license server
     */
    public function download(int $chunkIndex, string $licenseKey, string $productUid): array
    {
        $this->ensureChunksDirExists();

        $baseUrl = xgNormalizeBaseApiUrl(Config::get('xgapiclient.base_api_url'));
        $siteUrl = url('/');
        $hash = hash_hmac('sha224', $licenseKey . $siteUrl, 'xgenious');

        // Get target version from status
        $status = $this->statusManager->getStatus();
        $targetVersion = $status['version']['target'] ?? null;

        if (!$targetVersion) {
            throw new \Exception("Cannot download chunk: target version not found in update status. Please cancel and restart the update.");
        }

        $url = "{$baseUrl}/v2/download-chunk/{$licenseKey}/{$productUid}/{$chunkIndex}";

        $this->statusManager->addLog("Downloading chunk {$chunkIndex}...");
        $this->statusManager->updatePhase('download', ['current_chunk' => $chunkIndex]);

        try {
            $chunkPath = $this->getChunkPath($chunkIndex);

            // Build query parameters
            $queryParams = [
                'site' => $siteUrl,
                'has' => $hash,
            ];
            
            // Add version if available
            if ($targetVersion) {
                $queryParams['version'] = $targetVersion;
            }

            $response = Http::timeout(300) // 5 minute timeout per chunk
                ->withHeaders([
                    'X-Site-Url' => $siteUrl,
                    'Accept' => 'application/octet-stream',
                ])
                ->withOptions(['sink' => $chunkPath])
                ->get($url, $queryParams);

            if (!$response->successful()) {
                $errorBody = $response->body();
                $errorJson = $response->json();
                
                Log::error("Chunk download HTTP error", [
                    'chunk' => $chunkIndex,
                    'status' => $response->status(),
                    'url' => $url,
                    'response_body' => $errorBody,
                    'response_json' => $errorJson,
                    'headers' => $response->headers(),
                ]);
                
                $errorMessage = $errorJson['message'] ?? $errorJson['error'] ?? $errorBody;
                throw new \Exception("Failed to download chunk {$chunkIndex}: HTTP " . $response->status() . " - " . $errorMessage);
            }

            // Get chunk info from headers
            $expectedHash = $response->header('X-Chunk-Hash');
            $totalChunks = (int) $response->header('X-Total-Chunks');
            $chunkSize = (int) $response->header('X-Chunk-Size');

            // Verify hash if provided
            if ($expectedHash) {
                $actualHash = hash_file('sha256', $chunkPath);
                if (!hash_equals($expectedHash, $actualHash)) {
                    File::delete($chunkPath);
                    throw new \Exception("Chunk {$chunkIndex} hash verification failed");
                }
            }

            $actualSize = File::size($chunkPath);

            // Update status
            $this->statusManager->markChunkCompleted($chunkIndex, $expectedHash ?? hash_file('sha256', $chunkPath), $actualSize);
            $this->statusManager->addLog("Chunk {$chunkIndex} downloaded (" . $this->formatBytes($actualSize) . ")");

            return [
                'success' => true,
                'chunk_index' => $chunkIndex,
                'chunk_size' => $actualSize,
                'chunk_hash' => $expectedHash ?? hash_file('sha256', $chunkPath),
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
                'recoverable' => true,
            ];
        }
    }

    /**
     * Verify a downloaded chunk against expected hash
     */
    public function verify(int $chunkIndex, string $expectedHash): bool
    {
        $chunkPath = $this->getChunkPath($chunkIndex);

        if (!File::exists($chunkPath)) {
            return false;
        }

        $actualHash = hash_file('sha256', $chunkPath);
        return hash_equals($expectedHash, $actualHash);
    }

    /**
     * Verify chunk with server
     */
    public function verifyWithServer(int $chunkIndex, string $licenseKey, string $productUid): array
    {
        $baseUrl = xgNormalizeBaseApiUrl(Config::get('xgapiclient.base_api_url'));
        $siteUrl = url('/');
        $hash = hash_hmac('sha224', $licenseKey . $siteUrl, 'xgenious');

        $url = "{$baseUrl}/v2/verify-chunk/{$licenseKey}/{$productUid}/{$chunkIndex}";

        try {
            $response = Http::timeout(30)
                ->withHeaders(['X-Site-Url' => $siteUrl])
                ->get($url, [
                    'site' => $siteUrl,
                    'has' => $hash,
                ]);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Server verification failed'];
            }

            $serverData = $response->json('data');
            $chunkPath = $this->getChunkPath($chunkIndex);

            if (!File::exists($chunkPath)) {
                return [
                    'success' => true,
                    'valid' => false,
                    'reason' => 'Chunk file not found locally',
                ];
            }

            $localHash = hash_file('sha256', $chunkPath);
            $isValid = hash_equals($serverData['chunk_hash'], $localHash);

            return [
                'success' => true,
                'valid' => $isValid,
                'local_hash' => $localHash,
                'server_hash' => $serverData['chunk_hash'],
                'chunk_index' => $chunkIndex,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get list of successfully downloaded chunks
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
     * Check if chunk exists locally
     */
    public function chunkExists(int $chunkIndex): bool
    {
        return File::exists($this->getChunkPath($chunkIndex));
    }

    /**
     * Delete a specific chunk
     */
    public function deleteChunk(int $chunkIndex): bool
    {
        $chunkPath = $this->getChunkPath($chunkIndex);

        if (File::exists($chunkPath)) {
            return File::delete($chunkPath);
        }

        return true;
    }

    /**
     * Get total size of downloaded chunks
     */
    public function getDownloadedSize(): int
    {
        if (!File::isDirectory($this->chunksDir)) {
            return 0;
        }

        $totalSize = 0;
        $files = File::files($this->chunksDir);

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.bin')) {
                $totalSize += $file->getSize();
            }
        }

        return $totalSize;
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
