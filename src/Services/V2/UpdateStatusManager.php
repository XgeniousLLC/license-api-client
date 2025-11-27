<?php

namespace Xgenious\XgApiClient\Services\V2;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class UpdateStatusManager
{
    protected string $statusDir;
    protected string $statusFile;

    public function __construct()
    {
        $this->statusDir = storage_path('app/xg-update');
        $this->statusFile = $this->statusDir . '/.update-status.json';
    }

    /**
     * Initialize a new update session
     */
    public function initiate(string $targetVersion, array $updateInfo): array
    {
        $this->ensureDirectoryExists();

        $status = [
            'update_id' => 'upd_' . date('Ymd_His') . '_' . Str::random(6),
            'started_at' => now()->toIso8601String(),
            'last_activity' => now()->toIso8601String(),

            'version' => [
                'current' => $updateInfo['current_version'] ?? 'unknown',
                'target' => $targetVersion,
            ],

            'mode' => 'chunked',
            'phase' => 'initialized',

            'download' => [
                'method' => 'chunked',
                'total_size' => $updateInfo['chunked_download']['total_size'] ?? 0,
                'downloaded_size' => 0,
                'total_chunks' => $updateInfo['chunked_download']['total_chunks'] ?? 0,
                'chunk_size' => $updateInfo['chunked_download']['chunk_size'] ?? 10485760,
                'completed_chunks' => [],
                'current_chunk' => null,
                'chunk_hashes' => [],
                'zip_hash' => $updateInfo['chunked_download']['zip_hash'] ?? '',
                'percent' => 0,
            ],

            'extraction' => [
                'status' => 'pending',
                'total_files' => $updateInfo['chunked_download']['total_files'] ?? 0,
                'extracted_files' => 0,
                'current_batch' => 0,
                'total_batches' => 0,
                'percent' => 0,
            ],

            'replacement' => [
                'status' => 'pending',
                'total_files' => 0,
                'replaced_files' => 0,
                'skipped_files' => 0,
                'current_batch' => 0,
                'total_batches' => 0,
                'percent' => 0,
                'current_file' => null,
            ],

            'migration' => [
                'status' => 'pending',
                'migrations_run' => 0,
                'seeders_run' => 0,
                'tenant_progress' => [
                    'total' => 0,
                    'completed' => 0,
                ],
            ],

            'skip_files' => $updateInfo['skip_files'] ?? '',
            'skip_directories' => $updateInfo['skip_directories'] ?? '',
            'is_tenant' => $updateInfo['is_tenant'] ?? false,

            'maintenance_mode' => false,
            'errors' => [],

            'log' => [
                ['time' => now()->format('H:i:s'), 'message' => 'Update initialized'],
            ],
        ];

        $this->save($status);
        return $status;
    }

    /**
     * Get current status
     */
    public function getStatus(): ?array
    {
        if (!File::exists($this->statusFile)) {
            return null;
        }

        $content = File::get($this->statusFile);
        return json_decode($content, true);
    }

    /**
     * Update status
     */
    public function update(array $data): array
    {
        $status = $this->getStatus() ?? [];
        $status = array_merge($status, $data);
        $status['last_activity'] = now()->toIso8601String();
        $this->save($status);
        return $status;
    }

    /**
     * Update a specific phase
     */
    public function updatePhase(string $phase, array $data): array
    {
        $status = $this->getStatus() ?? [];
        $status['phase'] = $phase;

        if (isset($status[$phase])) {
            $status[$phase] = array_merge($status[$phase], $data);
        } else {
            $status[$phase] = $data;
        }

        $status['last_activity'] = now()->toIso8601String();
        $this->save($status);
        return $status;
    }

    /**
     * Mark a chunk as completed
     */
    public function markChunkCompleted(int $chunkIndex, string $hash, int $size): array
    {
        $status = $this->getStatus();

        if (!in_array($chunkIndex, $status['download']['completed_chunks'])) {
            $status['download']['completed_chunks'][] = $chunkIndex;
            sort($status['download']['completed_chunks']);
        }

        $status['download']['chunk_hashes'][$chunkIndex] = $hash;
        $status['download']['downloaded_size'] += $size;
        $status['download']['percent'] = $status['download']['total_chunks'] > 0
            ? round((count($status['download']['completed_chunks']) / $status['download']['total_chunks']) * 100)
            : 0;

        $status['last_activity'] = now()->toIso8601String();
        $this->save($status);

        return $status;
    }

