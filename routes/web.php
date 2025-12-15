<?php

use Xgenious\XgApiClient\Http\Controllers\ActivationController;
use Xgenious\XgApiClient\Http\Controllers\SystemUpgradeController;
use Xgenious\XgApiClient\Http\Controllers\V2\UpdateController;
use Xgenious\XgApiClient\Http\Controllers\V2\ChunkController;
use Xgenious\XgApiClient\Http\Controllers\V2\ExtractionController;
use Xgenious\XgApiClient\Http\Controllers\V2\ReplacementController;
use Xgenious\XgApiClient\Http\Controllers\V2\MigrationController;

// ============================================
// V1 Routes (Legacy - Unchanged)
// ============================================
Route::get('/license-activation', [ActivationController::class, 'licenseActivation'])->name('license.activation');
Route::post('/license-activation-update', [ActivationController::class, 'licenseActivationUpdate'])->name('license.activation.update');
Route::get('/check-update', [SystemUpgradeController::class, 'checkSystemUpdate'])->name('check.system.update');
Route::post('/download-update/{productId}/{tenant}', [SystemUpgradeController::class, 'updateDownloadLatestVersion'])->name('update.download');

// ============================================
// V2 Routes (Chunked Update System)
// ============================================
Route::prefix('update/v2')->name('xg.update.v2.')->group(function () {
    // Main update page and status
    Route::get('/', [UpdateController::class, 'index'])->name('index');
    Route::get('/check', [UpdateController::class, 'checkUpdate'])->name('check');
    Route::post('/initiate', [UpdateController::class, 'initiate'])->name('initiate');
    Route::get('/status', [UpdateController::class, 'status'])->name('status');
    Route::post('/cancel', [UpdateController::class, 'cancel'])->name('cancel');
    Route::get('/resume-info', [UpdateController::class, 'resumeInfo'])->name('resume-info');
    Route::get('/logs', [UpdateController::class, 'logs'])->name('logs');

    // Chunk download routes
    Route::prefix('chunks')->name('chunks.')->group(function () {
        Route::get('/download/{chunkIndex}', [ChunkController::class, 'download'])->name('download');
        Route::get('/progress', [ChunkController::class, 'progress'])->name('progress');
        Route::get('/missing', [ChunkController::class, 'missing'])->name('missing');
        Route::get('/verify/{chunkIndex}', [ChunkController::class, 'verify'])->name('verify');
        Route::post('/merge', [ChunkController::class, 'merge'])->name('merge');
        Route::get('/zip-info', [ChunkController::class, 'zipInfo'])->name('zip-info');
        Route::post('/redownload/{chunkIndex}', [ChunkController::class, 'redownload'])->name('redownload');
    });

    // Extraction routes
    Route::prefix('extraction')->name('extraction.')->group(function () {
        Route::post('/batch', [ExtractionController::class, 'extractBatch'])->name('batch');
        Route::get('/progress', [ExtractionController::class, 'progress'])->name('progress');
        Route::get('/file-count', [ExtractionController::class, 'fileCount'])->name('file-count');
        Route::get('/files', [ExtractionController::class, 'fileList'])->name('files');
        Route::post('/reset', [ExtractionController::class, 'reset'])->name('reset');
    });

    // Replacement routes
    Route::prefix('replacement')->name('replacement.')->group(function () {
        Route::post('/batch', [ReplacementController::class, 'replaceBatch'])->name('batch');
        Route::get('/progress', [ReplacementController::class, 'progress'])->name('progress');
        Route::get('/files', [ReplacementController::class, 'fileList'])->name('files');
        Route::get('/composer-analysis', [ReplacementController::class, 'composerAnalysis'])->name('composer-analysis');
        Route::post('/skip-files', [ReplacementController::class, 'setSkipFiles'])->name('skip-files');
        Route::post('/skip-directories', [ReplacementController::class, 'setSkipDirectories'])->name('skip-directories');
        Route::post('/reset', [ReplacementController::class, 'reset'])->name('reset');
        Route::post('/maintenance', [ReplacementController::class, 'maintenanceMode'])->name('maintenance');
    });

    // Migration routes
    Route::prefix('migration')->name('migration.')->group(function () {
        Route::post('/run', [MigrationController::class, 'migrate'])->name('run');
        Route::get('/status', [MigrationController::class, 'status'])->name('status');
        Route::post('/clear-caches', [MigrationController::class, 'clearCachesOnly'])->name('clear-caches');
        Route::post('/complete', [MigrationController::class, 'complete'])->name('complete');
        Route::post('/finalize', [MigrationController::class, 'finalize'])->name('finalize');
    });
});
