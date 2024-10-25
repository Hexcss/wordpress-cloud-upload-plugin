<?php

class Cloud_Upload_Admin_Migration_Page
{
    private $storageClient;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_migration_page']);

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

        // AJAX action for handling migration
        add_action('wp_ajax_start_migration', [$this, 'start_migration']);
    }

    public function add_migration_page()
    {
        add_submenu_page(
            'cloud-upload',                  // Parent slug (top-level menu slug)
            'Cloud Upload Migration',        // Page title
            'Migration',                     // Menu title
            'manage_options',                // Capability
            'cloud-upload-migration',        // Menu slug
            [$this, 'create_migration_page'] // Function to display the page
        );
    }

    public function create_migration_page()
    {
        ?>
        <div class="wrap">
            <h1>Cloud Upload - Migrate Local Files</h1>
            <p><strong>Important:</strong> Please ensure that you have backed up your WordPress files and database before proceeding with the migration. The migration process may change file URLs and is not reversible.</p>
            
            <form id="migration-options">
                <label>
                    <input type="checkbox" id="migrate-by-folder" name="migrate-by-folder">
                    Maintain existing folder structure
                </label><br><br>
                <label for="file-type">Select file types to migrate:</label>
                <select id="file-type" name="file-type">
                    <option value="all">All</option>
                    <option value="images">Images</option>
                    <option value="videos">Videos</option>
                    <option value="documents">Documents</option>
                </select><br><br>
                <label for="date-range">Migrate files from:</label>
                <input type="date" id="date-range" name="date-range"><br><br>
                <button id="start-migration" class="button button-primary">Start Migration</button>
            </form>
            
            <div id="migration-progress" style="margin-top: 20px;"></div>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#start-migration').on('click', function(e) {
                        e.preventDefault();
                        const options = {
                            folder: $('#migrate-by-folder').is(':checked'),
                            fileType: $('#file-type').val(),
                            dateRange: $('#date-range').val()
                        };
                        $('#migration-progress').html('Migration in progress...');
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'start_migration',
                                options: options
                            },
                            success: function(response) {
                                $('#migration-progress').html(response.data.message);
                            },
                            error: function() {
                                $('#migration-progress').html('Migration failed. Please try again.');
                            }
                        });
                    });
                });
            </script>

        </div>
        <?php
    }

    public function start_migration()
    {
        if (!$this->storageClient) {
            wp_send_json_error('Cloud storage is not configured correctly.');
        }

        global $wpdb;
        $options = $_POST['options'];
        $attachments = $this->get_attachments($options);

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($attachments as $attachment) {
            // Get the local file path
            $localPath = get_attached_file($attachment->ID);
            $fileName = basename($localPath);

            // Skip if the attachment already has a cloud URL
            if (strpos($attachment->guid, 'https://storage.googleapis.com/') !== false) {
                continue;
            }

            if (file_exists($localPath)) {
                // Determine upload path based on user selection
                $uploadPath = $options['folder'] ? $this->generate_folder_based_path($localPath, $fileName) : $this->generate_default_path($fileName);

                // Upload to GCS
                $cloudUrl = $this->storageClient->uploadFile($localPath, $uploadPath);

                if ($cloudUrl) {
                    // Update URLs in the WordPress database
                    $updated = $this->update_attachment_url($attachment->ID, $cloudUrl, $uploadPath, $localPath);

                    if ($updated) {
                        // Delete local file
                        unlink($localPath);
                        $successCount++;
                    } else {
                        $errorCount++;
                        $errors[] = "Error updating database for {$fileName}. Attachment ID: {$attachment->ID}.";
                    }
                } else {
                    $errorCount++;
                    $errors[] = "Error uploading {$fileName} to cloud.";
                }
            } else {
                $errorCount++;
                $errors[] = "Error migrating {$fileName}: File not found.";
            }
        }

        $responseMessage = "Migration complete: {$successCount} files migrated, {$errorCount} errors.";
        if ($errorCount > 0) {
            $responseMessage .= "<br>Errors: <ul><li>" . implode("</li><li>", $errors) . "</li></ul>";
        }

        wp_send_json_success(['message' => $responseMessage]);
    }

    private function update_attachment_url($attachmentId, $cloudUrl, $uploadPath, $localPath)
    {
        // Update the _wp_attached_file meta to store the upload path (relative path)
        update_post_meta($attachmentId, '_wp_attached_file', $uploadPath);

        // Save the cloud URL in a custom meta field
        update_post_meta($attachmentId, '_cloud_storage_url', $cloudUrl);

        // Update the attachment metadata
        $metadata = wp_get_attachment_metadata($attachmentId);

        if ($metadata) {
            // Update the main file path
            $metadata['file'] = $uploadPath;

            // Update image sizes if applicable
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $data) {
                    if (isset($data['file'])) {
                        $sizeLocalPath = pathinfo($localPath, PATHINFO_DIRNAME) . '/' . $data['file'];

                        // Upload the resized image to GCS
                        $sizeUploadPath = dirname($uploadPath) . '/' . $data['file'];
                        $sizeCloudUrl = $this->storageClient->uploadFile($sizeLocalPath, $sizeUploadPath);

                        // Delete the local resized image
                        if (file_exists($sizeLocalPath)) {
                            unlink($sizeLocalPath);
                        }
                    }
                }
            }

            // Save the updated metadata
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        return true;
    }

    private function get_attachments($options)
    {
        global $wpdb;

        $query = "SELECT ID, guid FROM {$wpdb->posts} WHERE post_type = 'attachment'";

        // Filter by file type
        if ($options['fileType'] !== 'all') {
            $mimeTypeCondition = $this->get_mime_type_condition($options['fileType']);
            $query .= " AND post_mime_type IN ($mimeTypeCondition)";
        }

        // Filter by date range
        if (!empty($options['dateRange'])) {
            $query .= $wpdb->prepare(" AND post_date >= %s", $options['dateRange']);
        }

        return $wpdb->get_results($query);
    }

    private function get_mime_type_condition($type)
    {
        switch ($type) {
            case 'images':
                return "'image/jpeg', 'image/png', 'image/gif'";
            case 'videos':
                return "'video/mp4', 'video/avi', 'video/mpeg'";
            case 'documents':
                return "'application/pdf', 'application/msword'";
            default:
                return "'image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/avi', 'video/mpeg', 'application/pdf', 'application/msword'";
        }
    }

    private function generate_folder_based_path($localPath, $fileName)
    {
        $relativePath = str_replace(wp_upload_dir()['basedir'], '', $localPath);
        return trim($relativePath, '/');
    }

    private function generate_default_path($fileName)
    {
        $currentYear = date('Y');
        $currentMonth = date('m');
        return sprintf('%s/%s/%s', $currentYear, $currentMonth, $fileName);
    }

    private function log_errors($messages)
    {
        $logFile = plugin_dir_path(__FILE__) . 'migration_errors.log';
        foreach ($messages as $message) {
            error_log($message . PHP_EOL, 3, $logFile);
        }
    }
}
