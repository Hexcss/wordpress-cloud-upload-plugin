<?php

class Cloud_Upload_Admin_Scan_Bucket_Page
{
    private $storageClient;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_scan_bucket_page']);

        // Initialize the storage client
        $options = get_option('cloud_upload_options');
        if (
            !empty($options['service_account_json']) &&
            !empty($options['bucket_name'])
        ) {
            $this->storageClient = new Cloud_Storage_Client(
                $options['service_account_json'],
                $options['bucket_name']
            );
        }

        // AJAX action for handling bucket scan
        add_action('wp_ajax_start_bucket_scan', [$this, 'start_bucket_scan']);
    }

    public function add_scan_bucket_page()
    {
        add_submenu_page(
            'cloud-upload',                    // Parent slug (top-level menu slug)
            'Cloud Upload - Scan Remote Bucket', // Page title
            'Scan Remote Bucket',              // Menu title
            'manage_options',                  // Capability
            'cloud-upload-scan-bucket',        // Menu slug
            [$this, 'create_scan_bucket_page'] // Function to display the page
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
                    <input type="checkbox" id="scan-by-prefix" name="scan-by-prefix">
                    Only scan files with a specific prefix
                </label>
                <input type="text" id="prefix" name="prefix" placeholder="Enter prefix" style="display:none;">
                <p>
                    <button id="start-scan" class="button button-primary">Start Scan</button>
                </p>
            </form>

            <div id="scan-progress-container" style="display:none; margin-top:20px;">
                <div class="progress" style="height:20px;">
                    <div id="scan-progress-bar" class="progress-bar" role="progressbar" style="width:0%;">0%</div>
                </div>
                <div id="scan-log" style="margin-top:10px; max-height:200px; overflow:auto; background:#fff; padding:8px; border:1px solid #ccd0d4;"></div>
            </div>

            <div id="scan-results" style="margin-top:20px;"></div>
        </div>

        <script>
            jQuery(function($) {
                $('#scan-by-prefix').on('change', function() {
                    $('#prefix').toggle(this.checked);
                });

                $('#start-scan').on('click', function(e) {
                    e.preventDefault();
                    var prefix = $('#scan-by-prefix').is(':checked') ? $('#prefix').val() : '';

                    // reset UI
                    $('#start-scan').prop('disabled', true);
                    $('#scan-results').empty();
                    $('#scan-log').empty();
                    $('#scan-progress-bar').css('width', '0%').text('0%');
                    $('#scan-progress-container').show();

                    // kick off scan
                    $.post(ajaxurl, {
                            action: 'start_bucket_scan',
                            options: {
                                prefix: prefix
                            }
                        }, function(response) {
                            if (!response.success) {
                                $('#scan-results').html(
                                    '<div class="notice notice-error"><p>' + response.data + '</p></div>'
                                );
                                return;
                            }

                            // structured JSON expected from handler
                            var data = response.data;
                            // 1) build summary
                            var html = '<div class="notice notice-success">';
                            html += '<strong>Scan Complete</strong><br>';
                            html += 'Imported: ' + data.imported + '<br>';
                            html += 'Skipped: ' + data.skipped + '<br>';
                            if (data.errors.length) {
                                html += '<details><summary style="cursor:pointer;color:#a00;">Errors (' + data.errors.length + ')</summary>';
                                html += '<ul>';
                                data.errors.forEach(function(err) {
                                    html += '<li>' + err + '</li>';
                                });
                                html += '</ul></details>';
                            }
                            html += '</div>';

                            $('#scan-results').html(html);
                        }, 'json')
                        .fail(function() {
                            $('#scan-results').html(
                                '<div class="notice notice-error"><p>Scan failed. Please try again.</p></div>'
                            );
                        })
                        .always(function() {
                            $('#start-scan').prop('disabled', false);
                        })
                        .progress(function(evt) {
                            // this will never fire unless you switch to XHR2 + progress events,
                            // but here’s where you’d update the bar if you streamed events…
                        });
                });

                // Optional: intercept console.log from PHP via SSE or chunked output
                // and push lines into #scan-log, updating the bar.
            });
        </script>
<?php
    }

    public function start_bucket_scan()
    {
        if (!$this->storageClient) {
            wp_send_json_error('Cloud storage is not configured correctly.');
        }

        $options      = $_POST['options'];
        $prefix       = !empty($options['prefix']) ? $options['prefix'] : '';
        $bucketFiles  = $this->storageClient->listFiles($prefix);

        if (empty($bucketFiles)) {
            wp_send_json_success([
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => [],
            ]);
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($bucketFiles as $object) {
            $fileName = $object->name();
            $fileUrl  = sprintf(
                'https://storage.googleapis.com/%s/%s',
                $this->storageClient->bucketName,
                $fileName
            );

            if ($this->attachment_exists($fileUrl)) {
                $skipped++;
                continue;
            }

            $attachmentId = $this->create_attachment($fileName, $fileUrl);
            if ($attachmentId) {
                $imported++;
            } else {
                $errors[] = "Failed to import: {$fileName}";
            }
        }

        wp_send_json_success([
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }

    private function attachment_exists($fileUrl)
    {
        global $wpdb;
        $attachment = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s",
            $fileUrl
        ));

        return !empty($attachment);
    }

    private function create_attachment($fileName, $fileUrl)
    {
        // Determine the file type
        $filetype = wp_check_filetype($fileName);

        // Set up attachment data
        $attachment = [
            'guid'           => $fileUrl,
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name($fileName),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        // Insert the attachment into the database
        $attachmentId = wp_insert_attachment($attachment);

        if (!is_wp_error($attachmentId)) {
            // Generate attachment metadata and update
            $metadata = [
                'file' => $fileName
            ];

            wp_update_attachment_metadata($attachmentId, $metadata);

            // Save the cloud URL in a custom meta field
            update_post_meta($attachmentId, '_cloud_storage_url', $fileUrl);
            update_post_meta($attachmentId, '_wp_attached_file', $fileName);

            return $attachmentId;
        }

        return false;
    }
}
