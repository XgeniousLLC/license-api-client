<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('System Update') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --ground: #F5F2EC;
            --surface: #FFFFFF;
            --surface-2: #ECE6D9;
            --border: #DDD3BF;
            --text: #2A241B;
            --text-2: #6B6252;
            --text-3: #9A927E;
            --accent: #BD640C;
            --accent-2: #9C5209;
            --accent-soft: #F6E3CB;
            --success: #2F8F5B;
            --success-soft: #E3F3E9;
            --warning: #9A7D00;
            --warning-soft: #F6EFCE;
            --danger: #B93A28;
            --danger-soft: #F8E4DE;
            --console-bg: #1C1712;
            --console-text: #E4D9C4;
            --console-border: #2B241B;
            --shadow: 0 1px 2px rgba(40,30,10,0.04), 0 10px 28px rgba(40,30,10,0.07);
            --radius: 14px;
            --font-ui: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            --font-mono: "IBM Plex Mono", ui-monospace, "SF Mono", Menlo, monospace;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --ground: #17130E;
                --surface: #1F1A13;
                --surface-2: #261F17;
                --border: #372C1E;
                --text: #F1E9DB;
                --text-2: #B7AA92;
                --text-3: #83765D;
                --accent: #E28A3C;
                --accent-2: #F0A05C;
                --accent-soft: #3B2A15;
                --success: #4FCB86;
                --success-soft: #16301F;
                --warning: #D9B94A;
                --warning-soft: #332B10;
                --danger: #E8776A;
                --danger-soft: #3A1C16;
                --console-bg: #100D08;
                --console-text: #E4DAC0;
                --console-border: #241E15;
                --shadow: 0 1px 2px rgba(0,0,0,0.3), 0 10px 28px rgba(0,0,0,0.45);
            }
        }

        * { box-sizing: border-box; }

        body {
            background: var(--ground);
            color: var(--text);
            font-family: var(--font-ui);
            margin: 0;
            line-height: 1.55;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            min-height: 100vh;
            padding: 48px 20px;
            -webkit-font-smoothing: antialiased;
        }

        .update-container {
            width: 100%;
            max-width: 640px;
        }

        .update-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: var(--shadow);
            padding: 38px 40px;
        }

        .app-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .app-sub {
            font-size: 14px;
            color: var(--text-2);
            margin: 0 0 26px;
        }

        .version-badge {
            font-family: var(--font-mono);
            font-weight: 500;
        }

        /* Six-step tracker - JS toggles .active / .completed on .phase-item */
        .stepper {
            display: flex;
            align-items: center;
            margin-bottom: 28px;
        }

        .phase-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 7px;
            flex: 1;
            position: relative;
        }

        .phase-icon {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--surface-2);
            border: 2px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-ui);
            font-weight: 700;
            font-size: 12px;
            color: var(--text-3);
            z-index: 1;
            transition: background .2s ease, border-color .2s ease, color .2s ease;
        }

        .phase-label {
            font-size: 12px;
            color: var(--text-3);
            font-weight: 500;
            text-align: center;
        }

        .phase-item::before {
            content: '';
            position: absolute;
            top: 13px;
            left: -50%;
            width: 100%;
            height: 2px;
            background: var(--border);
            z-index: 0;
        }

        .phase-item:first-child::before { display: none; }

        .phase-item.completed .phase-icon {
            background: var(--success);
            border-color: var(--success);
            color: #fff;
        }

        .phase-item.completed::before { background: var(--success); }
        .phase-item.completed .phase-label { color: var(--success); }

        .phase-item.active .phase-icon {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        .phase-item.active .phase-label {
            color: var(--accent-2);
            font-weight: 600;
        }

        .phase-item.errored .phase-icon {
            background: var(--danger);
            border-color: var(--danger);
            color: #fff;
        }

        .phase-item.errored .phase-label {
            color: var(--danger);
            font-weight: 600;
        }

        /* Idle / available */
        .lede {
            color: var(--text-2);
            font-size: 14.5px;
            margin: 0 0 26px;
        }

        .whats-new {
            margin: 0 0 20px;
            padding: 0;
            list-style: none;
        }

        .changelog-box {
            background: var(--surface-2);
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 13.5px;
            color: var(--text-2);
            white-space: pre-wrap;
            max-height: 180px;
            overflow-y: auto;
            margin: 0 0 18px;
        }

        /* Reassurance strip */
        .reassure {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            font-size: 13.5px;
            color: var(--text-2);
            background: var(--surface-2);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 22px;
        }

        .reassure svg { flex: none; margin-top: 2px; }
        .reassure b { color: var(--text); }

        /* Banners: interrupted / error */
        .banner {
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 20px;
        }

        .banner.warn { background: var(--warning-soft); }
        .banner.err { background: var(--danger-soft); }

        .banner-title {
            font-size: 15.5px;
            font-weight: 700;
            margin: 0 0 5px;
        }

        .banner.warn .banner-title { color: var(--warning); }
        .banner.err .banner-title { color: var(--danger); }

        .banner-body {
            color: var(--text-2);
            font-size: 14px;
            margin: 0;
        }

        .banner-body b { color: var(--text); }
        .banner-body a { color: inherit; text-decoration: underline; }

        /* Big percentage + status line */
        .big-pct {
            font-family: var(--font-ui);
            font-weight: 700;
            font-size: 46px;
            letter-spacing: -0.02em;
            font-variant-numeric: tabular-nums;
            line-height: 1;
        }

        .status-line {
            font-size: 16.5px;
            color: var(--text);
            font-weight: 500;
            margin: 6px 0 18px;
        }

        .progress-bar-container {
            height: 10px;
            border-radius: 100px;
            background: var(--surface-2);
            overflow: hidden;
            margin-bottom: 14px;
        }

        .progress-bar-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 100px;
            width: 0%;
            transition: width .3s ease;
        }

        /* Technical details disclosure - closed by default */
        details.tech {
            margin-bottom: 22px;
        }

        details.tech > summary {
            list-style: none;
            cursor: pointer;
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--text-3);
            display: flex;
            align-items: center;
            gap: 6px;
            user-select: none;
            padding: 4px 0;
        }

        details.tech > summary::-webkit-details-marker { display: none; }
        details.tech > summary::before { content: "\25B8"; display: inline-block; transition: transform .15s; }
        details.tech[open] > summary::before { transform: rotate(90deg); }
        details.tech > summary:hover { color: var(--text-2); }

        .tech-body { margin-top: 12px; }

        .stat-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }

        .stat {
            background: var(--surface-2);
            border-radius: 8px;
            padding: 11px 13px;
        }

        .stat-label {
            font-size: 10.5px;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: .05em;
            font-family: var(--font-mono);
            margin-bottom: 5px;
        }

        .stat-val {
            font-family: var(--font-mono);
            font-size: 14px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        /* Composer analysis - lives inside technical details */
        .composer-analysis {
            display: none;
            margin-bottom: 14px;
        }

        .composer-analysis.visible { display: block; }

        .composer-analysis h5 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            margin: 0 0 10px;
            font-family: var(--font-mono);
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .composer-stats {
            display: flex;
            gap: 14px;
            margin-bottom: 10px;
            font-size: 12.5px;
            color: var(--text-2);
        }

        .composer-stats b { color: var(--text); }

        .composer-details {
            font-size: 12px;
            color: var(--text-2);
            padding: 10px 12px;
            background: var(--surface-2);
            border-radius: 6px;
            max-height: 140px;
            overflow-y: auto;
        }

        .composer-details ul { margin: 0; padding-left: 18px; }
        .composer-details li { margin: 2px 0; }

        /* Log console */
        .log-console {
            background: var(--console-bg);
            border: 1px solid var(--console-border);
            border-radius: 8px;
            padding: 14px 16px;
            font-family: var(--font-mono);
            font-size: 11.5px;
            line-height: 1.75;
            height: 130px;
            overflow-y: auto;
        }

        .log-entry .timestamp { color: #6b6152; margin-right: 8px; }
        .log-entry.info { color: var(--console-text); }
        .log-entry.success { color: #7BD9A0; }
        .log-entry.warning { color: #E8C46A; }
        .log-entry.error { color: #F0897A; }

        /* Actions */
        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .actions.center { justify-content: center; }
        .actions.end { justify-content: flex-end; }

        .btn {
            font-family: var(--font-ui);
            font-size: 14.5px;
            font-weight: 600;
            padding: 12px 22px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            cursor: pointer;
        }

        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            padding: 12px 26px;
        }

        .btn-primary:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .btn-link {
            background: none;
            border: none;
            color: var(--text-3);
            font-family: var(--font-ui);
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            padding: 8px 0;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .btn-link.danger { color: var(--danger); }
        .btn:focus-visible, .btn-link:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        a.btn-primary { display: inline-block; text-decoration: none; }

        /* Complete state */
        .complete-hero { text-align: center; padding: 6px 0; }

        .complete-check {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--success-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
        }

        .complete-title {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 6px;
        }

        .complete-sub {
            color: var(--text-2);
            font-size: 14.5px;
            margin: 0 0 24px;
        }

        .footer-note {
            text-align: center;
            color: var(--text-3);
            font-size: 12px;
            margin-top: 18px;
        }

        [hidden] { display: none !important; }

        @media (max-width: 520px) {
            .update-card { padding: 26px; }
            .stat-row { grid-template-columns: 1fr; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }
    </style>
</head>
<body>
    <div class="update-container">
        <div class="update-card">
            <p class="app-title">System Update</p>
            <p class="app-sub" id="versionSubtitle">You're on version <span class="version-badge">{{ $currentVersion ?? 'Unknown' }}</span></p>

            <!-- Six-step tracker -->
            <div class="stepper">
                <div class="phase-item" data-phase="check" data-num="1">
                    <div class="phase-icon">1</div>
                    <span class="phase-label">Check</span>
                </div>
                <div class="phase-item" data-phase="download" data-num="2">
                    <div class="phase-icon">2</div>
                    <span class="phase-label">Download</span>
                </div>
                <div class="phase-item" data-phase="extraction" data-num="3">
                    <div class="phase-icon">3</div>
                    <span class="phase-label">Extract</span>
                </div>
                <div class="phase-item" data-phase="replacement" data-num="4">
                    <div class="phase-icon">4</div>
                    <span class="phase-label">Replace</span>
                </div>
                <div class="phase-item" data-phase="migration" data-num="5">
                    <div class="phase-icon">5</div>
                    <span class="phase-label">Migrate</span>
                </div>
                <div class="phase-item" data-phase="completed" data-num="6">
                    <div class="phase-icon">6</div>
                    <span class="phase-label">Done</span>
                </div>
            </div>

            @if($canResume && $existingStatus)
                @php
                    $resumePhaseLabels = [
                        'initialized' => 'Download',
                        'download' => 'Download',
                        'merging' => 'Extract',
                        'extraction' => 'Extract',
                        'replacement' => 'Replace',
                        'migration' => 'Migrate',
                    ];
                    $resumePhase = $existingStatus['phase'] ?? 'unknown';
                    $resumePhaseLabel = $resumePhaseLabels[$resumePhase] ?? $resumePhase;
                @endphp
                <!-- Interrupted state -->
                <div id="interruptedBanner" class="banner warn">
                    <p class="banner-title">This got interrupted</p>
                    <p class="banner-body">No damage was done — we can pick up right where we left off, no need to start over.</p>
                </div>
                <details class="tech">
                    <summary>Show technical details</summary>
                    <div class="tech-body">
                        <p style="font-size:13px;color:var(--text-2);margin:0;">Paused during: <b style="color:var(--text)">{{ $resumePhaseLabel }}</b></p>
                    </div>
                </details>
                <div class="actions">
                    <button id="btnStartOver" class="btn-link danger" onclick="startOverInstead()">Start over instead</button>
                    <button id="btnResume" class="btn btn-primary" onclick="resumeUpdate()">Continue Update</button>
                </div>
            @else
                <!-- Idle / Available -->
                <div id="checkUpdateSection">
                    <p class="lede" id="idleLede">Check to see if a newer version is available. This only looks — nothing changes yet.</p>

                    <div id="updateAvailable" hidden>
                        <ul class="whats-new"></ul>
                        <pre class="changelog-box" id="changelog"></pre>
                        <div class="reassure">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="var(--text-3)" stroke-width="1.6"/><path d="M12 8v5M12 16h.01" stroke="var(--text-3)" stroke-width="1.6" stroke-linecap="round"/></svg>
                            <span><b>Your site shows a brief maintenance message during the Replace step</b>, while files are being installed. If anything goes wrong, it comes back online automatically — it's never left down.</span>
                        </div>
                    </div>

                    <div id="noUpdate" hidden>
                        <p class="lede" style="color:var(--success);">You're up to date — no new version right now.</p>
                    </div>

                    <div class="actions end">
                        <button id="btnDismissAvailable" class="btn-link" onclick="dismissUpdateAvailable()" hidden>Not now</button>
                        <button id="btnCheckUpdate" class="btn btn-primary" onclick="checkForUpdate()">Check for Updates</button>
                        <button id="btnStartUpdate" class="btn btn-primary" onclick="startUpdate()" hidden>Update Now</button>
                    </div>
                </div>
            @endif

            <!-- Update Progress Section -->
            <div id="updateProgressSection" hidden>
                <div class="big-pct" id="progressPercent">0%</div>
                <p class="status-line" id="statusLine">Getting ready...</p>
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" id="progressBar"></div>
                </div>
                <div class="reassure" id="progressReassure">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="var(--text-3)" stroke-width="1.6"/><path d="M12 8v5M12 16h.01" stroke="var(--text-3)" stroke-width="1.6" stroke-linecap="round"/></svg>
                    <span id="progressReassureText">You can close this tab. If anything interrupts it, we'll pick up right where we left off.</span>
                </div>

                <details class="tech" id="techDetails">
                    <summary>Show technical details</summary>
                    <div class="tech-body">
                        <div id="composerAnalysis" class="composer-analysis">
                            <h5>Composer dependency changes</h5>
                            <div class="composer-stats">
                                <span>Updated <b id="composerChanged">0</b></span>
                                <span>Added <b id="composerAdded">0</b></span>
                                <span>Removed <b id="composerRemoved">0</b></span>
                            </div>
                            <div id="composerDetails" class="composer-details"></div>
                        </div>

                        <div class="stat-row">
                            <div class="stat">
                                <div class="stat-label">Phase</div>
                                <div class="stat-val" id="currentPhaseDisplay">-</div>
                            </div>
                            <div class="stat">
                                <div class="stat-label">Detail</div>
                                <div class="stat-val" id="progressDetail">-</div>
                            </div>
                        </div>

                        <div class="log-console" id="logConsole">
                            <div class="log-entry info"><span class="timestamp">[--:--:--]</span> Ready to start update...</div>
                        </div>
                    </div>
                </details>

                <div class="actions center">
                    <button id="btnCancelUpdate" class="btn-link" onclick="cancelUpdate()">Cancel update</button>
                </div>
            </div>

            <!-- Error Section -->
            <div id="updateErrorSection" hidden>
                <div class="banner err">
                    <p class="banner-title">We couldn't continue</p>
                    <p class="banner-body" id="errorMessage"></p>
                </div>
                <details class="tech">
                    <summary>Show technical details</summary>
                    <div class="tech-body">
                        <p style="font-size:12.5px;color:var(--text-2);margin:0 0 10px;">
                            You can also update manually: download the update file from
                            <a href="https://xgenious.com/my-account/downloads" target="_blank" rel="noopener noreferrer">My Account &rarr; Downloads</a>
                            (click "Generate Update File URL"), then follow the
                            <a href="https://docs.xgenious.com/docs/common-documentation/how-to-download-manual-update-file/" target="_blank" rel="noopener noreferrer">manual update guide</a>,
                            or open a <a href="https://xgenious.com/my-account/support" target="_blank" rel="noopener noreferrer">support ticket</a>.
                        </p>
                        <div class="log-console" id="errorLogConsole"></div>
                    </div>
                </details>
                <div class="actions end">
                    <button id="btnTryAgain" class="btn btn-primary" onclick="tryAgain()">Try Again</button>
                </div>
            </div>

            <!-- Update Complete Section -->
            <div id="updateCompleteSection" hidden>
                <div class="complete-hero">
                    <div class="complete-check">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 12.5L9.5 18L20 6" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <p class="complete-title">You're all set</p>
                    <p class="complete-sub">Updated to version <strong id="finalVersion"></strong></p>
                    <a href="{{ url('/') }}" class="btn btn-primary">Go to Dashboard</a>
                </div>
            </div>
        </div>

        <p class="footer-note">XgApiClient V2 Chunked Update System</p>
    </div>

    <script src="{{ asset('assets/vendor/xgapiclient/js/UpdateManager.js') }}"></script>
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
            onComposerAnalysis: handleComposerAnalysis,
        });

        let updateInfo = null;
        const isTenant = @json($existingStatus['is_tenant'] ?? false);

        // Plain-language sentence shown under the big percentage - the
        // stepper keeps the system's real phase names, this translates
        // whichever one is active into what a person would say out loud.
        const STATUS_SENTENCES = {
            initiating: 'Getting ready...',
            download: 'Downloading your update...',
            merging: 'Preparing your update...',
            extraction: 'Unpacking your update...',
            replacement: 'Installing your update — almost there.',
            migration: 'Finishing up...',
            completing: 'Wrapping up...',
        };

        // Handle composer analysis display
        function handleComposerAnalysis(analysis) {
            const card = document.getElementById('composerAnalysis');

            if (!analysis || analysis.status === 'not_analyzed' || !analysis.has_changes) {
                card.classList.remove('visible');
                return;
            }

            card.classList.add('visible');
            // Dependency changes are worth surfacing even though technical
            // details are closed by default.
            document.getElementById('techDetails').open = true;

            document.getElementById('composerChanged').textContent = analysis.statistics.changed;
            document.getElementById('composerAdded').textContent = analysis.statistics.added;
            document.getElementById('composerRemoved').textContent = analysis.statistics.removed;

            const details = document.getElementById('composerDetails');
            let html = '';

            if (Object.keys(analysis.details.changed_packages).length > 0) {
                html += '<strong>Updated:</strong><ul>';
                for (const [pkg, versions] of Object.entries(analysis.details.changed_packages)) {
                    html += `<li>${pkg}: ${versions.old} → ${versions.new}</li>`;
                }
                html += '</ul>';
            }

            if (Object.keys(analysis.details.added_packages).length > 0) {
                html += '<strong>Added:</strong><ul>';
                for (const [pkg, version] of Object.entries(analysis.details.added_packages)) {
                    html += `<li>${pkg}: ${version}</li>`;
                }
                html += '</ul>';
            }

            if (Object.keys(analysis.details.removed_packages).length > 0) {
                html += '<strong>Removed:</strong><ul>';
                for (const [pkg, version] of Object.entries(analysis.details.removed_packages)) {
                    html += `<li>${pkg}: ${version}</li>`;
                }
                html += '</ul>';
            }

            details.innerHTML = html;
        }

        // Check for updates
        async function checkForUpdate() {
            const btn = document.getElementById('btnCheckUpdate');
            btn.disabled = true;
            btn.textContent = 'Checking...';

            try {
                updateInfo = await updateManager.checkForUpdate();

                if (updateInfo && updateInfo.update_available) {
                    document.getElementById('idleLede').hidden = true;
                    document.getElementById('updateAvailable').hidden = false;
                    document.getElementById('noUpdate').hidden = true;
                    document.getElementById('changelog').textContent = updateInfo.changelog || '';
                    document.getElementById('btnStartUpdate').hidden = false;
                    document.getElementById('btnCheckUpdate').hidden = true;
                    document.getElementById('btnDismissAvailable').hidden = false;
                } else {
                    document.getElementById('noUpdate').hidden = false;
                    document.getElementById('updateAvailable').hidden = true;
                }
            } catch (error) {
                showError(error.message || 'Could not check for updates. Please try again.');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Check for Updates';
            }
        }

        // "Not now" - back out of the update-available view, no API call
        function dismissUpdateAvailable() {
            document.getElementById('idleLede').hidden = false;
            document.getElementById('updateAvailable').hidden = true;
            document.getElementById('btnStartUpdate').hidden = true;
            document.getElementById('btnDismissAvailable').hidden = true;
            document.getElementById('btnCheckUpdate').hidden = false;
        }

        // Start update
        async function startUpdate() {
            if (!updateInfo || !updateInfo.latest_version) {
                showError('No update information available. Please check for updates again.');
                return;
            }

            showProgressSection();
            await updateManager.startUpdate(updateInfo.latest_version, isTenant);
        }

        // Resume an interrupted update
        async function resumeUpdate() {
            if (!updateInfo) {
                try {
                    updateInfo = await updateManager.checkForUpdate();
                } catch (error) {
                    console.error('Failed to check for updates:', error);
                }
            }

            showProgressSection();
            await updateManager.startUpdate(updateInfo?.latest_version || null, isTenant);
        }

        // "Start over instead" from the interrupted banner - discard the
        // stuck update and go back to a clean idle screen.
        async function startOverInstead() {
            if (!confirm('Discard the interrupted update and start fresh?')) {
                return;
            }
            await updateManager.cancel();
            location.reload();
        }

        // Try again after an error - the backend can only restart this
        // cleanly, not resume mid-batch, since the failure wasn't at a
        // guaranteed-safe checkpoint.
        async function tryAgain() {
            document.getElementById('updateErrorSection').hidden = true;
            showProgressSection();
            await updateManager.startUpdate(updateInfo?.latest_version || null, isTenant);
        }

        // Cancel update
        async function cancelUpdate() {
            if (confirm('Cancel this update? Progress will be lost.')) {
                await updateManager.cancel();
                location.reload();
            }
        }

        // Show progress section
        function showProgressSection() {
            const checkSection = document.getElementById('checkUpdateSection');
            if (checkSection) checkSection.hidden = true;
            const interruptedBanner = document.getElementById('interruptedBanner');
            if (interruptedBanner) interruptedBanner.hidden = true;
            document.getElementById('updateErrorSection').hidden = true;
            document.getElementById('updateProgressSection').hidden = false;
        }

        // Handle progress updates
        function handleProgress(data) {
            const percent = Math.round(data.percent || 0);
            document.getElementById('progressBar').style.width = percent + '%';
            document.getElementById('progressPercent').textContent = percent + '%';

            if (data.phase && STATUS_SENTENCES[data.phase]) {
                document.getElementById('statusLine').textContent = STATUS_SENTENCES[data.phase];
            }

            document.getElementById('currentPhaseDisplay').textContent = getPhaseLabel(data.phase);

            if (data.downloaded !== undefined) {
                document.getElementById('progressDetail').textContent = `${data.downloaded}/${data.total} chunks`;
            } else if (data.extracted !== undefined) {
                document.getElementById('progressDetail').textContent = `${data.extracted}/${data.total} files`;
            } else if (data.replaced !== undefined) {
                document.getElementById('progressDetail').textContent = `${data.replaced} replaced, ${data.skipped} skipped`;
            }
        }

        // Handle log messages
        function handleLog(data) {
            appendLogEntry('logConsole', data);
            appendLogEntry('errorLogConsole', data);
        }

        function appendLogEntry(consoleId, data) {
            const consoleEl = document.getElementById(consoleId);
            if (!consoleEl) return;
            const entry = document.createElement('div');
            entry.className = `log-entry ${data.type}`;
            entry.innerHTML = `<span class="timestamp">[${data.timestamp}]</span> ${data.message}`;
            consoleEl.appendChild(entry);
            consoleEl.scrollTop = consoleEl.scrollHeight;
        }

        // Handle phase changes
        function handlePhaseChange(phase) {
            document.getElementById('currentPhaseDisplay').textContent = getPhaseLabel(phase);

            const reassureText = document.getElementById('progressReassureText');
            if (reassureText) {
                reassureText.textContent = phase === 'replacement'
                    ? "Your site is showing a brief maintenance message while files are installed — it comes back online automatically, even if something goes wrong."
                    : "You can close this tab. If anything interrupts it, we'll pick up right where we left off.";
            }

            const phases = ['check', 'download', 'extraction', 'replacement', 'migration', 'completed'];
            const currentIndex = phases.indexOf(phase);

            document.querySelectorAll('.phase-item').forEach((item) => {
                const itemIndex = phases.indexOf(item.dataset.phase);
                const icon = item.querySelector('.phase-icon');

                item.classList.remove('active', 'completed');

                if (itemIndex < currentIndex) {
                    item.classList.add('completed');
                    icon.textContent = '✓';
                } else {
                    icon.textContent = item.dataset.num;
                    if (itemIndex === currentIndex) {
                        item.classList.add('active');
                    }
                }
            });
        }

        // Handle errors - inline state, no browser alert()
        function handleError(error) {
            document.getElementById('updateProgressSection').hidden = true;
            showError(error.message || 'Something went wrong during the update.');
        }

        function showError(message) {
            document.getElementById('errorMessage').textContent = message;
            document.getElementById('updateErrorSection').hidden = false;
        }

        // Handle completion
        function handleComplete(result) {
            document.getElementById('updateProgressSection').hidden = true;
            document.getElementById('updateCompleteSection').hidden = false;
            document.getElementById('finalVersion').textContent = result.version || 'Latest';
        }

        // Technical phase label - used inside the "technical details" panel
        function getPhaseLabel(phase) {
            const labels = {
                initiating: 'Initializing',
                check: 'Check',
                download: 'Download',
                merging: 'Extract',
                extraction: 'Extract',
                replacement: 'Replace',
                migration: 'Migrate',
                completing: 'Finishing',
                completed: 'Done',
            };
            return labels[phase] || phase || '-';
        }

        // Auto-check on load if there's nothing to resume
        @if(!$canResume)
        document.addEventListener('DOMContentLoaded', checkForUpdate);
        @endif
    </script>
</body>
</html>
