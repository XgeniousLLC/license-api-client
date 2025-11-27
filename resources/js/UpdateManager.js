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
        this.onProgress = options.onProgress || (() => {});
        this.onLog = options.onLog || (() => {});
        this.onPhaseChange = options.onPhaseChange || (() => {});
        this.onError = options.onError || (() => {});
        this.onComplete = options.onComplete || (() => {});

        // State
        this.isRunning = false;
        this.isPaused = false;
        this.currentPhase = null;
        this.status = null;
        this.abortController = null;

        // Configuration
        this.retryAttempts = options.retryAttempts || 3;
        this.retryDelay = options.retryDelay || 2000;
        this.chunkConcurrency = options.chunkConcurrency || 1; // Download one at a time for reliability
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
     * Get current update status
     */
    async getStatus() {
        try {
            const result = await this.request('/status');
            this.status = result.status;
            return result;
        } catch (error) {
            this.log(`Failed to get status: ${error.message}`, 'error');
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
     * Start or resume update process
     */
    async startUpdate(version, isTenant = false) {
        if (this.isRunning) {
            this.log('Update already in progress', 'warning');
            return;
        }

        this.isRunning = true;
        this.isPaused = false;
        this.abortController = new AbortController();

        try {
            // Check if we can resume an existing update
            const resumePoint = await this.canResume();

            if (resumePoint) {
                this.log(`Resuming update from phase: ${resumePoint.phase}`);
                await this.resumeFromPhase(resumePoint, isTenant);
            } else {
                // Start fresh update
                this.log(`Starting update to version ${version}`);
                await this.initiateUpdate(version, isTenant);
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                this.log('Update cancelled', 'warning');
            } else {
                this.log(`Update failed: ${error.message}`, 'error');
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
        this.log(`Update initialized. ${initResult.status.download.total_chunks} chunks to download.`);

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
            if (this.isPaused) {
                this.log('Download paused', 'warning');
                throw new Error('Update paused');
            }

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
            if (this.isPaused) {
                throw new Error('Update paused');
            }

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

        let batch = 0;
        let hasMore = true;

        while (hasMore) {
            if (this.isPaused) {
                throw new Error('Update paused');
            }

            const result = await this.request('/replacement/batch', {
                method: 'POST',
                body: JSON.stringify({ batch }),
            });

            if (!result.success) {
                throw new Error(result.error || 'Replacement failed');
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
     * Pause the update
     */
    pause() {
        this.isPaused = true;
        this.log('Update paused', 'warning');
    }

    /**
     * Cancel the update
     */
    async cancel() {
        this.isPaused = true;

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
     * Get update logs
     */
    async getLogs() {
        const result = await this.request('/logs');
        return result.logs || [];
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
