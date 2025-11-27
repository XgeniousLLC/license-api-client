# XgApiClient V2 - Chunked Update System Implementation Guide

## Overview

This document describes the V2 chunked update system implementation for the XgApiClient package. This new system solves the timeout issues that occur when downloading large update files (100MB+) by:

1. **Chunked Downloads**: Splitting the update ZIP into 10MB chunks
2. **JavaScript Orchestration**: Using JavaScript to manage the download flow instead of PHP
3. **Resume Capability**: Tracking progress in a JSON file to allow resuming interrupted updates
4. **Batch Processing**: Extracting and replacing files in small batches

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                          Browser (JavaScript)                        │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │                     UpdateManager.js                          │    │
│  │  - Orchestrates all update phases                            │    │
│  │  - Handles retries and error recovery                        │    │
│  │  - Provides progress callbacks                               │    │
│  └─────────────────────────────────────────────────────────────┘    │
└───────────────────────────────┬─────────────────────────────────────┘
                                │ HTTP Requests
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    Laravel V2 Controllers                            │
│  ┌────────────┐ ┌──────────────┐ ┌─────────────────┐ ┌───────────┐  │
│  │ Update     │ │ Chunk        │ │ Extraction      │ │ Migration │  │
│  │ Controller │ │ Controller   │ │ Controller      │ │ Controller│  │
│  └────────────┘ └──────────────┘ └─────────────────┘ └───────────┘  │
│         │              │                 │                 │         │
└─────────┼──────────────┼─────────────────┼─────────────────┼─────────┘
          │              │                 │                 │
          ▼              ▼                 ▼                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       V2 Services Layer                              │