    /**
     * Add log entry
     */
    public function addLog(string $message): void
    {
        $status = $this->getStatus();
        if ($status) {
            $status['log'][] = [
                'time' => now()->format('H:i:s'),
                'message' => $message,
            ];

            // Keep only last 100 log entries
            if (count($status['log']) > 100) {
                $status['log'] = array_slice($status['log'], -100);
            }

            $this->save($status);
        }
    }

    /**
     * Record an error
     */
    public function recordError(string $type, string $message, array $context = []): void
    {
        $status = $this->getStatus();
        if ($status) {
            $status['errors'][] = [
                'type' => $type,
                'message' => $message,
                'context' => $context,
                'occurred_at' => now()->toIso8601String(),
            ];
            $status['phase'] = 'error';
            $this->save($status);
        }
    }

    /**
     * Check if update can be resumed
     */
    public function canResume(): bool
    {
        $status = $this->getStatus();

        if (!$status) {
            return false;
        }

        // Can resume if not completed or in fatal error state
        return !in_array($status['phase'], ['completed', 'fatal_error']);
    }

    /**
     * Get resume point information
     */
    public function getResumePoint(): ?array
    {
        $status = $this->getStatus();

        if (!$status || !$this->canResume()) {
            return null;
        }

        return [
            'phase' => $status['phase'],
            'update_id' => $status['update_id'],
            'target_version' => $status['version']['target'],
            'download' => [
                'completed_chunks' => $status['download']['completed_chunks'],
                'total_chunks' => $status['download']['total_chunks'],
                'next_chunk' => count($status['download']['completed_chunks']),
                'percent' => $status['download']['percent'],
            ],
            'extraction' => [
                'current_batch' => $status['extraction']['current_batch'],
                'percent' => $status['extraction']['percent'],
            ],
            'replacement' => [
                'current_batch' => $status['replacement']['current_batch'],
                'percent' => $status['replacement']['percent'],
            ],
            'started_at' => $status['started_at'],
            'last_activity' => $status['last_activity'],
        ];
    }

    /**
     * Mark as complete
     */
    public function markComplete(): array
    {
        $status = $this->getStatus();
        $status['phase'] = 'completed';
        $status['completed_at'] = now()->toIso8601String();
        $status['last_activity'] = now()->toIso8601String();
        $this->save($status);
        $this->addLog('Update completed successfully');
        return $status;
    }

    /**
     * Reset status (for fresh start)
     */
    public function reset(): void
    {
        if (File::exists($this->statusFile)) {
            File::delete($this->statusFile);
        }

        // Clean up update directory contents but keep directory
        $this->cleanDirectory($this->statusDir);
    }

    /**
     * Save status to file
     */
    protected function save(array $status): void
    {
        $this->ensureDirectoryExists();
        File::put($this->statusFile, json_encode($status, JSON_PRETTY_PRINT));
    }

    /**
     * Ensure directory exists
     */
    protected function ensureDirectoryExists(): void
    {
        if (!File::isDirectory($this->statusDir)) {
            File::makeDirectory($this->statusDir, 0755, true);
        }
    }

    /**
     * Clean directory contents
     */
    protected function cleanDirectory(string $directory): void
    {
        if (!File::isDirectory($directory)) {
            return;
        }

        $items = File::files($directory);
        foreach ($items as $item) {
            File::delete($item);
        }

        $directories = File::directories($directory);
        foreach ($directories as $dir) {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Get storage paths used by the update system
     */
    public function getPaths(): array
    {
        return [
            'base' => $this->statusDir,
            'status_file' => $this->statusFile,
            'chunks' => $this->statusDir . '/chunks',
            'extracted' => $this->statusDir . '/extracted',
            'backup' => $this->statusDir . '/backup',
            'zip' => $this->statusDir . '/update.zip',
        ];
    }

    /**
     * Check if an update is currently in progress
     */
    public function isUpdateInProgress(): bool
    {
        $status = $this->getStatus();

        if (!$status) {
            return false;
        }

        return !in_array($status['phase'], ['completed', 'fatal_error', 'cancelled']);
    }

    /**
     * Mark update as cancelled
     */
    public function markCancelled(): void
    {
        $status = $this->getStatus();
        if ($status) {
            $status['phase'] = 'cancelled';
            $status['cancelled_at'] = now()->toIso8601String();
            $this->save($status);
            $this->addLog('Update cancelled by user');
        }
    }
}
