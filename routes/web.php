<?php

Route::get('/license-activation', [\Xgenious\XgApiClient\Http\Controllers\ActivationController::class, 'licenseActivation'])->name('license.activation');
Route::post('/license-activation-update', [\Xgenious\XgApiClient\Http\Controllers\ActivationController::class, 'licenseActivationUpdate'])->name('license.activation.update');
Route::get('/check-update', [\Xgenious\XgApiClient\Http\Controllers\SystemUpgradeController::class, 'checkSystemUpdate'])->name('check.system.update');
Route::post('/download-update/{productId}/{tenant}', [\Xgenious\XgApiClient\Http\Controllers\SystemUpgradeController::class, 'updateDownloadLatestVersion'])->name('update.download');

