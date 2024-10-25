<?php

class Cloud_Upload_Handler
{
    private $storageClient;

    public function __construct()
    {
        add_filter('wp_handle_upload', [$this, 'handle_media_upload'], 10, 2);
        add_action('delete_attachment', [$this, 'handle_media_delete'], 10, 1);

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
    }

    public function handle_media_upload($upload, $context)
    {
        if ($this->storageClient) {
            $filePath = $upload['file'];
            $fileName = basename($filePath);

            // Get the current year and month for storage structure
            $currentYear = date('Y');
            $currentMonth = date('m');

            // Upload the file to Google Cloud Storage
            $uploadPath = sprintf(
                '%s/%s/%s',
                $currentYear,
                $currentMonth,
                $fileName
            );
            $cloudUrl = $this->storageClient->uploadFile(
                $filePath,
                $uploadPath
            );

            // Delete the local file if upload was successful
            if ($cloudUrl) {
                unlink($filePath);

                // Set the URL to the cloud URL
                $upload['url'] = $cloudUrl;
                $upload['file'] = ''; // Clear local file path

                // After the file is uploaded, we need to wait until the attachment is created to update its metadata
                add_action('add_attachment', function($attachmentId) use ($cloudUrl, $uploadPath) {
                    $this->update_attachment_metadata($attachmentId, $cloudUrl, $uploadPath);
                });
            }
        }

        return $upload;
    }

    private function update_attachment_metadata($attachmentId, $cloudUrl, $uploadPath)
    {
        // Update the _wp_attached_file meta to store the upload path (relative path)
        update_post_meta($attachmentId, '_wp_attached_file', $uploadPath);

        // Save the cloud URL in a custom meta field
        update_post_meta($attachmentId, '_cloud_storage_url', $cloudUrl);

        // Update the attachment metadata
        $metadata = wp_generate_attachment_metadata($attachmentId, $cloudUrl);
        if (!empty($metadata)) {
            // Update the file path in metadata
            $metadata['file'] = $uploadPath;

            // Upload and update image sizes
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $data) {
                    if (isset($data['file'])) {
                        $sizeLocalPath = get_attached_file($attachmentId, true);
                        $sizeLocalPath = pathinfo($sizeLocalPath, PATHINFO_DIRNAME) . '/' . $data['file'];

                        if (file_exists($sizeLocalPath)) {
                            // Upload the resized image to GCS
                            $sizeUploadPath = dirname($uploadPath) . '/' . $data['file'];
                            $this->storageClient->uploadFile($sizeLocalPath, $sizeUploadPath);

                            // Delete the local resized image
                            unlink($sizeLocalPath);
                        }
                    }
                }
            }

            // Update the metadata in the database
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        update_post_meta($attachmentId, '_cloud_storage_url', $cloudUrl);
    }

    public function handle_media_delete($post_id)
    {
        if ($this->storageClient) {
            // Get the path of the attachment from post meta
            $uploadPath = get_post_meta($post_id, '_wp_attached_file', true);

            if (!empty($uploadPath)) {
                // Delete the file from Google Cloud Storage
                $this->storageClient->deleteFile($uploadPath);
            }
        }
    }
}
