<?php

namespace Xgenious\XgApiClient\Services\V2;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateApiClient
{
    protected string $baseUrl;
    protected string $licenseKey;
    protected string $productUid;
    protected string $siteUrl;

    public function __construct()
    {
        $this->baseUrl = xgNormalizeBaseApiUrl();
        $this->siteUrl = url('/');
    }

    /**
     * Set license credentials
     */
    public function setCredentials(string $licenseKey, string $productUid): self
    {
        $this->licenseKey = $licenseKey;
        $this->productUid = $productUid;
        return $this;
    }

    /**
     * Generate security hash
     */
    protected function generateHash(): string
    {
        return hash_hmac('sha224', $this->licenseKey . $this->siteUrl, 'xgenious');
    }

    /**
     * Default query parameters for all requests
     */
    protected function defaultParams(): array
    {
        return [
            'site' => $this->siteUrl,
            'has' => $this->generateHash(),
        ];
    }

    /**
     * Check for available updates
     */
    public function checkForUpdate(string $currentVersion): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['X-Site-Url' => $this->siteUrl])
                ->get(
                    "{$this->baseUrl}/v2/update-info/{$this->licenseKey}/{$this->productUid}",
                    array_merge($this->defaultParams(), ['current_version' => $currentVersion])
                );

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Server error: HTTP ' . $response->status(),
                ];
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Update check failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get update manifest with all file hashes
     */
    public function getManifest(?string $version = null): array
    {
        try {
            $params = $this->defaultParams();
            if ($version) {
                $params['version'] = $version;
            }

            $response = Http::timeout(60)
                ->withHeaders(['X-Site-Url' => $this->siteUrl])
                ->get(
                    "{$this->baseUrl}/v2/update-manifest/{$this->licenseKey}/{$this->productUid}",
                    $params
                );

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Failed to get manifest: HTTP ' . $response->status(),
                ];
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Get manifest failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Compare local files with server manifest
     */
    public function compareFiles(array $localFiles, ?string $version = null): array
    {
        try {
            $url = "{$this->baseUrl}/v2/update-manifest/{$this->licenseKey}/{$this->productUid}/compare";

            $response = Http::timeout(120)
                ->withHeaders(['X-Site-Url' => $this->siteUrl])
                ->post($url . '?' . http_build_query($this->defaultParams()), [
                    'files' => $localFiles,
                    'version' => $version,
                ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Comparison failed: HTTP ' . $response->status(),
                ];
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('File comparison failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get chunk list information
     */
    public function getChunks(?string $version = null): array
    {
        try {
            $params = $this->defaultParams();
            if ($version) {
                $params['version'] = $version;
            }

            $response = Http::timeout(30)
                ->withHeaders(['X-Site-Url' => $this->siteUrl])
                ->get(
                    "{$this->baseUrl}/v2/update-manifest/{$this->licenseKey}/{$this->productUid}/chunks",
                    $params
                );

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Failed to get chunks: HTTP ' . $response->status(),
                ];
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Get chunks failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify a chunk hash with server
     */
    public function verifyChunk(int $chunkIndex, ?string $version = null): array
    {
        try {
            $params = $this->defaultParams();
            if ($version) {
                $params['version'] = $version;
            }

            $response = Http::timeout(30)
                ->withHeaders(['X-Site-Url' => $this->siteUrl])
                ->get(
                    "{$this->baseUrl}/v2/verify-chunk/{$this->licenseKey}/{$this->productUid}/{$chunkIndex}",
                    $params
                );

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Verification failed: HTTP ' . $response->status(),
                ];
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Chunk verification failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get download progress / check available chunks on server
     */
    public function getDownloadProgress(?string $version = null): array
    {
        try {
            $params = $this->defaultParams();
            if ($version) {
                $params['version'] = $version;
            }

            $response = Http::timeout(30)
                ->withHeaders(['X-Site-Url' => $this->siteUrl])
                ->get(
                    "{$this->baseUrl}/v2/download-progress/{$this->licenseKey}/{$this->productUid}",
                    $params
                );

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Failed to get progress: HTTP ' . $response->status(),
                ];
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Get download progress failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate files for selective download
     */
    public function validateSelectiveFiles(array $files, ?string $version = null): array
    {
        try {
            $url = "{$this->baseUrl}/v2/download-selective/{$this->licenseKey}/{$this->productUid}/validate";

            $response = Http::timeout(60)
                ->withHeaders(['X-Site-Url' => $this->siteUrl])
                ->post($url . '?' . http_build_query($this->defaultParams()), [
                    'files' => $files,
                    'version' => $version,
                ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Validation failed: HTTP ' . $response->status(),
                ];
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Selective validation failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get chunk download URL (for direct download)
     */
    public function getChunkDownloadUrl(int $chunkIndex, ?string $version = null): string
    {
        $params = $this->defaultParams();
        if ($version) {
            $params['version'] = $version;
        }

        return "{$this->baseUrl}/v2/download-chunk/{$this->licenseKey}/{$this->productUid}/{$chunkIndex}?" .
            http_build_query($params);
    }

    /**
     * Get selective download URL
     */
    public function getSelectiveDownloadUrl(?string $version = null): string
    {
        $params = $this->defaultParams();
        if ($version) {
            $params['version'] = $version;
        }

        return "{$this->baseUrl}/v2/download-selective/{$this->licenseKey}/{$this->productUid}?" .
            http_build_query($params);
    }

    /**
     * Get license key
     */
    public function getLicenseKey(): string
    {
        return $this->licenseKey ?? '';
    }

    /**
     * Get product UID
     */
    public function getProductUid(): string
    {
        return $this->productUid ?? '';
    }
}
