<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('System Update - V2') }}</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --primary-color: #4a6cf7;
            --success-color: #22c55e;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-color: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }

        .update-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .update-card {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 30px;
            margin-bottom: 20px;
        }

        .update-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .update-header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .update-header p {
            color: var(--text-muted);
        }

        /* Phase Progress */
        .phase-progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }

        .phase-progress::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--border-color);
            z-index: 0;
        }

        .phase-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            flex: 1;
        }

        .phase-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--card-bg);
            border: 3px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            transition: all 0.3s ease;
        }

        .phase-item.active .phase-icon {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
        }

        .phase-item.completed .phase-icon {
            border-color: var(--success-color);
            background: var(--success-color);
            color: white;
        }

        .phase-label {
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
        }

        .phase-item.active .phase-label,
        .phase-item.completed .phase-label {
            color: var(--text-color);
            font-weight: 500;
        }

        /* Progress Bar */
        .progress-section {
            margin-bottom: 30px;
        }

        .progress-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .progress-bar-container {
            height: 12px;
            background: var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), #6366f1);
            border-radius: 6px;
            transition: width 0.3s ease;
            position: relative;
        }

        .progress-bar-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Log Console */
        .log-console {
            background: #1e293b;
            border-radius: 8px;
            padding: 15px;
            max-height: 250px;
            overflow-y: auto;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .log-entry {
            margin-bottom: 4px;
            line-height: 1.5;
        }

        .log-entry .timestamp {
            color: #64748b;
        }

        .log-entry.info { color: #94a3b8; }
        .log-entry.success { color: #22c55e; }
        .log-entry.warning { color: #f59e0b; }
        .log-entry.error { color: #ef4444; }

        /* Buttons */
        .btn-update {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 108, 247, 0.4);
            color: white;
        }

        .btn-update:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .btn-cancel {
            background: transparent;
            border: 2px solid var(--danger-color);
            color: var(--danger-color);
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: var(--danger-color);
            color: white;
        }

        /* Update Info */
        .update-info {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .update-info h4 {
            color: #166534;
            margin-bottom: 10px;
        }

        .update-info p {
            margin: 0;
            color: #15803d;
        }

        /* Version Badge */
        .version-badge {
            display: inline-block;
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        /* Status Cards */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .status-item {
            background: var(--bg-color);
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }

        .status-item .value {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary-color);
        }

        .status-item .label {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        /* Resume Alert */
        .resume-alert {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .resume-alert .icon {
            font-size: 24px;
        }

        /* Hidden initially */
        .update-progress-section,
        .update-complete-section {
            display: none;
        }
    </style>
</head>
<body>
    <div class="update-container">
        <div class="update-card">
            <div class="update-header">
                <h1>System Update</h1>
                <p>Chunked Update System V2 - Resume capable</p>
            </div>

            @if($canResume && $existingStatus)
            <div class="resume-alert">
                <span class="icon">&#9888;</span>
                <div>
                    <strong>Update In Progress</strong>
                    <p class="mb-0">A previous update was interrupted at phase: {{ $existingStatus['phase'] ?? 'unknown' }}</p>
                </div>
            </div>
            @endif

            <!-- Phase Progress Indicator -->
            <div class="phase-progress">
                <div class="phase-item" data-phase="check">
                    <div class="phase-icon">1</div>
                    <span class="phase-label">Check</span>
                </div>
                <div class="phase-item" data-phase="download">
                    <div class="phase-icon">2</div>
                    <span class="phase-label">Download</span>
                </div>
                <div class="phase-item" data-phase="extraction">
                    <div class="phase-icon">3</div>
                    <span class="phase-label">Extract</span>
                </div>
                <div class="phase-item" data-phase="replacement">
                    <div class="phase-icon">4</div>
                    <span class="phase-label">Replace</span>
                </div>
                <div class="phase-item" data-phase="migration">
                    <div class="phase-icon">5</div>
                    <span class="phase-label">Migrate</span>
                </div>
                <div class="phase-item" data-phase="completed">
                    <div class="phase-icon">&#10003;</div>
                    <span class="phase-label">Done</span>
                </div>
            </div>

            <!-- Check Update Section -->
            <div id="checkUpdateSection">
                <div class="text-center mb-4">
                    <p>Current Version: <span class="version-badge">{{ $currentVersion ?? 'Unknown' }}</span></p>
                </div>

                <div id="updateAvailable" class="update-info" style="display: none;">
                    <h4>Update Available!</h4>
                    <p>Version <strong id="newVersion"></strong> is ready to install.</p>
                    <p class="mt-2" id="changelog"></p>
                </div>

                <div id="noUpdate" style="display: none;" class="text-center text-success">
                    <p>Your system is up to date!</p>
                </div>

                <div class="action-buttons">
                    <button id="btnCheckUpdate" class="btn btn-update" onclick="checkForUpdate()">
                        Check for Updates
                    </button>

                    @if($canResume)
                    <button id="btnResume" class="btn btn-update" onclick="resumeUpdate()">
                        Resume Update
                    </button>
                    @endif

                    <button id="btnStartUpdate" class="btn btn-update" style="display: none;" onclick="startUpdate()">
                        Start Update
                    </button>
                </div>
            </div>

            <!-- Update Progress Section -->
            <div id="updateProgressSection" class="update-progress-section">
                <div class="status-grid">
                    <div class="status-item">
                        <div class="value" id="progressPercent">0%</div>
                        <div class="label">Progress</div>
                    </div>
                    <div class="status-item">
                        <div class="value" id="currentPhaseDisplay">-</div>
                        <div class="label">Current Phase</div>
                    </div>
                    <div class="status-item">
                        <div class="value" id="filesProcessed">0</div>
                        <div class="label">Files Processed</div>
                    </div>
                </div>

                <div class="progress-section">
                    <div class="progress-info">
                        <span id="progressLabel">Initializing...</span>
                        <span id="progressDetail"></span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" id="progressBar" style="width: 0%"></div>
                    </div>
                </div>

                <div class="log-console" id="logConsole">
                    <div class="log-entry info">
                        <span class="timestamp">[--:--:--]</span> Ready to start update...
                    </div>
                </div>

                <div class="action-buttons">
                    <button id="btnPause" class="btn btn-cancel" onclick="pauseUpdate()" style="display: none;">
                        Pause
                    </button>
                    <button id="btnCancelUpdate" class="btn btn-cancel" onclick="cancelUpdate()">
                        Cancel Update
                    </button>
                </div>
            </div>

            <!-- Update Complete Section -->
            <div id="updateCompleteSection" class="update-complete-section">
                <div class="text-center">
                    <div style="font-size: 60px; color: var(--success-color);">&#10003;</div>
                    <h3>Update Complete!</h3>
                    <p>Your system has been successfully updated to version <strong id="finalVersion"></strong></p>
                    <a href="{{ url('/') }}" class="btn btn-update mt-3">Go to Dashboard</a>
                </div>
            </div>
        </div>

        <div class="text-center text-muted">
            <small>XgApiClient V2 Chunked Update System</small>
        </div>
    </div>

    <script src="{{ asset('vendor/xgapiclient/js/UpdateManager.js') }}"></script>
    <script>
        // Initialize Update Manager
        const updateManager = new UpdateManager({
            baseUrl: '/update/v2',
            csrfToken: document.querySelector('meta[name="csrf-token"]').content,
            onProgress: handleProgress,
            onLog: handleLog,
            onPhaseChange: handlePhaseChange,
            onError: handleError,
            onComplete: handleComplete,
        });

        let updateInfo = null;
        const isTenant = {{ $existingStatus['is_tenant'] ?? 'false' }};

        // Check for updates
        async function checkForUpdate() {
            const btn = document.getElementById('btnCheckUpdate');
            btn.disabled = true;
            btn.textContent = 'Checking...';

            try {
                updateInfo = await updateManager.checkForUpdate();

                if (updateInfo && updateInfo.update_available) {
                    document.getElementById('updateAvailable').style.display = 'block';
                    document.getElementById('noUpdate').style.display = 'none';
                    document.getElementById('newVersion').textContent = updateInfo.latest_version;
                    document.getElementById('changelog').textContent = updateInfo.changelog || '';
                    document.getElementById('btnStartUpdate').style.display = 'inline-block';
                    document.getElementById('btnCheckUpdate').style.display = 'none';
                } else {
                    document.getElementById('noUpdate').style.display = 'block';
                    document.getElementById('updateAvailable').style.display = 'none';
                }
            } catch (error) {
                alert('Failed to check for updates: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.textContent = 'Check for Updates';
            }
        }

        // Start update
        async function startUpdate() {
            if (!updateInfo || !updateInfo.latest_version) {
                alert('No update information available');
                return;
            }

            showProgressSection();
            await updateManager.startUpdate(updateInfo.latest_version, isTenant);
        }

        // Resume update
        async function resumeUpdate() {
            showProgressSection();
            await updateManager.startUpdate(null, isTenant);
        }

        // Pause update
        function pauseUpdate() {
            updateManager.pause();
        }

        // Cancel update
        async function cancelUpdate() {
            if (confirm('Are you sure you want to cancel the update? Progress will be lost.')) {
                await updateManager.cancel();
                location.reload();
            }
        }

        // Show progress section
        function showProgressSection() {
            document.getElementById('checkUpdateSection').style.display = 'none';
            document.getElementById('updateProgressSection').style.display = 'block';
        }

        // Handle progress updates
        function handleProgress(data) {
            document.getElementById('progressBar').style.width = data.percent + '%';
            document.getElementById('progressPercent').textContent = data.percent + '%';

            if (data.phase) {
                document.getElementById('progressLabel').textContent = getPhaseLabel(data.phase);
            }

            if (data.downloaded !== undefined) {
                document.getElementById('filesProcessed').textContent = data.downloaded;
                document.getElementById('progressDetail').textContent = `${data.downloaded}/${data.total} chunks`;
            } else if (data.extracted !== undefined) {
                document.getElementById('filesProcessed').textContent = data.extracted;
                document.getElementById('progressDetail').textContent = `${data.extracted}/${data.total} files`;
            } else if (data.replaced !== undefined) {
                document.getElementById('filesProcessed').textContent = data.replaced;
                document.getElementById('progressDetail').textContent = `${data.replaced} replaced, ${data.skipped} skipped`;
            }
        }

        // Handle log messages
        function handleLog(data) {
            const console = document.getElementById('logConsole');
            const entry = document.createElement('div');
            entry.className = `log-entry ${data.type}`;
            entry.innerHTML = `<span class="timestamp">[${data.timestamp}]</span> ${data.message}`;
            console.appendChild(entry);
            console.scrollTop = console.scrollHeight;
        }

        // Handle phase changes
        function handlePhaseChange(phase) {
            document.getElementById('currentPhaseDisplay').textContent = getPhaseLabel(phase);

            // Update phase indicators
            const phases = ['check', 'download', 'extraction', 'replacement', 'migration', 'completed'];
            const currentIndex = phases.indexOf(phase);

            document.querySelectorAll('.phase-item').forEach((item, index) => {
                const itemPhase = item.dataset.phase;
                const itemIndex = phases.indexOf(itemPhase);

                item.classList.remove('active', 'completed');

                if (itemIndex < currentIndex) {
                    item.classList.add('completed');
                } else if (itemIndex === currentIndex) {
                    item.classList.add('active');
                }
            });
        }

        // Handle errors
        function handleError(error) {
            alert('Update error: ' + error.message);
        }

        // Handle completion
        function handleComplete(result) {
            document.getElementById('updateProgressSection').style.display = 'none';
            document.getElementById('updateCompleteSection').style.display = 'block';
            document.getElementById('finalVersion').textContent = result.version || 'Latest';
        }

        // Get human-readable phase label
        function getPhaseLabel(phase) {
            const labels = {
                'initiating': 'Initializing',
                'download': 'Downloading',
                'merging': 'Merging',
                'extraction': 'Extracting',
                'replacement': 'Replacing',
                'migration': 'Migrating',
                'completing': 'Completing',
                'completed': 'Completed'
            };
            return labels[phase] || phase;
        }

        // Auto-check on load if no resume available
        @if(!$canResume)
        document.addEventListener('DOMContentLoaded', checkForUpdate);
        @endif
    </script>
</body>
</html>
