<?php

class Cloud_Upload_Admin_Scan_Bucket_Page
{
    private $storageClient;
    private $transient_expiry = 300; // 5 minutes
    private $batch_size       = 20;  // files per batch

    public function __construct()
    {
        add_action('admin_menu',              [$this, 'add_scan_bucket_page']);
        add_action('wp_ajax_start_bucket_scan',  [$this, 'start_bucket_scan']);
        add_action('wp_ajax_scan_bucket_batch',  [$this, 'scan_bucket_batch']);

        $options = get_option('cloud_upload_options');
        if (
            ! empty($options['service_account_json']) &&
            ! empty($options['bucket_name'])
        ) {
            $this->storageClient = new Cloud_Storage_Client(
                $options['service_account_json'],
                $options['bucket_name']
            );
        }
    }

    public function add_scan_bucket_page()
    {
        add_submenu_page(
            'cloud-upload',
            'Cloud Upload - Scan Remote Bucket',
            'Scan Remote Bucket',
            'manage_options',
            'cloud-upload-scan-bucket',
            [$this, 'create_scan_bucket_page']
        );
    }

    public function create_scan_bucket_page()
    {
?>
        <div class="wrap">
            <h1>Cloud Upload – Scan Remote Bucket</h1>
            <p>Scan your Google Cloud Storage bucket and import files into the Media Library.</p>

            <form id="scan-bucket-form">
                <label>
                    <input type="checkbox" id="scan-by-prefix">
                    Only scan files with a specific prefix
                </label>
                <input type="text" id="prefix" placeholder="Enter prefix" style="display: none; margin-left: .5em;">
                <br><br>
                <button id="start-scan" class="button button-primary">
                    <span class="dashicons dashicons-cloud-upload"></span> Start Scan
                </button>
            </form>

            <div id="scan-ui" style="display: none; margin-top: 20px;">
                <div style="display: flex; align-items: center;">
                    <progress id="scan-progress-bar" value="0" max="100" style="flex:1; margin-right:1em;"></progress>
                    <span id="scan-percent">0%</span>
                </div>
                <p>
                    Processed <strong id="scan-processed">0</strong> /
                    <strong id="scan-total">0</strong> files.
                    Imported: <strong id="scan-imported">0</strong>,
                    Skipped: <strong id="scan-skipped">0</strong>
                    <span id="scan-spinner" class="spinner" style="visibility: hidden;"></span>
                </p>

                <h2 class="screen-reader-text">Scan Log</h2>
                <div id="scan-log" style="
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    padding: 1em;
                    max-height: 200px;
                    overflow: auto;
                    font-family: monospace;
                    font-size: 13px;
                "></div>
            </div>
        </div>

        <script>
            jQuery(function($) {
                const $prefix = $('#prefix'),
                    $byPrefix = $('#scan-by-prefix'),
                    $startBtn = $('#start-scan'),
                    $ui = $('#scan-ui'),
                    $bar = $('#scan-progress-bar'),
                    $percent = $('#scan-percent'),
                    $processed = $('#scan-processed'),
                    $total = $('#scan-total'),
                    $imported = $('#scan-imported'),
                    $skipped = $('#scan-skipped'),
                    $spinner = $('#scan-spinner'),
                    $log = $('#scan-log');

                $byPrefix.on('change', () => $prefix.toggle($byPrefix.is(':checked')));

                $startBtn.on('click', function(e) {
                    e.preventDefault();
                    $startBtn.prop('disabled', true);
                    $log.empty().append('Initializing scan...\n');
                    const opts = {
                        prefix: $byPrefix.is(':checked') ? $prefix.val() : ''
                    };

                    // Start scan
                    $.post(ajaxurl, {
                        action: 'start_bucket_scan',
                        options: opts
                    }, function(res) {
                        if (!res.success) {
                            $log.append('ERROR: ' + res.data + '\n');
                            $startBtn.prop('disabled', false);
                            return;
                        }
                        const scanId = res.data.scan_id;
                        $ui.show();
                        pollBatch(scanId);
                    });
                });

                function pollBatch(scanId) {
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
                        let p = res.data.progress;
                        // update counters
                        $processed.text(p.processed);
                        $total.text(p.total);
                        $imported.text(p.imported);
                        $skipped.text(p.skipped);
                        // update progress bar
                        let pct = Math.floor((p.processed / p.total) * 100);
                        $bar.val(pct);
                        $percent.text(pct + '%');
                        // log any new errors
                        if (p.errors.length) {
                            p.errors.forEach(err => {
                                if (!$log.text().includes(err)) {
                                    $log.append('ERROR: ' + err + '\n');
                                }
                            });
                        }
                        // continue or finish
                        if (!res.data.done) {
                            setTimeout(() => pollBatch(scanId), 300);
                        } else {
                            $log.append('✅ Scan complete!\n');
                            $startBtn.prop('disabled', false);
                        }
                    });
                }
            });
        </script>
