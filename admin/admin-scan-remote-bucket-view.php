<?php
// File: admin/admin-scan-remote-bucket-view.php
?>
<style>
    .scan-grid {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 1em;
    }

    .scan-log,
    #scan-errors {
        font-family: monospace;
        font-size: 13px;
    }

    #dual-bar {
        position: relative;
        height: 1em;
        background: #eee;
        margin-bottom: .5em;
    }

    #bar-imported {
        position: absolute;
        left: 0;
        height: 100%;
        background: #46b450;
    }

    #bar-skipped {
        position: absolute;
        left: 0;
        height: 100%;
        background: #f7b924;
    }
</style>

<div class="wrap">
    <h1>Cloud Upload – Scan Remote Bucket</h1>
    <p>Scan your Google Cloud Storage bucket and import files into the Media Library.</p>

    <form id="scan-bucket-form" class="scan-grid">
        <label>
            <input type="checkbox" id="scan-by-prefix">
            Only scan files with prefix
        </label>
        <input type="text" id="prefix" placeholder="Enter prefix"
            style="display:none;margin-left:.5em;">
        <button id="start-scan" class="button button-primary">
            <span class="dashicons dashicons-cloud-upload"></span> Start
        </button>
        <button id="pause-scan" class="button">Pause</button>
        <button id="cancel-scan" class="button">Cancel</button>
    </form>

    <div id="scan-ui" style="display:none;margin-top:20px;">
        <!-- batch + current file -->
        <p>
            <strong>Batch:</strong> <span id="scan-batch">0</span> /
            <span id="scan-batches-total">0</span>
            &nbsp;|&nbsp;
            <strong>Current File:</strong> <span id="scan-current-file">—</span>
        </p>

        <!-- dual-color bar -->
        <div id="dual-bar">
            <div id="bar-imported" style="width:0"></div>
            <div id="bar-skipped" style="width:0"></div>
        </div>

        <!-- numeric progress + ETA -->
        <p>
            <progress id="scan-progress-bar" value="0" max="100"></progress>
            <span id="scan-percent">0%</span><br>
            Processed <strong id="scan-processed">0</strong> /
            <strong id="scan-total">0</strong> files.<br>
            Imported: <strong id="scan-imported">0</strong>,
            Skipped: <strong id="scan-skipped">0</strong>.<br>
            Elapsed: <span id="scan-elapsed">00:00</span>,
            ETA: <span id="scan-eta">00:00</span>
            <span id="scan-spinner" class="spinner" style="visibility:hidden;"></span>
        </p>

        <!-- logs & errors side-by-side -->
        <div style="display:flex; gap:1em;">
            <div style="flex:2;">
                <h4>Activity Log</h4>
                <div id="scan-log" class="scan-log"
                    style="background:#fff;border:1px solid #ccd0d4;
                    padding:1em;max-height:200px;overflow:auto;"></div>
            </div>
            <div style="flex:1;">
                <h4>Errors (<span id="error-count">0</span>)</h4>
                <ul id="scan-errors" style="color:#c00;list-style:none;"></ul>
            </div>
        </div>

        <button id="download-log" class="button" style="margin-top:1em;">
            Download Log
        </button>
    </div>
</div>

