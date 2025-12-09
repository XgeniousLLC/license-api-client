<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\V2\ChunkDownloader;
use Xgenious\XgApiClient\Services\V2\ChunkMerger;
use Xgenious\XgApiClient\Services\V2\UpdateStatusManager;

class ChunkController extends Controller
{
    protected UpdateStatusManager $statusManager;
    protected ChunkDownloader $downloader;
    protected ChunkMerger $merger;

    public function __construct()
    {
        $this->statusManager = new UpdateStatusManager();
        $this->downloader = new ChunkDownloader($this->statusManager);
        $this->merger = new ChunkMerger($this->statusManager, $this->downloader);
    }

    /**
     * Download a specific chunk
     */
    public function download(int $chunkIndex): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'No active update. Please initiate update first.',
            ], 400);
        }

        $licenseKey = get_static_option('site_license_key');
        $productUid = config('xgapiclient.has_token');

        // Download the chunk
        $result = $this->downloader->download($chunkIndex, $licenseKey, $productUid);

        // Add progress info
        if ($result['success']) {
            $downloadedChunks = $this->downloader->getDownloadedChunks();
            $totalChunks = $status['download']['total_chunks'];

            $result['progress'] = [
                'downloaded' => count($downloadedChunks),
                'total' => $totalChunks,
                'percent' => $totalChunks > 0 ? round((count($downloadedChunks) / $totalChunks) * 100) : 0,
            ];
        }

        return response()->json($result);
    }

    /**
     * Get download progress
     */
    public function progress(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'No active update',
            ], 400);
        }

        $downloadedChunks = $this->downloader->getDownloadedChunks();
        $totalChunks = $status['download']['total_chunks'];
        $downloadedSize = $this->downloader->getDownloadedSize();

        return response()->json([
            'success' => true,
            'downloaded_chunks' => $downloadedChunks,
            'downloaded_count' => count($downloadedChunks),
            'total_chunks' => $totalChunks,
            'downloaded_size' => $downloadedSize,
            'total_size' => $status['download']['total_size'] ?? 0,
            'percent' => $totalChunks > 0 ? round((count($downloadedChunks) / $totalChunks) * 100) : 0,
            'is_complete' => count($downloadedChunks) >= $totalChunks,
        ]);
    }

    /**
     * Get list of missing chunks
     */
    public function missing(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'No active update',
            ], 400);
        }

        $downloadedChunks = $this->downloader->getDownloadedChunks();
        $totalChunks = $status['download']['total_chunks'];

        $missingChunks = [];
        for ($i = 0; $i < $totalChunks; $i++) {
            if (!in_array($i, $downloadedChunks)) {
                $missingChunks[] = $i;
            }
        }

        return response()->json([
            'success' => true,
            'missing_chunks' => $missingChunks,
            'missing_count' => count($missingChunks),
            'total_chunks' => $totalChunks,
        ]);
    }

    /**
     * Verify a downloaded chunk
     */
    public function verify(int $chunkIndex): JsonResponse
    {
        $licenseKey = get_static_option('site_license_key');
        $productUid = config('xgapiclient.has_token');

        $result = $this->downloader->verifyWithServer($chunkIndex, $licenseKey, $productUid);

        return response()->json($result);
    }

    /**
     * Merge all chunks into ZIP
     */
    public function merge(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'No active update',
            ], 400);
        }

        // Check if all chunks are downloaded
        $downloadedChunks = $this->downloader->getDownloadedChunks();
        $totalChunks = $status['download']['total_chunks'];

        if (count($downloadedChunks) < $totalChunks) {
            return response()->json([
                'success' => false,
                'message' => 'Not all chunks downloaded. Missing: ' . ($totalChunks - count($downloadedChunks)),
                'downloaded' => count($downloadedChunks),
                'total' => $totalChunks,
            ], 400);
        }

        // Merge chunks
        $result = $this->merger->merge();

        if ($result['success']) {
            // Update status
            $this->statusManager->updatePhase('download', ['status' => 'completed']);
            $this->statusManager->update(['phase' => 'extraction']);

            // Cleanup chunks
            $this->merger->cleanupChunks();
        }

        return response()->json($result);
    }

    /**
     * Get ZIP info after merge
     */
    public function zipInfo(): JsonResponse
    {
        $info = $this->merger->getZipInfo();

        if (!$info) {
            return response()->json([
                'success' => false,
                'message' => 'ZIP file not found or not yet merged',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'zip' => $info,
        ]);
    }

    /**
     * Re-download a specific chunk (for recovery)
     */
    public function redownload(int $chunkIndex): JsonResponse
    {
        // Delete existing chunk first
        $this->downloader->deleteChunk($chunkIndex);

        // Download again
        return $this->download($chunkIndex);
    }
}
