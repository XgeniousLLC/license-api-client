<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\V2\BatchExtractor;
use Xgenious\XgApiClient\Services\V2\ChunkMerger;
use Xgenious\XgApiClient\Services\V2\ChunkDownloader;
use Xgenious\XgApiClient\Services\V2\UpdateStatusManager;

class ExtractionController extends Controller
{
    protected UpdateStatusManager $statusManager;
    protected BatchExtractor $extractor;
    protected ChunkMerger $merger;

    public function __construct()
    {
        $this->statusManager = new UpdateStatusManager();
        $this->extractor = new BatchExtractor($this->statusManager);
        $this->merger = new ChunkMerger(
            $this->statusManager,
            new ChunkDownloader($this->statusManager)
        );
    }

    /**
     * Extract a batch of files from ZIP
     */
    public function extractBatch(Request $request): JsonResponse
    {
        $batchNumber = (int) $request->input('batch', 0);
        $batchSize = $request->input('batch_size');

        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'No active update',
            ], 400);
        }

        // Verify ZIP exists
        if (!$this->merger->zipExists()) {
            return response()->json([
                'success' => false,
                'message' => 'ZIP file not found. Please complete download and merge first.',
            ], 400);
        }

        // Check if we're in the right phase
        if (!in_array($status['phase'], ['extraction', 'merging', 'download'])) {
            // Allow re-extraction if needed
            if ($status['phase'] !== 'replacement') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid phase for extraction: ' . $status['phase'],
                ], 400);
            }
        }

        // Update phase if not already in extraction
        if ($status['phase'] !== 'extraction') {
            $this->statusManager->update(['phase' => 'extraction']);
        }

        // Extract batch
        $result = $this->extractor->extractBatch($batchNumber, $batchSize);

        // If extraction complete, update phase
        if ($result['success'] && !$result['has_more']) {
            $this->statusManager->updatePhase('extraction', ['status' => 'completed']);
            $this->statusManager->update(['phase' => 'replacement']);
            $this->statusManager->addLog('Extraction completed. Ready for file replacement.');
        }

        return response()->json($result);
    }

    /**
     * Get extraction progress
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

        $extraction = $status['extraction'] ?? [];

        return response()->json([
            'success' => true,
            'status' => $extraction['status'] ?? 'pending',
            'extracted_files' => $extraction['extracted_files'] ?? 0,
            'total_files' => $extraction['total_files'] ?? 0,
            'current_batch' => $extraction['current_batch'] ?? 0,
            'total_batches' => $extraction['total_batches'] ?? 0,
            'percent' => $extraction['percent'] ?? 0,
            'is_complete' => $this->extractor->isExtractionComplete(),
        ]);
    }

    /**
     * Get total files in ZIP
     */
    public function fileCount(): JsonResponse
    {
        if (!$this->merger->zipExists()) {
            return response()->json([
                'success' => false,
                'message' => 'ZIP file not found',
            ], 404);
        }

        $totalFiles = $this->extractor->getTotalFiles();

        return response()->json([
            'success' => true,
            'total_files' => $totalFiles,
        ]);
    }

    /**
     * Reset extraction (start over)
     */
    public function reset(): JsonResponse
    {
        $this->extractor->reset();

        $this->statusManager->updatePhase('extraction', [
            'status' => 'pending',
            'extracted_files' => 0,
            'current_batch' => 0,
            'percent' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Extraction reset',
        ]);
    }

    /**
     * Get list of files to be extracted
     */
    public function fileList(): JsonResponse
    {
        if (!$this->merger->zipExists()) {
            return response()->json([
                'success' => false,
                'message' => 'ZIP file not found',
            ], 404);
        }

        $files = $this->extractor->getFileList();

        return response()->json([
            'success' => true,
            'files' => $files,
            'count' => count($files),
        ]);
    }
}
