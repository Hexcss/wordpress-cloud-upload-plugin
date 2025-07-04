<?php
// File: admin/admin-scan-remote-bucket-page.php

if (! defined('ABSPATH')) {
    exit;
}

class Cloud_Upload_Admin_Scan_Bucket_Page
{
    private $storageClient;
    private $transient_expiry = 300; // 5 minutes
    private $batch_size       = 20;  // files per batch

    public function __construct()
    {
        add_action('admin_menu',               [$this, 'add_scan_bucket_page']);
        add_action('wp_ajax_start_bucket_scan', [$this, 'start_bucket_scan']);
        add_action('wp_ajax_scan_bucket_batch', [$this, 'scan_bucket_batch']);

        $options = get_option('cloud_upload_options', []);
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
            'Cloud Upload – Scan Remote Bucket',
            'Scan Remote Bucket',
            'manage_options',
            'cloud-upload-scan-bucket',
            [$this, 'create_scan_bucket_page']
        );
    }

    public function create_scan_bucket_page()
    {
        // include the separate view file
        include plugin_dir_path(__FILE__) . 'admin-scan-remote-bucket-view.php';
    }

    public function start_bucket_scan()
    {
        if (empty($this->storageClient)) {
            wp_send_json_error('Cloud storage is not configured correctly.');
        }

        $prefix = ! empty($_POST['options']['prefix'])
            ? sanitize_text_field($_POST['options']['prefix'])
            : '';

        // Fetch the iterator but do NOT drain it
        $objects = $this->storageClient->listFiles($prefix);

        // Only import these extensions
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $names   = [];

        foreach ($objects as $object) {
            $name = $object->name();
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true)) {
                $names[] = $name;
            }
        }

        if (empty($names)) {
            wp_send_json_success(['message' => 'No images found in the bucket.']);
        }

        // Store just the names + progress in a transient
        $scanId = uniqid('cloud_scan_', true);
        set_transient($scanId, [
            'files'    => $names,
            'imported' => 0,
            'skipped'  => 0,
            'errors'   => [],
            'current'  => 0,
        ], $this->transient_expiry);

        error_log("ScanBucket[{$scanId}]: initialized with " . count($names) . " images");

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

        $batch_results = [];

        // instrumentation
        $batchStart = microtime(true);
        error_log("ScanBucket[{$scan_id}]: batch starting files {$start}–" . ($end - 1));

        for ($i = $start; $i < $end; $i++) {
            $fileName    = $files[$i];
            $fileUrl     = sprintf(
                'https://storage.googleapis.com/%s/%s',
                $this->storageClient->bucketName,
                $fileName
            );
            $itemStart   = microtime(true);

            if ($this->attachment_exists($fileUrl)) {
                $status = 'skipped';
                $data['skipped']++;
            } else {
                $aid = $this->create_attachment($fileName, $fileUrl);
                if ($aid) {
                    $status = 'imported';
                    $data['imported']++;
                } else {
                    $status = 'error';
                    $data['errors'][] = "Failed to import: {$fileName}";
                }
            }

            $duration = microtime(true) - $itemStart;
            error_log("ScanBucket[{$scan_id}]: {$status} {$fileName} in " . round($duration, 3) . "s");

            $batch_results[] = [
                'file'     => $fileName,
                'status'   => $status,
                'duration' => round($duration, 3),
            ];
        }

        // update transient
        $data['current'] = $end;
        set_transient($scan_id, $data, $this->transient_expiry);

        $batchDuration = microtime(true) - $batchStart;
        error_log("ScanBucket[{$scan_id}]: batch processed " . ($end - $start)
            . " items in " . round($batchDuration, 3) . "s");

        wp_send_json_success([
            'done'           => ($end >= count($files)),
            'batch_duration' => round($batchDuration, 3),
            'batch'          => $batch_results,
            'progress'       => [
                'total'     => count($files),
                'processed' => $data['current'],
                'imported'  => $data['imported'],
                'skipped'   => $data['skipped'],
                'errors'    => $data['errors'],
            ],
        ]);
    }

    private function attachment_exists($fileUrl)
    {
        global $wpdb;
        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = %s
                     AND meta_value = %s
                   LIMIT 1",
                '_cloud_storage_url',
                $fileUrl
            )
        );
        return ! empty($post_id);
    }

    private function create_attachment($fileName, $fileUrl)
    {
        // 1) Insert the attachment post; no local file needed
        $filetype   = wp_check_filetype($fileName);
        $attachment = [
            'guid'           => $fileUrl,
            'post_mime_type' => $filetype['type'] ?? 'application/octet-stream',
            'post_title'     => sanitize_file_name($fileName),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment);
        if (is_wp_error($attach_id)) {
            return false;
        }

        // 2) Tell WP there are no real thumbnails (always use full image)
        $metadata = [
            'file'   => $fileName,
            'width'  => 0,
            'height' => 0,
            'sizes'  => [],
        ];
        wp_update_attachment_metadata($attach_id, $metadata);

        // 3) Store GCS URL so get_attachment_url() returns it
        update_post_meta($attach_id, '_cloud_storage_url', $fileUrl);
        update_post_meta($attach_id, '_wp_attached_file',  $fileName);

        return $attach_id;
    }
}
