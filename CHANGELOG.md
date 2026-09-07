# Changelog

All notable changes to `XgApiClient` will be documented in this file.

## 6.6.1 - 2026-September-07

- Fixed: `composer test` was non-functional - `phpunit.xml` had no `<testsuites>` block, and the one existing test's data provider wasn't `static`, which PHPUnit 11 requires
- Added: `tests/TestCase.php` (Orchestra Testbench, boots the package's service provider) and `tests/Pest.php` so `tests/Unit/*` runs as Pest tests against a real Laravel app
- Added: `tests/Unit/UpdateStatusManagerTest.php` and `tests/Unit/BatchReplacerTest.php` - cover the 6.6.0 maintenance-mode fix directly (status init/resume/error tracking, and that `reset()` brings a maintenance-mode-stuck site back up, but leaves it alone when it wasn't down)

## 6.6.0 - 2026-September-07

- Fixed: a failure during the Replace phase of a V2 update could leave the live site stuck behind a maintenance page indefinitely, with no automatic recovery - any failure, cancel, or finalize now restores public access automatically (`BatchReplacer`, `UpdateStatusManager::reset()`)
- Fixed: the update wizard's own requests could get blocked by the maintenance mode it had just enabled, breaking any update over ~50 files (the default batch size) from the inside - the client now acquires Laravel's maintenance-mode bypass cookie right after maintenance mode turns on, before making its next request
- Changed: redesigned the V2 System Update page (`resources/views/v2/update.blade.php`) - warm color palette, plain-language status copy, technical detail (log console, phase stats, Composer analysis) moved behind an optional "Show technical details" disclosure, and dedicated Interrupted/Error states replacing the old `alert()`-based error handling
- Removed: the permanently-hidden, non-functional Pause button and its broken `pause()`/`isPaused` handling in `UpdateManager.js`; the unused `chunkConcurrency` option; the unused `getStatus()`/`getLogs()` client methods

## 6.5.3 - 2026-September-07

- Changed: `base_api_url` is no longer read from `XG_LICENSE_API_URL` in `.env` - there is only ever one xgenious license server, so it's now a fixed value (`https://license.xgenious.com`) in `config/xgapiclient.php`, same rationale as the update-tuning knobs fixed in 6.5.2. `XG_PRODUCT_TOKEN` remains the only supported `.env` override
- Docs: README and V2 guides updated - `.env` setup now shows only `XG_PRODUCT_TOKEN`; the README's config file example was also brought back in sync with 6.5.2's changes (it still showed the old `env()`-wrapped values)

## 6.5.2 - 2026-September-07

- Fixed: extraction/replacement batch size, chunk size, download timeout, retry count, backup, and smart-vendor-replacement are no longer read from `.env` - they're fixed internal defaults in `config/xgapiclient.php` now. A malformed override (e.g. `XG_UPDATE_EXTRACTION_BATCH` pasted as `"100       # Files per extraction batch"`, which some hosting-panel env editors produce by storing everything after `=` literally, comment included) was hitting PHP's "A non-numeric value encountered" warning in batch-count arithmetic, promoted by Laravel's error handler into a fatal `ErrorException` on the very first extraction batch. `XG_LICENSE_API_URL` and `XG_PRODUCT_TOKEN` are unaffected and remain the only supported `.env` overrides
- Hardened: `BatchExtractor` and `BatchReplacer` also cast the batch size to `int` via a shared `resolveBatchSize()` guard as defense in depth, in case a future config value isn't a clean positive integer
- Docs: README and V2 guides no longer document the removed `.env` variables; existing sites with them still set can leave them in place (now silently ignored) or remove them

## 6.5.1 - 2026-September-07

- Changed: the `Memory Limit` and `Execution Time Limit` readiness checks now block the update (status `fail`) instead of only warning - an admin is required to raise `memory_limit`/`max_execution_time` before the update proceeds, rather than being allowed to continue on a setting likely to fail partway through

## 6.5.0 - 2026-September-07

- Added: `SystemReadinessChecker` service + `POST /update/v2/system-check` endpoint - verifies PHP version, required extensions (zip, curl, mbstring, openssl, fileinfo, json), memory_limit, max_execution_time, free disk space (sized against the actual update package size), and directory write permissions before a chunked update proceeds
- The check now runs automatically right after `/initiate` succeeds, before any chunk is downloaded - a blocking failure (e.g. missing extension, insufficient disk space) aborts the update immediately with the specific problem and suggested fix, instead of failing partway through download/extraction with no clear cause

## 6.4.2 - 2026-September-06

- Fixed: `BatchExtractor::extractBatch()` now catches `\Throwable` instead of only `\Exception`, so a `TypeError`/`Error` during extraction returns a clean JSON error instead of crashing to a raw 500
- Added: extraction failures now log the exception class, file, line, and full trace (previously only the message), so reports like "A non-numeric value encountered" are actually debuggable from `storage/logs/laravel.log` or the update status file instead of needing to be reproduced blind

## 6.4.1 - 2026-September-06

- Fixed: `UpdateApiClient::checkForUpdate()` now safely handles a non-JSON/non-array error body instead of only trusting `message` on an array
- Added: when an in-browser V2 update fails, the log now suggests downloading the update file manually from My Account → Downloads or opening a support ticket

## 6.3.0 - 2026-April-10

- Added Laravel 13+ support
- Updated PHP requirement to ^8.2 || ^8.3

## 1.0.0 - 202X-XX-XX

- initial release
