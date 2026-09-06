# Changelog

All notable changes to `XgApiClient` will be documented in this file.

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
