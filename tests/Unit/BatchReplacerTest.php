<?php

use Xgenious\XgApiClient\Services\V2\BatchReplacer;
use Xgenious\XgApiClient\Services\V2\UpdateStatusManager;

beforeEach(function () {
    (new UpdateStatusManager())->reset();
});

afterEach(function () {
    if (app()->isDownForMaintenance()) {
        $this->artisan('up');
    }
});

it('builds the maintenance bypass url from the same secret used to enable maintenance mode', function () {
    $replacer = new BatchReplacer(new UpdateStatusManager());

    // The client visits this exact URL to acquire Laravel's maintenance
    // bypass cookie right after maintenance mode turns on - if this secret
    // ever drifts from the one passed to `artisan down --secret=...`, the
    // update wizard would start blocking its own requests again.
    expect($replacer->getMaintenanceBypassUrl())->toBe(url('/xg-update-in-progress'));
});

it('caches the replacement file list for the rest of the update run', function () {
    $manager = new UpdateStatusManager();
    $manager->initiate('2.0.0', []);
    $paths = $manager->getPaths();

    @mkdir($paths['extracted'] . '/sub', 0755, true);
    file_put_contents($paths['extracted'] . '/a.txt', 'a');
    file_put_contents($paths['extracted'] . '/sub/b.txt', 'b');

    $build = new ReflectionMethod(BatchReplacer::class, 'buildFileList');
    $build->setAccessible(true);

    $replacer = new BatchReplacer($manager);
    $build->invoke($replacer, $paths['extracted']);

    expect($replacer->getFileList())->toHaveCount(2)
        ->and(is_file($paths['base'] . '/.replacement-file-list.json'))->toBeTrue();

    // A file disappears afterwards: a fresh instance (next batch request)
    // must reuse the cached list instead of rescanning the disk.
    unlink($paths['extracted'] . '/a.txt');

    $nextBatch = new BatchReplacer(new UpdateStatusManager());
    $build->invoke($nextBatch, $paths['extracted']);

    expect($nextBatch->getFileList())->toHaveCount(2);

    // A new update id invalidates the cache and rescans.
    $manager->initiate('3.0.0', []);

    $newUpdate = new BatchReplacer(new UpdateStatusManager());
    $build->invoke($newUpdate, $paths['extracted']);

    expect($newUpdate->getFileList())->toHaveCount(1);
});
