<?php

namespace Xgenious\XgApiClient\Http\Controllers\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Xgenious\XgApiClient\Http\Controllers\Controller;
use Xgenious\XgApiClient\Services\V2\UpdateApiClient;
use Xgenious\XgApiClient\Services\V2\UpdateStatusManager;

class UpdateController extends Controller
{
    protected UpdateStatusManager $statusManager;
    protected UpdateApiClient $apiClient;

    public function __construct()
    {
        $this->statusManager = new UpdateStatusManager();
        $this->apiClient = new UpdateApiClient();
    }

    /**
     * Show the V2 update page
     */
    public function index()
    {
        $licenseKey = get_static_option('site_license_key');
        $currentVersion = get_static_option('site_script_version');
        $productUid = config('xgapiclient.has_token');

        // Check for existing update status (for resume capability)
        $existingStatus = $this->statusManager->getStatus();
        $canResume = $this->statusManager->canResume();

        return view('XgApiClient::v2.update', compact(
            'licenseKey',
            'currentVersion',
            'productUid',
            'existingStatus',
            'canResume'
        ));
    }

    /**
     * Check for available updates
     */
    public function checkUpdate(): JsonResponse
    {
        $licenseKey = get_static_option('site_license_key');
        $currentVersion = get_static_option('site_script_version');
        $productUid = config('xgapiclient.has_token');

        if (!$licenseKey && !$productUid) {
            return response()->json([
                'success' => false,
                'message' => 'License key or product UID not configured' . $productUid . ' ' . $licenseKey,
            ], 400);
        }

        $this->apiClient->setCredentials($licenseKey, $productUid);
        $result = $this->apiClient->checkForUpdate($currentVersion);

        return response()->json($result);
    }

    /**
     * Initialize update process
     */
    public function initiate(Request $request): JsonResponse
    {
        $targetVersion = $request->input('version');
        $licenseKey = get_static_option('site_license_key');
        $productUid = config('xgapiclient.has_token') ;

        if (!$targetVersion) {
            return response()->json([
                'success' => false,
                'message' => 'Target version is required',
            ], 400);
        }

        $this->apiClient->setCredentials($licenseKey, $productUid);

        // Get chunks info from server
        $chunksInfo = $this->apiClient->getChunks($targetVersion);

        if (!($chunksInfo['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $chunksInfo['message'] ?? 'Failed to get chunk information',
            ], 400);
        }

        // Initialize status
        $result = $this->statusManager->initiate($targetVersion, [
            'total_chunks' => $chunksInfo['data']['total_chunks'] ?? 0,
            'total_size' => $chunksInfo['data']['total_size'] ?? 0,
            'chunk_size' => $chunksInfo['data']['chunk_size'] ?? 0,
            'zip_hash' => $chunksInfo['data']['zip_hash'] ?? null,
        ]);

        return response()->json($result);
    }

    /**
     * Get current update status
     */
    public function status(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        if (!$status) {
            return response()->json([
                'success' => true,
                'active' => false,
                'message' => 'No active update',
            ]);
        }

        return response()->json([
            'success' => true,
            'active' => true,
            'status' => $status,
            'can_resume' => $this->statusManager->canResume(),
            'resume_point' => $this->statusManager->getResumePoint(),
        ]);
    }

    /**
     * Cancel/reset update process
     */
    public function cancel(): JsonResponse
    {
        $result = $this->statusManager->reset();

        return response()->json([
            'success' => true,
            'message' => 'Update cancelled and cleaned up',
            'cleaned' => $result,
        ]);
    }

    /**
     * Get resume information for interrupted update
     */
    public function resumeInfo(): JsonResponse
    {
        if (!$this->statusManager->canResume()) {
            return response()->json([
                'success' => false,
                'can_resume' => false,
                'message' => 'No update to resume',
            ]);
        }

        $resumePoint = $this->statusManager->getResumePoint();

        return response()->json([
            'success' => true,
            'can_resume' => true,
            'resume_point' => $resumePoint,
        ]);
    }

    /**
     * Get update logs
     */
    public function logs(): JsonResponse
    {
        $status = $this->statusManager->getStatus();

        return response()->json([
            'success' => true,
            'logs' => $status['logs'] ?? [],
        ]);
    }
}