<?php
    }

    public function start_bucket_scan()
    {
        if (! $this->storageClient) {
            wp_send_json_error('Cloud storage is not configured correctly.');
        }

        $prefix = ! empty($_POST['options']['prefix'])
            ? sanitize_text_field($_POST['options']['prefix'])
            : '';

        $objects = $this->storageClient->listFiles($prefix);
        if (empty(iterator_to_array($objects))) {
            wp_send_json_success([
                'message' => 'No files found in the bucket.',
            ]);
        }

        // build an array of file names
        $names = [];
        foreach ($objects as $object) {
            /** @var \Google\Cloud\Storage\StorageObject $object */
            $names[] = $object->name();
        }

        $scanId = uniqid('cloud_scan_', true);
        set_transient($scanId, [
            'files'    => $names,
            'imported' => 0,
            'skipped'  => 0,
            'errors'   => [],
            'current'  => 0,
        ], $this->transient_expiry);

        wp_send_json_success([
            'message' => 'Scan initialized.',
            'scan_id' => $scanId,
        ]);
    }


    public function scan_bucket_batch()
    {
        $scan_id = sanitize_text_field($_POST['scan_id'] ?? '');
        $data    = get_transient($scan_id);

        if (! $data || empty($data['files'])) {
            wp_send_json_error(['message' => 'Invalid or expired scan ID.']);
        }

        $files = $data['files'];
        $start = $data['current'];
        $end   = min($start + $this->batch_size, count($files));

        for ($i = $start; $i < $end; $i++) {
            $fileName = $files[$i];
            $fileUrl  = sprintf(
                'https://storage.googleapis.com/%s/%s',
                $this->storageClient->bucketName,
                $fileName
            );

            if ($this->attachment_exists($fileUrl)) {
                $data['skipped']++;
            } else {
                $aid = $this->create_attachment($fileName, $fileUrl);
                if ($aid) {
                    $data['imported']++;
                } else {
                    $data['errors'][] = "Failed to import: {$fileName}";
                }
            }
        }

        $data['current'] = $end;
        set_transient($scan_id, $data, $this->transient_expiry);

        wp_send_json_success([
            'done'     => ($end >= count($files)),
            'progress' => [
                'total'     => count($files),
                'processed' => $end,
                'imported'  => $data['imported'],
                'skipped'   => $data['skipped'],
                'errors'    => $data['errors'],
            ],
        ]);
    }

    private function attachment_exists($fileUrl)
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s",
            $fileUrl
        ));
    }

    private function create_attachment($fileName, $fileUrl)
    {
        $filetype   = wp_check_filetype($fileName);
        $attachment = [
            'guid'           => $fileUrl,
            'post_mime_type' => $filetype['type'] ?? 'application/octet-stream',
            'post_title'     => sanitize_file_name($fileName),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $id = wp_insert_attachment($attachment);
        if (is_wp_error($id)) {
            return false;
        }

        wp_update_attachment_metadata($id, ['file' => $fileName]);
        update_post_meta($id, '_cloud_storage_url', $fileUrl);
        update_post_meta($id, '_wp_attached_file',    $fileName);

        return $id;
    }
}
