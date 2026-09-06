<?php

namespace Xgenious\XgApiClient\Services\V2;

class SystemReadinessChecker
{
    /**
     * Extensions the V2 chunked update system relies on directly.
     */
    protected const REQUIRED_EXTENSIONS = ['zip', 'curl', 'mbstring', 'openssl', 'fileinfo', 'json'];

    /**
     * Recommended minimum memory_limit, in bytes, when the value isn't
     * unlimited (-1). Below this, large-project extraction/merge is at real
     * risk of hitting "Allowed memory size exhausted".
     */
    protected const RECOMMENDED_MEMORY_LIMIT_BYTES = 256 * 1024 * 1024; // 256M

    /**
     * Recommended minimum max_execution_time (seconds) when not unlimited
     * (0). The merge step writes the whole reassembled ZIP in one request.
     */
    protected const RECOMMENDED_MAX_EXECUTION_TIME = 300;

    /**
     * Run all readiness checks. $totalSize (bytes) is the update package
     * size if known (from the update-info/initiate response) - when
     * provided, the disk-space check is sized against it rather than using
     * a flat guess.
     *
     * @return array{ready: bool, checks: array}
     */
    public function check(int $totalSize = 0, ?string $phpVersionRequired = null, array $extensionsRequired = []): array
    {
        $checks = [];

        $checks[] = $this->checkPhpVersion($phpVersionRequired);
        $checks[] = $this->checkExtensions($extensionsRequired);
        $checks[] = $this->checkMemoryLimit();
        $checks[] = $this->checkExecutionTime();
        $checks[] = $this->checkDiskSpace($totalSize);
        $checks[] = $this->checkWritable();

        $ready = collect($checks)->doesntContain(fn ($check) => $check['status'] === 'fail');

        return [
            'ready' => $ready,
            'checks' => $checks,
        ];
    }

    protected function checkPhpVersion(?string $required): array
    {
        $current = PHP_VERSION;

        if ($required && version_compare($current, $required, '<')) {
            return $this->result(
                'PHP Version',
                'fail',
                "Running PHP {$current}, but this update requires PHP {$required} or newer.",
                "Ask your hosting provider to upgrade PHP to {$required}+ before updating."
            );
        }

        return $this->result('PHP Version', 'pass', "PHP {$current}");
    }

    protected function checkExtensions(array $required): array
    {
        $needed = array_unique(array_merge(self::REQUIRED_EXTENSIONS, array_filter($required)));
        $missing = array_values(array_filter($needed, fn ($ext) => !extension_loaded($ext)));

        if (!empty($missing)) {
            $list = implode(', ', $missing);
            return $this->result(
                'PHP Extensions',
                'fail',
                "Missing required PHP extension(s): {$list}.",
                "Ask your hosting provider to enable: {$list} (usually via php.ini or a control panel PHP extension manager)."
            );
        }

        return $this->result('PHP Extensions', 'pass', 'All required extensions are enabled.');
    }

    protected function checkMemoryLimit(): array
    {
        $limit = $this->parseIniBytes((string) ini_get('memory_limit'));

        if ($limit === -1) {
            return $this->result('Memory Limit', 'pass', 'memory_limit is unlimited (-1).');
        }

        if ($limit < self::RECOMMENDED_MEMORY_LIMIT_BYTES) {
            $current = ini_get('memory_limit');
            return $this->result(
                'Memory Limit',
                'warning',
                "memory_limit is {$current}, which may not be enough for a large update.",
                'Raise memory_limit to at least 256M in php.ini (or via .htaccess / control panel), especially for large projects.'
            );
        }

        return $this->result('Memory Limit', 'pass', 'memory_limit: ' . ini_get('memory_limit'));
    }

    protected function checkExecutionTime(): array
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit === 0) {
            return $this->result('Execution Time Limit', 'pass', 'max_execution_time is unlimited (0).');
        }

        if ($limit < self::RECOMMENDED_MAX_EXECUTION_TIME) {
            return $this->result(
                'Execution Time Limit',
                'warning',
                "max_execution_time is {$limit}s, which may be too short for the merge/extraction steps on a large update.",
                'Raise max_execution_time to at least 300 (or 0 for unlimited) in php.ini for the duration of the update.'
            );
        }

        return $this->result('Execution Time Limit', 'pass', "max_execution_time: {$limit}s");
    }

    protected function checkDiskSpace(int $totalSize): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);

        if ($free === false) {
            return $this->result(
                'Disk Space',
                'warning',
                'Could not determine free disk space.',
                'Manually verify there is enough free space (several times the update size) before proceeding.'
            );
        }

        // Rough but deliberately generous: the downloaded/merged ZIP, its
        // extracted (uncompressed, typically larger) contents, and a backup
        // copy of replaced files if backups are enabled - covered by a flat
        // 4x multiplier on the known package size.
        $required = $totalSize > 0 ? $totalSize * 4 : 1024 * 1024 * 1024; // 1GB fallback guess if size unknown

        if ($free < $required) {
            return $this->result(
                'Disk Space',
                'fail',
                sprintf(
                    'Only %s free, but this update needs roughly %s (package + extraction + backup headroom).',
                    $this->formatBytes((int) $free),
                    $this->formatBytes($required)
                ),
                'Free up disk space (old logs, backups, unused files) before starting the update.'
            );
        }

        return $this->result('Disk Space', 'pass', $this->formatBytes((int) $free) . ' free');
    }

    protected function checkWritable(): array
    {
        $paths = [
            storage_path('app/xg-update'),
            base_path(),
        ];

        $notWritable = [];
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            if (!is_writable($path)) {
                $notWritable[] = $path;
            }
        }

        if (!empty($notWritable)) {
            $list = implode(', ', $notWritable);
            return $this->result(
                'File Permissions',
                'fail',
                "Not writable: {$list}",
                'Fix ownership/permissions on these paths (typically the web server user needs write access) before updating.'
            );
        }

        return $this->result('File Permissions', 'pass', 'Required directories are writable.');
    }

    protected function result(string $name, string $status, string $message, ?string $suggestion = null): array
    {
        return [
            'name' => $name,
            'status' => $status, // pass|warning|fail
            'message' => $message,
            'suggestion' => $suggestion,
        ];
    }

    /**
     * Parse a php.ini-style size value ("256M", "1G", "-1") into bytes.
     */
    protected function parseIniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return round($value, 2) . ' ' . $units[$i];
    }
}
