<?php

namespace Xgenious\XgApiClient\Services\V2;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ComposerDiffHandler
{
    protected UpdateStatusManager $statusManager;
    protected array $changedPackages = [];
    protected array $addedPackages = [];
    protected array $removedPackages = [];
    protected array $lockedChanges = [];
    protected bool $analyzed = false;

    public function __construct(UpdateStatusManager $statusManager)
    {
        $this->statusManager = $statusManager;

        $status = $statusManager->getStatus();
        if ($status && isset($status['composer_analysis'])) {
            $analysis = $status['composer_analysis'];
            $this->analyzed = true;
            $this->addedPackages = $analysis['details']['added_packages'] ?? [];
            $this->removedPackages = $analysis['details']['removed_packages'] ?? [];
            $this->changedPackages = $analysis['details']['changed_packages'] ?? [];
            $this->lockedChanges = $analysis['locked_changes'] ?? [];
        }
    }

    public function analyze(string $extractedPath): array
    {
        $this->statusManager->addLog('Analyzing Composer dependencies...');

        try {
            $realRoot = $this->detectUpdateRoot($extractedPath);

            $currentComposerJson = base_path('composer.json');
            $currentComposerLock = base_path('composer.lock');

            $updateComposerJson = $realRoot . '/custom/composer.json';
            $updateComposerLock = $realRoot . '/custom/composer.lock';

            if (!File::exists($updateComposerJson)) {
                $this->statusManager->addLog('No composer.json in update. Skipping vendor comparison.');
                $this->analyzed = true;
                return $this->getEmptyAnalysisResult();
            }

            if (!File::exists($currentComposerJson)) {
                $this->statusManager->addLog('No composer.json in current project. Will copy all vendor files.');
                $this->analyzed = true;
                return $this->getEmptyAnalysisResult();
            }

            $currentJson = json_decode(File::get($currentComposerJson), true);
            $currentLock = File::exists($currentComposerLock)
                ? json_decode(File::get($currentComposerLock), true)
                : null;

            $updateJson = json_decode(File::get($updateComposerJson), true);
            $updateLock = File::exists($updateComposerLock)
                ? json_decode(File::get($updateComposerLock), true)
                : null;

            if (!$currentJson || !$updateJson) {
                throw new \Exception('Invalid composer.json format');
            }

            $currentRequire = $currentJson['require'] ?? [];
            $updateRequire = $updateJson['require'] ?? [];

            $this->addedPackages = array_diff_key($updateRequire, $currentRequire);

            $this->removedPackages = array_diff_key($currentRequire, $updateRequire);

            $this->changedPackages = [];
            foreach ($updateRequire as $package => $version) {
                if (isset($currentRequire[$package]) && $currentRequire[$package] !== $version) {
                    $this->changedPackages[$package] = [
                        'old' => $currentRequire[$package],
                        'new' => $version,
                    ];
                }
            }

            $this->lockedChanges = [];
            if ($currentLock && $updateLock) {
                $this->lockedChanges = $this->compareLockedVersions($currentLock, $updateLock);
            }

            $hasChanges = !empty($this->addedPackages) ||
                !empty($this->removedPackages) ||
                !empty($this->changedPackages) ||
                !empty($this->lockedChanges);

            $this->analyzed = true;

            $result = [
                'status' => 'analyzed',
                'has_changes' => $hasChanges,
                'requires_composer_update' => $hasChanges,
                'statistics' => [
                    'added' => count($this->addedPackages),
                    'removed' => count($this->removedPackages),
                    'changed' => count($this->changedPackages),
                ],
                'details' => [
                    'added_packages' => $this->addedPackages,
                    'removed_packages' => $this->removedPackages,
                    'changed_packages' => $this->changedPackages,
                ],
                'locked_changes' => $this->lockedChanges,
                'summary' => $this->generateSummary(),
                'affected_vendor_paths' => $this->getAffectedVendorPaths(),
                'vendor_paths_to_remove' => $this->getVendorPathsToRemove(),
            ];

            if ($hasChanges) {
                $this->statusManager->addLog($result['summary']);

                $affectedPaths = $this->getAffectedVendorPaths();
                if (!empty($affectedPaths)) {
                    $this->statusManager->addLog('Affected vendor packages: ' . implode(', ', $affectedPaths));
                }
            } else {
                $this->statusManager->addLog('No Composer dependency changes detected.');
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Composer analysis failed', ['error' => $e->getMessage()]);
            $this->statusManager->addLog('Failed to analyze Composer changes: ' . $e->getMessage());

            return $this->getEmptyAnalysisResult('analysis_failed', $e->getMessage());
        }
    }

    protected function detectUpdateRoot(string $path): string
    {
        $currentPath = $path;

        for ($i = 0; $i < 5; $i++) {
            $files = File::files($currentPath);
            $directories = File::directories($currentPath);

            if (count($files) > 0) {
                return $currentPath;
            }

            if (count($directories) > 1) {
                return $currentPath;
            }

            if (count($directories) === 1) {
                $currentPath = $directories[0];
                continue;
            }

            return $path;
        }

        return $currentPath;
    }

    protected function getEmptyAnalysisResult(string $status = 'no_changes', ?string $error = null): array
    {
        return [
            'status' => $status,
            'error' => $error,
            'has_changes' => false,
            'requires_composer_update' => false,
            'statistics' => [
                'added' => 0,
                'removed' => 0,
                'changed' => 0,
            ],
            'details' => [
                'added_packages' => [],
                'removed_packages' => [],
                'changed_packages' => [],
            ],
            'locked_changes' => [],
            'summary' => $error ?? 'No Composer changes detected',
            'affected_vendor_paths' => [],
            'vendor_paths_to_remove' => [],
        ];
    }

    protected function compareLockedVersions(array $currentLock, array $updateLock): array
    {
        $changes = [];

        $currentPackages = $this->indexLockPackages($currentLock['packages'] ?? []);
        $updatePackages = $this->indexLockPackages($updateLock['packages'] ?? []);

        foreach ($updatePackages as $name => $package) {
            if (!isset($currentPackages[$name])) {
                $changes[$name] = [
                    'type' => 'added',
                    'version' => $package['version'],
                ];
            } elseif ($currentPackages[$name]['version'] !== $package['version']) {
                $changes[$name] = [
                    'type' => 'changed',
                    'old_version' => $currentPackages[$name]['version'],
                    'new_version' => $package['version'],
                ];
            }
        }

        foreach ($currentPackages as $name => $package) {
            if (!isset($updatePackages[$name])) {
                $changes[$name] = [
                    'type' => 'removed',
                    'version' => $package['version'],
                ];
            }
        }

        return $changes;
    }

    protected function indexLockPackages(array $packages): array
    {
        $indexed = [];
        foreach ($packages as $package) {
            $indexed[$package['name']] = $package;
        }
        return $indexed;
    }

    protected function generateSummary(): string
    {
        $parts = [];

        if (!empty($this->addedPackages)) {
            $parts[] = count($this->addedPackages) . ' package(s) added';
        }

        if (!empty($this->removedPackages)) {
            $parts[] = count($this->removedPackages) . ' package(s) removed';
        }

        if (!empty($this->changedPackages)) {
            $parts[] = count($this->changedPackages) . ' package(s) updated';
        }

        return empty($parts)
            ? 'No Composer changes detected'
            : 'Composer changes: ' . implode(', ', $parts);
    }

    public function getAffectedVendorPaths(): array
    {
        if (!$this->analyzed) {
            return [];
        }

        $paths = [];

        foreach (array_keys($this->addedPackages) as $package) {
            $paths[] = 'vendor/' . $package;
        }

        foreach (array_keys($this->changedPackages) as $package) {
            $paths[] = 'vendor/' . $package;
        }

        foreach (array_keys($this->lockedChanges) as $package) {
            $change = $this->lockedChanges[$package];
            if (in_array($change['type'] ?? '', ['changed', 'added'])) {
                $paths[] = 'vendor/' . $package;
            }
        }

        return array_unique($paths);
    }

    public function getVendorPathsToRemove(): array
    {
        if (!$this->analyzed) {
            return [];
        }

        $paths = [];
        foreach (array_keys($this->removedPackages) as $package) {
            $paths[] = 'vendor/' . $package;
        }

        return $paths;
    }

    /**
     * Check if vendor path should be REPLACED
     * Returns TRUE only for packages that need replacement:
     * - New packages (added)
     * - Updated packages (changed in composer.json or composer.lock)
     * - Missing packages in current vendor (filesystem check)
     * 
     * Returns FALSE for:
     * - Unchanged packages (same version, already exists)
     * - Removed packages
     */
    public function shouldReplaceVendorPath(string $relativePath): bool
    {
        if (!$this->analyzed) {
            // If not analyzed, check if package exists in current vendor
            return $this->shouldReplaceByFilesystemCheck($relativePath);
        }

        if (!str_starts_with($relativePath, 'vendor/')) {
            return true; // Not a vendor path
        }

        $packageName = $this->extractPackageNameFromPath($relativePath);

        if (!$packageName) {

            // These should always be replaced as they're generated by Composer
            $rootVendorFiles = [
                'vendor/autoload.php',
                'vendor/composer/',
            ];

            foreach ($rootVendorFiles as $rootFile) {
                if (str_starts_with($relativePath, $rootFile)) {
                    return true;
                }
            }

            // Fallback: if file does not exist, replace it
            $currentPath = base_path($relativePath);
            if (!File::exists($currentPath)) {
                return true;
            }

            // Otherwise, keep existing file
            Log::warning("Could not extract package from path: {$relativePath}");
            return false;
        }

        // Skip removed packages
        if (array_key_exists($packageName, $this->removedPackages)) {
            Log::debug("Package '{$packageName}' is removed, skipping: {$relativePath}");
            return false;
        }

        // Replace added packages
        if (array_key_exists($packageName, $this->addedPackages)) {
            return true;
        }

        // Replace changed packages (version constraint changed)
        if (array_key_exists($packageName, $this->changedPackages)) {
            return true;
        }

        // Replace packages with locked version changes
        if (isset($this->lockedChanges[$packageName])) {
            $change = $this->lockedChanges[$packageName];
            if (in_array($change['type'] ?? '', ['changed', 'added'])) {
                return true;
            }
        }

        // For unchanged packages, check if they exist in current vendor
        // If missing, replace them (handles corrupted/incomplete vendor)
        $currentPath = base_path($relativePath);
        if (!File::exists($currentPath)) {
            return true;
        }

        return false;
    }

    /**
     * Fallback: Check by filesystem if package exists in current vendor
     * Used when composer analysis is not available
     */
    protected function shouldReplaceByFilesystemCheck(string $relativePath): bool
    {
        if (!str_starts_with($relativePath, 'vendor/')) {
            return true;
        }

        $currentPath = base_path($relativePath);

        // If file doesn't exist in current vendor, replace it
        if (!File::exists($currentPath)) {
            return true;
        }
        return false;
    }

    /**
     * Extract package name from vendor path
     * Returns "organization/package" format
     */
    protected function extractPackageNameFromPath(string $relativePath): ?string
    {
        if (!str_starts_with($relativePath, 'vendor/')) {
            return null;
        }

        $withoutVendor = substr($relativePath, 7);
        $parts = explode('/', $withoutVendor);

        if (count($parts) < 2) {
            return null;
        }

        return $parts[0] . '/' . $parts[1];
    }

    public function removeObsoletePackages(): int
    {
        $removed = 0;
        $pathsToRemove = $this->getVendorPathsToRemove();

        foreach ($pathsToRemove as $path) {
            $fullPath = base_path($path);

            if (File::isDirectory($fullPath)) {
                try {
                    File::deleteDirectory($fullPath);
                    $removed++;
                } catch (\Exception $e) {
                    Log::warning("Failed to remove vendor package: {$path}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        if ($removed > 0) {
            $this->statusManager->addLog("Removed {$removed} obsolete vendor package(s)");
        }

        return $removed;
    }

    public function getDetailedReport(): array
    {
        if (!$this->analyzed) {
            return $this->getEmptyAnalysisResult('not_analyzed');
        }

        return [
            'status' => 'analyzed',
            'has_changes' => !empty($this->addedPackages) ||
                !empty($this->removedPackages) ||
                !empty($this->changedPackages),
            'requires_composer_update' => !empty($this->addedPackages) ||
                !empty($this->removedPackages) ||
                !empty($this->changedPackages),
            'statistics' => [
                'added' => count($this->addedPackages),
                'removed' => count($this->removedPackages),
                'changed' => count($this->changedPackages),
            ],
            'details' => [
                'added_packages' => $this->addedPackages,
                'removed_packages' => $this->removedPackages,
                'changed_packages' => $this->changedPackages,
            ],
            'affected_vendor_paths' => $this->getAffectedVendorPaths(),
            'vendor_paths_to_remove' => $this->getVendorPathsToRemove(),
        ];
    }

    public function requiresComposerUpdate(): bool
    {
        return $this->analyzed && (
            !empty($this->addedPackages) ||
            !empty($this->removedPackages) ||
            !empty($this->changedPackages)
        );
    }
}
