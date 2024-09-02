<?php
/*
Plugin Name: Cloud Upload Plugin
Description: A plugin to upload WordPress media files to Google Cloud Storage.
Version: 1.0.0
Author: Javier Cáder Suay
*/

if (!defined("ABSPATH")) {
    exit(); // Exit if accessed directly.
}

// Include the Composer autoload file
require_once plugin_dir_path(__FILE__) . "vendor/autoload.php";

// Include the necessary files
require_once plugin_dir_path(__FILE__) . "includes/cloud-upload-handler.php";
require_once plugin_dir_path(__FILE__) . "includes/cloud-storage-client.php";
require_once plugin_dir_path(__FILE__) . "admin/admin-settings-page.php";
require_once plugin_dir_path(__FILE__) . "admin/admin-settings-handler.php";

// Initialize the settings page
function cloud_upload_plugin_init()
{
    new Cloud_Upload_Admin_Settings_Page();
    new Cloud_Upload_Handler();
}
add_action("plugins_loaded", "cloud_upload_plugin_init");
