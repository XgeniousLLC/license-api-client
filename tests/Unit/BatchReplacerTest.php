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