<script>
    jQuery(function($) {
        const BATCH_SIZE = 20;
        let startTime, paused = false,
            cancelled = false;

        const $prefix = $('#prefix'),
            $byPrefix = $('#scan-by-prefix'),
            $startBtn = $('#start-scan'),
            $pauseBtn = $('#pause-scan'),
            $cancelBtn = $('#cancel-scan'),
            $download = $('#download-log'),
            $ui = $('#scan-ui'),
            $barImp = $('#bar-imported'),
            $barSkip = $('#bar-skipped'),
            $bar = $('#scan-progress-bar'),
            $pct = $('#scan-percent'),
            $batch = $('#scan-batch'),
            $batches = $('#scan-batches-total'),
            $curFile = $('#scan-current-file'),
            $processed = $('#scan-processed'),
            $total = $('#scan-total'),
            $imp = $('#scan-imported'),
            $skip = $('#scan-skipped'),
            $elapsed = $('#scan-elapsed'),
            $eta = $('#scan-eta'),
            $spinner = $('#scan-spinner'),
            $log = $('#scan-log'),
            $errors = $('#scan-errors'),
            $errCount = $('#error-count');

        $byPrefix.on('change', () => $prefix.toggle($byPrefix.is(':checked')));

        $pauseBtn.on('click', () => paused = !paused);
        $cancelBtn.on('click', () => cancelled = true);

        $startBtn.on('click', function(e) {
            e.preventDefault();
            paused = cancelled = false;
            startTime = Date.now();
            $startBtn.prop('disabled', true);
            $log.empty().append('Initializing scan...\n');
            $errors.empty();
            $errCount.text(0);

            $.post(ajaxurl, {
                action: 'start_bucket_scan',
                options: {
                    prefix: $byPrefix.is(':checked') ? $prefix.val() : ''
                }
            }, function(res) {
                if (!res.success) {
                    $log.append('ERROR: ' + res.data + '\n');
                    $startBtn.prop('disabled', false);
                    return;
                }
                $ui.show();
                pollBatch(res.data.scan_id);
            });
        });

        function formatTime(sec) {
            const m = Math.floor(sec / 60),
                s = Math.floor(sec % 60);
            return `${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
        }

        function pollBatch(scanId) {
            if (cancelled) {
                $log.append('🚫 Scan cancelled\n');
                return;
            }
            if (paused) {
                $log.append('⏸ Paused\n');
                return setTimeout(() => pollBatch(scanId), 1000);
            }

            $spinner.css('visibility', 'visible');
            $.post(ajaxurl, {
                action: 'scan_bucket_batch',
                scan_id: scanId
            }, function(res) {
                $spinner.css('visibility', 'hidden');
                if (!res.success) {
                    $log.append('ERROR: ' + res.data.message + '\n');
                    $startBtn.prop('disabled', false);
                    return;
                }
                let p = res.data.progress,
                    batches = Math.ceil(p.total / BATCH_SIZE),
                    thisBatch = Math.ceil(p.processed / BATCH_SIZE) || 1;

                $batches.text(batches);
                $batch.text(thisBatch);

                // log each file
                res.data.batch.forEach(item => {
                    const icon = item.status === 'imported' ? '✅' :
                        item.status === 'skipped' ? '⏭️' : '❌';
                    $log.append(`${icon} ${item.file}\n`);
                    if (item.status === 'error') {
                        $errors.append(`<li>${item.file}</li>`);
                        $errCount.text(parseInt($errCount.text()) + 1);
                    }
                    $curFile.text(item.file);
                });

                // counters
                $processed.text(p.processed);
                $total.text(p.total);
                $imp.text(p.imported);
                $skip.text(p.skipped);

                // dual-bar
                const impPct = p.imported / p.total * 100,
                    skipPct = p.skipped / p.total * 100;
                $barImp.css('width', impPct + '%');
                $barSkip.css('width', skipPct + '%');

                // progress bar + pct
                const pct = Math.floor(p.processed / p.total * 100);
                $bar.val(pct);
                $pct.text(pct + '%');

                // timers
                const elapsed = (Date.now() - startTime) / 1000,
                    rate = p.processed / elapsed,
                    rem = p.total - p.processed,
                    eta = rate > 0 ? rem / rate : 0;
                $elapsed.text(formatTime(elapsed));
                $eta.text(formatTime(eta));

                if (!res.data.done) {
                    setTimeout(() => pollBatch(scanId), 300);
                } else {
                    $log.append('✅ Scan complete!\n');
                    $startBtn.prop('disabled', false);
                }
            });
        }

        // download log
        $download.on('click', () => {
            const blob = new Blob([$log.text()], {
                    type: 'text/plain'
                }),
                url = URL.createObjectURL(blob),
                a = document.createElement('a');
            a.href = url;
            a.download = 'scan-log.txt';
            document.body.appendChild(a);
            a.click();
            URL.revokeObjectURL(url);
            document.body.removeChild(a);
        });
    });
</script>