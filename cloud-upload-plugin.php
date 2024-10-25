<?php
/*
Plugin Name: Cloud Upload Plugin
Description: A plugin to upload WordPress media files to Google Cloud Storage.
Version: 1.1.0
Author: Javier Cáder Suay
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include the Composer autoload file
if (file_exists(ABSPATH . 'vendor/autoload.php')) {
    require_once ABSPATH . 'vendor/autoload.php';
} elseif (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
} else {
    // No autoloader found, cannot continue.
    wp_die('Autoloader not found. Please run composer install.');
}

// Include necessary files
// Include necessary files
require_once plugin_dir_path(__FILE__) . 'admin/admin-settings-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/admin-migration-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/admin-scan-remote-bucket-page.php'; // Include the new file
require_once plugin_dir_path(__FILE__) . 'includes/cloud-storage-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/cloud-upload-handler.php';

// Initialize the settings and migration page
function cloud_upload_plugin_init() {
    new Cloud_Upload_Admin_Settings_Page();
    new Cloud_Upload_Admin_Migration_Page();
    new Cloud_Upload_Admin_Scan_Bucket_Page(); 
    new Cloud_Upload_Handler();
}
add_action('plugins_loaded', 'cloud_upload_plugin_init');

// Add the filter function here
add_filter('wp_get_attachment_url', 'my_custom_wp_get_attachment_url', 10, 2);

function my_custom_wp_get_attachment_url($url, $post_id) {
    $cloud_url = get_post_meta($post_id, '_cloud_storage_url', true);
    if (!empty($cloud_url)) {
        return $cloud_url;
    }
    return $url;
}

add_filter('upload_dir', 'my_custom_upload_dir');

function my_custom_upload_dir($dirs) {
    $options = get_option('cloud_upload_options');
    $bucket_url = 'https://storage.googleapis.com/' . $options['bucket_name'];

    $dirs['baseurl'] = $bucket_url;
    return $dirs;
}
