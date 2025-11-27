<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\V2\BatchReplacer;
use Xgenious\XgApiClient\Services\V2\UpdateStatusManager;

class ReplacementController extends Controller
{
    protected UpdateStatusManager $statusManager;
    protected BatchReplacer $replacer;

    public function __construct()
    {
        $this->statusManager = new UpdateStatusManager();
        $this->replacer = new BatchReplacer($this->statusManager);
    }

    /**
     * Replace a batch of files
     */
    public function replaceBatch(Request $request): JsonResponse
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

        // Check if extraction is complete
        if ($status['extraction']['status'] !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Extraction not complete. Please complete extraction first.',
            ], 400);
        }

        // Replace batch
        $result = $this->replacer->replaceBatch($batchNumber, $batchSize);

        // If replacement complete, update phase and proceed to migration
        if ($result['success'] && !$result['has_more']) {
            $this->statusManager->updatePhase('replacement', ['status' => 'completed']);
            $this->statusManager->update(['phase' => 'migration']);
            $this->statusManager->addLog('File replacement completed. Ready for database migration.');

            // Disable maintenance mode
            $this->replacer->disableMaintenanceMode();
        }

        return response()->json($result);
    }

    /**
     * Get replacement progress
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

        $replacement = $status['replacement'] ?? [];

        return response()->json([
            'success' => true,
            'status' => $replacement['status'] ?? 'pending',
            'replaced_files' => $replacement['replaced_files'] ?? 0,
            'skipped_files' => $replacement['skipped_files'] ?? 0,
            'total_files' => $replacement['total_files'] ?? 0,
            'current_batch' => $replacement['current_batch'] ?? 0,
            'total_batches' => $replacement['total_batches'] ?? 0,
            'percent' => $replacement['percent'] ?? 0,
            'current_file' => $replacement['current_file'] ?? null,
            'is_complete' => $this->replacer->isReplacementComplete(),
        ]);
    }

    /**
     * Set skip files
     */
    public function setSkipFiles(Request $request): JsonResponse
    {
        $files = $request->input('files', []);

        if (!is_array($files)) {
            $files = array_filter(array_map('trim', explode(',', $files)));
        }

        $this->replacer->setSkipFiles($files);

        // Also update in status
        $status = $this->statusManager->getStatus();
        if ($status) {
            $existingSkip = $status['skip_files'] ?? '';
            $allSkip = array_unique(array_merge(
                array_filter(array_map('trim', explode(',', $existingSkip))),
                $files
            ));
            $this->statusManager->update(['skip_files' => implode(',', $allSkip)]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Skip files updated',
            'skip_files' => $files,
        ]);
    }

    /**
     * Set skip directories
     */
    public function setSkipDirectories(Request $request): JsonResponse
    {
        $directories = $request->input('directories', []);

        if (!is_array($directories)) {
            $directories = array_filter(array_map('trim', explode(',', $directories)));
        }

        $this->replacer->setSkipDirectories($directories);

        // Also update in status
        $status = $this->statusManager->getStatus();
        if ($status) {
            $existingSkip = $status['skip_directories'] ?? '';
            $allSkip = array_unique(array_merge(
                array_filter(array_map('trim', explode(',', $existingSkip))),
                $directories
            ));
            $this->statusManager->update(['skip_directories' => implode(',', $allSkip)]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Skip directories updated',
            'skip_directories' => $directories,
        ]);
    }

    /**
     * Get list of files to be replaced
     */
    public function fileList(): JsonResponse
    {
        $files = $this->replacer->getFileList();

        return response()->json([
            'success' => true,
            'files' => $files,
            'count' => count($files),
        ]);
    }

    /**
     * Reset replacement (start over)
     */
    public function reset(): JsonResponse
    {
        $this->replacer->reset();

        $this->statusManager->updatePhase('replacement', [
            'status' => 'pending',
            'replaced_files' => 0,
            'skipped_files' => 0,
            'current_batch' => 0,
            'percent' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Replacement reset',
        ]);
    }

    /**
     * Toggle maintenance mode
     */
    public function maintenanceMode(Request $request): JsonResponse
    {
        $enable = $request->boolean('enable', true);

        if ($enable) {
            // Need to use Artisan directly for enabling
            try {
                \Illuminate\Support\Facades\Artisan::call('down');
                $this->statusManager->update(['maintenance_mode' => true]);
                $this->statusManager->addLog('Maintenance mode enabled manually');
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to enable maintenance mode: ' . $e->getMessage(),
                ], 500);
            }
        } else {
            $this->replacer->disableMaintenanceMode();
        }

        return response()->json([
            'success' => true,
            'maintenance_mode' => $enable,
        ]);
    }
}
