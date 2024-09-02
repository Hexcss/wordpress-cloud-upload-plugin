<?php

class Cloud_Upload_Handler
{
    private $storageClient;

    public function __construct()
    {
        add_filter("wp_handle_upload", [$this, "handle_media_upload"], 10, 2);
        add_action("delete_attachment", [$this, "handle_media_delete"], 10, 1);

        // Initialize the storage client
        $options = get_option("cloud_upload_options");
        if (
            !empty($options["service_account_json"]) &&
            !empty($options["bucket_name"])
        ) {
            $this->storageClient = new Cloud_Storage_Client(
                $options["service_account_json"],
                $options["bucket_name"]
            );
        }
    }

    public function handle_media_upload($upload, $context)
    {
        if ($this->storageClient) {
            $filePath = $upload["file"];
            $fileName = basename($filePath);

            // Get the current year and month for storage structure
            $currentYear = date("Y");
            $currentMonth = date("m");

            // Upload the file to Google Cloud Storage
            $uploadPath = sprintf(
                "%s/%s/%s",
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
                $upload["url"] = $cloudUrl;
                $upload["file"] = ""; // Clear local file path
            }
        }

        return $upload;
    }

    public function handle_media_delete($post_id)
    {
        if ($this->storageClient) {
            // Get the URL of the attachment
            $fileUrl = wp_get_attachment_url($post_id);

            // Extract the Google Cloud Storage path from the URL
            $gcsPath = str_replace(
                "https://storage.googleapis.com/" .
                    $this->storageClient->bucketName .
                    "/",
                "",
                $fileUrl
            );

            // Delete the file from Google Cloud Storage
            $this->storageClient->deleteFile($gcsPath);
        }
    }
}