│  ┌──────────────────┐  ┌────────────────┐  ┌─────────────────────┐  │
│  │UpdateStatusManager│  │ChunkDownloader │  │ BatchExtractor     │  │
│  │                  │  │                │  │ BatchReplacer       │  │
│  │ .update-status   │  │ chunks/*.bin   │  │                     │  │
│  │ .json            │  │                │  │                     │  │
│  └──────────────────┘  └────────────────┘  └─────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

## File Structure

```
src/
├── Services/
│   └── V2/
│       ├── UpdateStatusManager.php   # Manages .update-status.json
│       ├── UpdateApiClient.php       # API client for license server
│       ├── ChunkDownloader.php       # Downloads individual chunks
│       ├── ChunkMerger.php           # Merges chunks into ZIP
│       ├── BatchExtractor.php        # Extracts files in batches
│       └── BatchReplacer.php         # Replaces files in batches
│
├── Http/
│   └── Controllers/
│       └── V2/
│           ├── UpdateController.php      # Main update endpoints
│           ├── ChunkController.php       # Chunk download/merge
│           ├── ExtractionController.php  # ZIP extraction
│           ├── ReplacementController.php # File replacement
│           └── MigrationController.php   # Database migration
│
resources/
├── js/
│   └── UpdateManager.js              # JavaScript orchestrator
└── views/
    └── v2/
        └── update.blade.php          # Update UI

routes/
└── web.php                           # V2 routes added
```

## Installation

### 1. Publish Assets

```bash
php artisan vendor:publish --tag=xgapiclient-assets
```

This publishes `UpdateManager.js` to `public/vendor/xgapiclient/js/`.

### 2. Publish Config (if not already done)

```bash
php artisan vendor:publish --tag=xgapiclient-config
```

### 3. Ensure Storage Directory

The package automatically creates the update directory, but you can verify:

```bash
mkdir -p storage/app/xg-update
chmod 755 storage/app/xg-update
```

## Configuration

Add these optional settings to your `.env`:

```env
# V2 Update Settings (all optional)
XG_UPDATE_CHUNK_SIZE=10485760        # 10MB default
XG_UPDATE_DOWNLOAD_TIMEOUT=300       # 5 minutes per chunk
XG_UPDATE_EXTRACTION_BATCH=100       # Files per extraction batch
XG_UPDATE_REPLACEMENT_BATCH=50       # Files per replacement batch
XG_UPDATE_ENABLE_BACKUP=false        # Backup files before replacing
XG_UPDATE_MAX_RETRIES=3              # Retry attempts for failed chunks
```

## API Endpoints

### Main Update Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/update/v2` | V2 Update page |
| GET | `/update/v2/check` | Check for updates |
| POST | `/update/v2/initiate` | Start update process |
| GET | `/update/v2/status` | Get current status |
| POST | `/update/v2/cancel` | Cancel update |
| GET | `/update/v2/resume-info` | Get resume information |
| GET | `/update/v2/logs` | Get update logs |

### Chunk Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/update/v2/chunks/download/{index}` | Download a chunk |
| GET | `/update/v2/chunks/progress` | Get download progress |
| GET | `/update/v2/chunks/missing` | Get missing chunks |
| GET | `/update/v2/chunks/verify/{index}` | Verify chunk hash |
| POST | `/update/v2/chunks/merge` | Merge chunks into ZIP |
| GET | `/update/v2/chunks/zip-info` | Get merged ZIP info |

### Extraction Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/update/v2/extraction/batch` | Extract a batch |
| GET | `/update/v2/extraction/progress` | Get extraction progress |
| POST | `/update/v2/extraction/reset` | Reset extraction |

### Replacement Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/update/v2/replacement/batch` | Replace a batch |
| GET | `/update/v2/replacement/progress` | Get replacement progress |
| POST | `/update/v2/replacement/skip-files` | Set skip files |
| POST | `/update/v2/replacement/skip-directories` | Set skip directories |

### Migration Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/update/v2/migration/run` | Run migrations |
| GET | `/update/v2/migration/status` | Get migration status |
| POST | `/update/v2/migration/complete` | Complete update |
| POST | `/update/v2/migration/finalize` | Cleanup and finalize |

## Usage

### Accessing the V2 Update Page

Navigate to `/update/v2` in your application to access the new update interface.

### Programmatic Usage

```javascript
// Initialize the UpdateManager
const updateManager = new UpdateManager({
    baseUrl: '/update/v2',
    csrfToken: document.querySelector('meta[name="csrf-token"]').content,
    onProgress: (data) => console.log('Progress:', data),
    onLog: (data) => console.log('Log:', data.message),
    onPhaseChange: (phase) => console.log('Phase:', phase),
    onError: (error) => console.error('Error:', error),
    onComplete: (result) => console.log('Complete!', result),
});

// Check for updates
const updateInfo = await updateManager.checkForUpdate();

if (updateInfo && updateInfo.update_available) {
    // Start the update
    await updateManager.startUpdate(updateInfo.latest_version, false); // false = not tenant
}
```

### Custom Integration

If you need to integrate the update system into your own UI:

```javascript
// 1. Check for updates
const response = await fetch('/update/v2/check');
const data = await response.json();

// 2. Initialize update
await fetch('/update/v2/initiate', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify({ version: '2.5.0' }),
});

// 3. Download chunks one by one
for (let i = 0; i < totalChunks; i++) {
    await fetch(`/update/v2/chunks/download/${i}`);
}

// 4. Merge chunks
await fetch('/update/v2/chunks/merge', { method: 'POST' });

// 5. Extract in batches
let batch = 0;
let hasMore = true;
while (hasMore) {
    const result = await fetch('/update/v2/extraction/batch', {
        method: 'POST',
        body: JSON.stringify({ batch }),
    }).then(r => r.json());

    hasMore = result.has_more;
    batch = result.next_batch;
}

// 6. Replace files in batches (similar to extraction)

// 7. Run migrations
await fetch('/update/v2/migration/run', {
    method: 'POST',
    body: JSON.stringify({ is_tenant: false }),
});

// 8. Complete update
await fetch('/update/v2/migration/complete', { method: 'POST' });
```

## Update Flow

### Phase 1: Initialization
1. User clicks "Start Update"
2. JavaScript calls `/update/v2/initiate`
3. Server fetches chunk info from license server
4. Creates `.update-status.json` with initial state

### Phase 2: Download
1. JavaScript downloads chunks sequentially
2. Each chunk: `/update/v2/chunks/download/{index}`
3. Server downloads from license server and saves locally
4. Hash verification on each chunk
5. Progress saved to status file after each chunk

### Phase 3: Merge
1. JavaScript calls `/update/v2/chunks/merge`
2. Server combines all chunks into single ZIP
3. Validates ZIP integrity
4. Cleans up chunk files

### Phase 4: Extraction
1. JavaScript calls `/update/v2/extraction/batch` repeatedly
2. Server extracts 100 files per batch
3. Files go to temporary extraction directory
4. Progress tracked in status file

### Phase 5: Replacement
1. JavaScript calls `/update/v2/replacement/batch` repeatedly
2. First batch enables maintenance mode
3. Server replaces 50 files per batch
4. Respects skip files/directories
5. Optional backup before replacement

### Phase 6: Migration
1. JavaScript calls `/update/v2/migration/run`
2. Server runs `php artisan migrate --force`
3. Server runs `php artisan db:seed --force`
4. For tenants: also runs `tenants:migrate`
5. Clears all caches

### Phase 7: Completion
1. JavaScript calls `/update/v2/migration/complete`
2. Server updates version in license file
3. Cleans up temporary files
4. Disables maintenance mode

## Resume Capability

If the update is interrupted (browser closed, network failure, etc.):

1. User returns to `/update/v2`
2. Page detects existing `.update-status.json`
3. Shows "Resume Update" button
4. Clicking resume continues from last phase

The status file tracks:
- Current phase
- Downloaded chunks
- Extracted files count
- Replaced files count
- All error logs

## Error Handling

### Chunk Download Failures
- Automatic retry (3 attempts by default)
- Exponential backoff between retries
- Failed chunks can be redownloaded individually

### Extraction/Replacement Failures
- Logged to status file
- Can restart from current batch
- Specific files that failed are tracked

### Migration Failures
- Environment automatically restored
- Detailed error logged
- Can retry migration

## Skip Files Configuration

Default skip files (never replaced):
- `.env`
- `.htaccess`
- `dynamic-style.css`
- `dynamic-script.js`
- `.DS_Store`

Default skip directories:
- `lang`
- `custom-fonts`
- `.git`
- `.idea`
- `.vscode`
- `.fleet`
- `node_modules`

Custom skip files can be set via API or in the update status.

## File Mapping

The BatchReplacer handles special directory mapping:

| Source Path | Destination |
|-------------|-------------|
| `public/*` | Laravel `public/` directory |
| `__rootFiles/*` | Laravel root directory |
| `assets/*` | Root `assets/` directory |
| `Modules/*` | Root `Modules/` directory |
| `plugins/*` | Root `plugins/` directory |
| `custom/*` | Handled via change-logs.json |
| Everything else | Laravel base path |

## Storage Structure

During update:
```
storage/app/xg-update/
├── .update-status.json    # Progress tracking
├── chunks/
│   ├── chunk_000.bin
│   ├── chunk_001.bin
│   └── ...
├── update.zip             # Merged ZIP
├── extracted/             # Extracted files
│   ├── app/
│   ├── public/
│   └── ...
└── backup/                # If backup enabled
    └── ...
```

After completion, all temporary files are cleaned up.

## Backward Compatibility

The V2 system does not affect the existing V1 update system:

- V1 routes (`/check-update`, `/download-update`) still work
- V1 controller unchanged
- Clients using V1 continue to work
- V2 is accessed via `/update/v2` prefix

## Troubleshooting

### "No active update" error
The status file may be missing or corrupted. Visit `/update/v2` to start fresh.

### Chunk download timeout
Increase timeout in config:
```env
XG_UPDATE_DOWNLOAD_TIMEOUT=600
```

### Extraction slow
Reduce batch size:
```env
XG_UPDATE_EXTRACTION_BATCH=50
```

### Permission errors
Ensure storage directory is writable:
```bash
chmod -R 755 storage/app/xg-update
chown -R www-data:www-data storage/app/xg-update
```

### Stuck in maintenance mode
Manually disable:
```bash
php artisan up
```

## License Server Requirements

The V2 client requires these API endpoints from the license server:

1. `GET /api/v2/update-info/{license}/{product}` - Check for updates
2. `GET /api/v2/update-manifest/{license}/{product}/chunks` - Get chunk info
3. `GET /api/v2/download-chunk/{license}/{product}/{index}` - Download chunk
4. `GET /api/v2/verify-chunk/{license}/{product}/{index}` - Verify chunk

See `LICENSE-SERVER-IMPLEMENTATION.md` for server implementation details.

## Security Considerations

1. **CSRF Protection**: All POST endpoints require CSRF token
2. **License Validation**: All downloads validate license with server
3. **Hash Verification**: Each chunk is verified against server hash
4. **Maintenance Mode**: Automatic during file replacement
5. **Cleanup**: Temporary files deleted after completion

## Performance Notes

- Chunks download sequentially (one at a time) for reliability
- Extraction and replacement use batching to avoid memory issues
- Progress is persisted after each operation
- Large files are streamed, not loaded into memory
