<?php

use Xgenious\XgApiClient\Services\V2\UpdateStatusManager;

beforeEach(function () {
    (new UpdateStatusManager())->reset();
});

afterEach(function () {
    if (app()->isDownForMaintenance()) {
        $this->artisan('up');
    }
});

it('initializes a fresh update status with the expected shape', function () {
    $manager = new UpdateStatusManager();

    $status = $manager->initiate('2.0.0', [
        'current_version' => '1.0.0',
        'chunked_download' => [
            'total_chunks' => 5,
            'total_size' => 1000,
            'chunk_size' => 200,
            'zip_hash' => 'abc',
            'total_files' => 10,
        ],
    ]);

    expect($status['version']['current'])->toBe('1.0.0')
        ->and($status['version']['target'])->toBe('2.0.0')
        ->and($status['phase'])->toBe('initialized')
        ->and($status['download']['total_chunks'])->toBe(5)
        ->and($status['maintenance_mode'])->toBeFalse()
        ->and($manager->getStatus())->toBe($status);
});

it('tracks resumability based on phase', function () {
    $manager = new UpdateStatusManager();
    $manager->initiate('2.0.0', []);

    expect($manager->canResume())->toBeTrue();

    $manager->update(['phase' => 'replacement']);
    expect($manager->canResume())->toBeTrue();

    $manager->update(['phase' => 'completed']);
    expect($manager->canResume())->toBeFalse();
});

it('marks the update as errored and stops it being resumable', function () {
    $manager = new UpdateStatusManager();
    $manager->initiate('2.0.0', []);

    $manager->recordError('replacement_failed', 'Something broke');

    $status = $manager->getStatus();

    expect($status['phase'])->toBe('error')
        ->and($status['errors'])->toHaveCount(1)
        ->and($status['errors'][0]['message'])->toBe('Something broke')
        ->and($manager->canResume())->toBeFalse();
});

it('brings the site back up when resetting while stuck in maintenance mode', function () {
    $this->artisan('down');
    expect(app()->isDownForMaintenance())->toBeTrue();

    (new UpdateStatusManager())->reset();

    expect(app()->isDownForMaintenance())->toBeFalse();
});

it('does not touch maintenance mode when resetting while the site is already up', function () {
    expect(app()->isDownForMaintenance())->toBeFalse();

    (new UpdateStatusManager())->reset();

    expect(app()->isDownForMaintenance())->toBeFalse();
});

it('clears the status file on reset', function () {
    $manager = new UpdateStatusManager();
    $manager->initiate('2.0.0', []);

    expect($manager->getStatus())->not->toBeNull();

    $manager->reset();

    expect($manager->getStatus())->toBeNull();
});
