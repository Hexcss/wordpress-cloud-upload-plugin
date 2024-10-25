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
            <h1>Cloud Upload - Scan Remote Bucket</h1>
            <p>Scan your Google Cloud Storage bucket and import files into the Media Library.</p>

            <form id="scan-bucket-form">
                <label>
                    <input type="checkbox" id="scan-by-prefix" name="scan-by-prefix">
                    Only scan files with a specific prefix
                </label>
                <input type="text" id="prefix" name="prefix" placeholder="Enter prefix" style="display: none;"><br><br>

                <button id="start-scan" class="button button-primary">Start Scan</button>
            </form>

            <div id="scan-progress" style="margin-top: 20px;"></div>

            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#scan-by-prefix').on('change', function() {
                        if ($(this).is(':checked')) {
                            $('#prefix').show();
                        } else {
                            $('#prefix').hide();
                        }
                    });

                    $('#start-scan').on('click', function(e) {
                        e.preventDefault();
                        const options = {
                            prefix: $('#scan-by-prefix').is(':checked') ? $('#prefix').val() : ''
                        };
                        $('#scan-progress').html('Scanning bucket...');
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'start_bucket_scan',
                                options: options
                            },
                            success: function(response) {
                                $('#scan-progress').html(response.data.message);
                            },
                            error: function() {
                                $('#scan-progress').html('Scan failed. Please try again.');
                            }
                        });
                    });
                });
            </script>

        </div>
        <?php
    }

    public function start_bucket_scan()
    {
        if (!$this->storageClient) {
            wp_send_json_error('Cloud storage is not configured correctly.');
        }

        $options = $_POST['options'];
        $prefix = !empty($options['prefix']) ? $options['prefix'] : '';

        // Get all files from the bucket
        $bucketFiles = $this->storageClient->listFiles($prefix);

        if (empty($bucketFiles)) {
            wp_send_json_success(['message' => 'No files found in the bucket.']);
        }

        $importedCount = 0;
        $skippedCount = 0;
        $errors = [];

        foreach ($bucketFiles as $object) {
            $fileName = $object->name();
            $fileUrl = sprintf(
                'https://storage.googleapis.com/%s/%s',
                $this->storageClient->bucketName,
                $fileName
            );

            // Check if the file already exists in the Media Library
            if ($this->attachment_exists($fileUrl)) {
                $skippedCount++;
                continue;
            }

            // Create attachment in the Media Library
            $attachmentId = $this->create_attachment($fileName, $fileUrl);

            if ($attachmentId) {
                $importedCount++;
            } else {
                $errors[] = "Failed to import file: {$fileName}";
            }
        }

        $responseMessage = "Scan complete: {$importedCount} files imported, {$skippedCount} files skipped.";
        if (!empty($errors)) {
            $responseMessage .= "<br>Errors: <ul><li>" . implode("</li><li>", $errors) . "</li></ul>";
        }

        wp_send_json_success(['message' => $responseMessage]);
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
