/**
 * XgApiClient V2 Update Manager
 *
 * JavaScript orchestrator for chunked update downloads.
 * This class manages the entire update process from checking for updates
 * to completing the migration, with full resume capability.
 */
class UpdateManager {
    constructor(options = {}) {
        this.baseUrl = options.baseUrl || '/update/v2';
        this.csrfToken = options.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;

        // Callbacks
        this.onProgress = options.onProgress || (() => { });
        this.onLog = options.onLog || (() => { });
        this.onPhaseChange = options.onPhaseChange || (() => { });
        this.onError = options.onError || (() => { });
        this.onComplete = options.onComplete || (() => { });
        this.onComposerAnalysis = options.onComposerAnalysis || (() => { });

        // State
        this.isRunning = false;
        this.currentPhase = null;
        this.status = null;
        this.abortController = null;
        this.composerAnalysis = null;

        // Configuration
        this.retryAttempts = options.retryAttempts || 3;
        this.retryDelay = options.retryDelay || 2000;
    }

    /**
     * Make an API request
     */
    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}${endpoint}`;

        const config = {
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
            },
            ...options,
        };

        if (this.abortController) {
            config.signal = this.abortController.signal;
        }

        const response = await fetch(url, config);
        const data = await response.json();

        if (!response.ok && !data.success) {
            throw new Error(data.message || `Request failed: ${response.status}`);
        }

        return data;
    }

    /**
     * Log a message
     */
    log(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        this.onLog({ message, type, timestamp });
        console.log(`[${timestamp}] [${type.toUpperCase()}] ${message}`);
    }

    /**
     * Update progress
     */
    updateProgress(phase, percent, details = {}) {
        this.onProgress({ phase, percent, ...details });
    }

    /**
     * Check for available updates
     */
    async checkForUpdate() {
        this.log('Checking for updates...');

        try {
            const result = await this.request('/check');

            if (result.success && result.data?.update_available) {
                this.log(`Update available: v${result.data.latest_version}`);
                this.lastCheckInfo = result.data;
                return result.data;
            } else if (result.success) {
                this.log('No updates available');
                return null;
            }

            throw new Error(result.message || 'Failed to check for updates');
        } catch (error) {
            this.log(`Update check failed: ${error.message}`, 'error');
            throw error;
        }
    }

    /**
     * Check if update can be resumed
     */
    async canResume() {
        try {
            const result = await this.request('/resume-info');
            return result.can_resume ? result.resume_point : null;
        } catch (error) {
            return null;
        }
    }

    /**
     * Get composer analysis
     */
    async getComposerAnalysis() {
        try {
            const result = await this.request('/replacement/composer-analysis');
            this.composerAnalysis = result.report;
            return result.report;
        } catch (error) {
            this.log(`Failed to get composer analysis: ${error.message}`, 'warning');
            return null;
        }
    }

    /**
     * Start or resume update process
     */
    async startUpdate(version, isTenant = false) {
        if (this.isRunning) {
            this.log('Update already in progress', 'warning');
            return;
        }

        this.isRunning = true;
        this.abortController = new AbortController();

        try {
            // Check if we can resume an existing update
            const resumePoint = await this.canResume();

            if (resumePoint) {
                this.log(`Resuming update from phase: ${resumePoint.phase}`);
                await this.resumeFromPhase(resumePoint, isTenant);
            } else {
                // Start fresh update
                if (!version) {
                    throw new Error('Version parameter is required to start a new update. Please check for updates first.');
                }
                this.log(`Starting update to version ${version}`);
                await this.initiateUpdate(version, isTenant);
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                this.log('Update cancelled', 'warning');
            } else {
                this.log(`Update failed: ${error.message}`, 'error');

                // Last-resort safety net: never leave the public site behind
                // a maintenance page just because this run failed. Normally
                // the server already restores access itself on error; this
                // only matters if the failure happened before that could run
                // (e.g. a dropped connection). Best-effort - a failure here
                // is logged, not thrown.
                if (this.currentPhase === 'replacement' || this.currentPhase === 'migration') {
                    try {
                        await this.request('/replacement/maintenance', {
                            method: 'POST',
                            body: JSON.stringify({ enable: false }),
                        });
                        this.log('Restored public site access after the failure', 'info');
                    } catch (restoreError) {
                        this.log(`Could not confirm maintenance mode was cleared: ${restoreError.message}`, 'warning');
                    }
                }

                this.onError(error);
            }
        } finally {
            this.isRunning = false;
        }
    }

    /**
     * Initiate a new update
     */
    async initiateUpdate(version, isTenant) {
        // Phase 1: Initiate
        this.setPhase('initiating');
        this.log('Initializing update...');

        const initResult = await this.request('/initiate', {
            method: 'POST',
            body: JSON.stringify({ version }),
        });

        if (!initResult.success) {
            throw new Error(initResult.message || 'Failed to initiate update');
        }

        this.status = initResult.status;

        // Validate that we have valid chunk information
        if (!this.status.download || this.status.download.total_chunks <= 0) {
            throw new Error('Invalid update configuration: No chunks to download. The server may not have the update package ready. Please try again later or contact support.');
        }

        this.log(`Update initialized. ${initResult.status.download.total_chunks} chunks to download.`);

        // Verify this server can actually complete an update of this size
        // before spending any time/bandwidth on it.
        await this.runSystemCheck(initResult.status.download.total_size);

        // Continue with download phase
        await this.runDownloadPhase();
        await this.runMergePhase();
        await this.runExtractionPhase();
        await this.runReplacementPhase();
        await this.runMigrationPhase(isTenant);
        await this.runCompletionPhase();
    }

    /**
     * Resume from a specific phase
     */
    async resumeFromPhase(resumePoint, isTenant) {
        const phase = resumePoint.phase;

        switch (phase) {
            case 'initialized':
                await this.runDownloadPhase();
                await this.runMergePhase();
                await this.runExtractionPhase();
                await this.runReplacementPhase();
                await this.runMigrationPhase(isTenant);
                await this.runCompletionPhase();
                break;

            case 'download':
                await this.runDownloadPhase();
                await this.runMergePhase();
                await this.runExtractionPhase();
                await this.runReplacementPhase();
                await this.runMigrationPhase(isTenant);
                await this.runCompletionPhase();
                break;

            case 'merging':
                await this.runMergePhase();
                await this.runExtractionPhase();
                await this.runReplacementPhase();
                await this.runMigrationPhase(isTenant);
                await this.runCompletionPhase();
                break;

            case 'extraction':
                await this.runExtractionPhase();
                await this.runReplacementPhase();
                await this.runMigrationPhase(isTenant);
                await this.runCompletionPhase();
                break;

            case 'replacement':
                await this.runReplacementPhase();
                await this.runMigrationPhase(isTenant);
                await this.runCompletionPhase();
                break;

            case 'migration':
                await this.runMigrationPhase(isTenant);
                await this.runCompletionPhase();
                break;

            case 'error':
            case 'fatal_error':
                // Update is in error state, cancel it and restart
                this.log('Previous update failed. Cancelling corrupted update...', 'warning');
                await this.cancel();

                // Get the version from resume point before it was cancelled
                const targetVersion = resumePoint.target_version;
                if (targetVersion) {
                    this.log(`Restarting update to version ${targetVersion}...`, 'info');
                    await this.initiateUpdate(targetVersion, isTenant);
                } else {
                    throw new Error('Previous update failed and has been cancelled. Please start a new update manually.');
                }
                break;

            default:
                throw new Error(`Unknown phase to resume: ${phase}`);
        }
    }


    /**
     * Set current phase
     */
    setPhase(phase) {
        this.currentPhase = phase;
        this.onPhaseChange(phase);
    }

    /**
     * Verify this server environment (PHP version/extensions, memory,
     * execution time limit, disk space, permissions) can actually complete
     * an update of this size. Throws on a blocking failure; logs and
     * continues on non-blocking warnings.
     */
    async runSystemCheck(totalSize = 0) {
        this.log('Checking server readiness for this update...');

        const info = this.lastCheckInfo || {};

        let result;
        try {
            result = await this.request('/system-check', {
                method: 'POST',
                body: JSON.stringify({
                    total_size: totalSize,
                    php_version_required: info.php_version_required || null,
                    extensions_required: info.extensions_required || null,
                }),
            });
        } catch (error) {
            // Don't block the update if the check itself fails to run -
            // just warn and proceed, the update may still succeed.
            this.log(`Could not run the readiness check (${error.message}) - continuing anyway.`, 'warning');
            return;
        }

        const checks = result.checks || [];
        const failed = checks.filter((c) => c.status === 'fail');
        const warned = checks.filter((c) => c.status === 'warning');

        for (const check of warned) {
            this.log(`Warning: ${check.name} - ${check.message}${check.suggestion ? ` (${check.suggestion})` : ''}`, 'warning');
        }

        if (failed.length > 0) {
            const details = failed.map((c) => `${c.name}: ${c.message}${c.suggestion ? ` — ${c.suggestion}` : ''}`).join(' | ');
            throw new Error(`Server is not ready for this update. ${details}`);
        }

        this.log('Server readiness check passed.');
    }

    /**
     * Download phase - download all chunks
     */
    async runDownloadPhase() {
        this.setPhase('download');
        this.log('Starting download phase...');

        // Get missing chunks
        const missingResult = await this.request('/chunks/missing');
        let missingChunks = missingResult.missing_chunks || [];
        const totalChunks = missingResult.total_chunks;

        if (missingChunks.length === 0) {
            this.log('All chunks already downloaded');
            this.updateProgress('download', 100);
            return;
        }

        this.log(`Downloading ${missingChunks.length} of ${totalChunks} chunks...`);

        // Download chunks sequentially (more reliable)
        for (let i = 0; i < missingChunks.length; i++) {
            const chunkIndex = missingChunks[i];
            await this.downloadChunkWithRetry(chunkIndex);

            // Update progress
            const progress = await this.request('/chunks/progress');
            const percent = progress.percent || 0;
            this.updateProgress('download', percent, {
                downloaded: progress.downloaded_count,
                total: progress.total_chunks,
            });
        }

        this.log('All chunks downloaded');
    }

    /**
     * Download a single chunk with retry logic
     */
    async downloadChunkWithRetry(chunkIndex, attempt = 1) {
        try {
            this.log(`Downloading chunk ${chunkIndex}...`);
            const result = await this.request(`/chunks/download/${chunkIndex}`);

            if (result.success) {
                return result;
            }
            throw new Error(result.error || 'Chunk download failed');
        } catch (error) {
            if (attempt < this.retryAttempts) {
                this.log(`Chunk ${chunkIndex} failed, retrying (${attempt}/${this.retryAttempts})...`, 'warning');
                await this.sleep(this.retryDelay * attempt);
                return this.downloadChunkWithRetry(chunkIndex, attempt + 1);
            }
            throw new Error(`Failed to download chunk ${chunkIndex} after ${this.retryAttempts} attempts`);
        }
    }

    /**
     * Merge phase - combine chunks into ZIP
     */
    async runMergePhase() {
        this.setPhase('merging');
        this.log('Merging chunks into ZIP file...');
        this.updateProgress('merging', 0);

        const result = await this.request('/chunks/merge', { method: 'POST' });

        if (!result.success) {
            throw new Error(result.error || 'Failed to merge chunks');
        }

        this.log(`ZIP created: ${this.formatBytes(result.zip_size)} (${result.file_count} files)`);
        this.updateProgress('merging', 100);
    }

    /**
     * Extraction phase - extract files from ZIP in batches
     */
    async runExtractionPhase() {
        this.setPhase('extraction');
        this.log('Starting file extraction...');

        let batch = 0;
        let hasMore = true;

        while (hasMore) {
            const result = await this.request('/extraction/batch', {
                method: 'POST',
                body: JSON.stringify({ batch }),
            });

            if (!result.success) {
                throw new Error(result.error || 'Extraction failed');
            }

            this.updateProgress('extraction', result.percent, {
                extracted: result.extracted_total,
                total: result.total_files,
            });

            hasMore = result.has_more;
            batch = result.next_batch;

            // Log progress every 10 batches
            if (batch % 10 === 0) {
                this.log(`Extracted ${result.extracted_total} of ${result.total_files} files`);
            }
        }

        this.log('Extraction completed');
    }

    /**
     * Replacement phase - replace files in batches
     */
    async runReplacementPhase() {
        this.setPhase('replacement');
        this.log('Starting file replacement...');
        this.log('Enabling maintenance mode...');

        // Get and display composer analysis on first batch
        let composerAnalyzed = false;

        let batch = 0;
        let hasMore = true;

        while (hasMore) {
            const result = await this.request('/replacement/batch', {
                method: 'POST',
                body: JSON.stringify({ batch }),
            });

            if (!result.success) {
                throw new Error(result.error || 'Replacement failed');
            }

            // On first batch, get composer analysis
            if (batch === 0 && !composerAnalyzed) {
                const analysis = await this.getComposerAnalysis();
                if (analysis && analysis.has_changes) {
                    this.log(`Composer: ${analysis.statistics.changed} package(s) updated, ${analysis.statistics.added} added, ${analysis.statistics.removed} removed`, 'info');
                    this.onComposerAnalysis(analysis);
                } else if (analysis) {
                    this.log('No Composer dependency changes detected', 'info');
                }
                composerAnalyzed = true;
            }

            // The server just enabled maintenance mode for this batch - visit
            // the bypass URL now so this tab's own next request isn't blocked
            // by the maintenance mode it just turned on.
            if (result.maintenance_bypass_url) {
                try {
                    await fetch(result.maintenance_bypass_url, { credentials: 'same-origin' });
                } catch (error) {
                    this.log(`Could not pre-authorize maintenance bypass: ${error.message}`, 'warning');
                }
            }

            this.updateProgress('replacement', result.percent, {
                replaced: result.replaced_total,
                skipped: result.skipped_total,
                total: result.total_files,
            });

            hasMore = result.has_more;
            batch = result.next_batch;

            // Log progress every 5 batches
            if (batch % 5 === 0) {
                this.log(`Replaced ${result.replaced_total} files, skipped ${result.skipped_total}`);
            }
        }

        this.log('File replacement completed');
    }

    /**
     * Migration phase - run database migrations
     */
    async runMigrationPhase(isTenant) {
        this.setPhase('migration');
        this.log('Running database migrations...');
        this.updateProgress('migration', 0);

        const result = await this.request('/migration/run', {
            method: 'POST',
            body: JSON.stringify({ is_tenant: isTenant }),
        });

        if (!result.success) {
            throw new Error(result.message || 'Migration failed');
        }

        this.log('Database migrations completed');
        this.updateProgress('migration', 100);
    }

    /**
     * Completion phase - finalize update
     */
    async runCompletionPhase() {
        this.setPhase('completing');
        this.log('Finalizing update...');

        const result = await this.request('/migration/complete', { method: 'POST' });

        if (!result.success) {
            throw new Error(result.message || 'Failed to complete update');
        }

        this.log(`Update completed! Version: ${result.version}`);
        this.updateProgress('completed', 100);
        this.setPhase('completed');
        this.onComplete(result);
    }

    /**
     * Cancel the update
     */
    async cancel() {
        if (this.abortController) {
            this.abortController.abort();
        }

        try {
            await this.request('/cancel', { method: 'POST' });
            this.log('Update cancelled and cleaned up');
        } catch (error) {
            this.log('Failed to cancel update: ' + error.message, 'error');
        }

        this.isRunning = false;
    }

    /**
     * Helper: Sleep for specified milliseconds
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * Helper: Format bytes to human readable
     */
    formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return `${bytes.toFixed(2)} ${units[i]}`;
    }
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = UpdateManager;
}

// Also make available globally
if (typeof window !== 'undefined') {
    window.UpdateManager = UpdateManager;
}
