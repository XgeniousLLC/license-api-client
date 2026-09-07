# Changelog

All notable changes to `XgApiClient` will be documented in this file.

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
