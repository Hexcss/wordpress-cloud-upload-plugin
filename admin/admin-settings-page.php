<?php

class Cloud_Upload_Admin_Settings_Page
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_plugin_page']);
        add_action('admin_init', [$this, 'page_init']);
    }

    public function add_plugin_page()
    {
        // Create the top-level menu
        add_menu_page(
            'Cloud Upload',                  // Page title
            'Cloud Upload',                  // Menu title
            'manage_options',                // Capability
            'cloud-upload',                  // Menu slug
            [$this, 'create_admin_page'],    // Function to display the page
            'dashicons-cloud',               // Icon URL or Dashicons class
            66                               // Position in the menu
        );

        // Add the Settings submenu (This will rename the main menu item)
        add_submenu_page(
            'cloud-upload',                  // Parent slug
            'Cloud Upload Settings',         // Page title
            'Settings',                      // Menu title
            'manage_options',                // Capability
            'cloud-upload',                  // Menu slug (same as parent to override the parent page)
            [$this, 'create_admin_page']     // Function to display the page
        );
    }



    public function create_admin_page()
    {
        ?>
        <div class="wrap">
            <h1>Cloud Upload Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields("cloud_upload_option_group");
                do_settings_sections("cloud-upload-settings");
                submit_button("Save Settings", "primary", "submit", true, [
                    "style" => "margin-top: 20px;",
                ]);?>
            </form>
        </div>
        <?php
    }

    public function page_init()
    {
        register_setting("cloud_upload_option_group", "cloud_upload_options", [
            $this,
            "sanitize",
        ]);

        add_settings_section(
            "setting_section_id",
            "Google Cloud Settings",
            [$this, "print_section_info"],
            "cloud-upload-settings"
        );

        add_settings_field(
            "service_account_json",
            "Service Account JSON",
            [$this, "service_account_json_callback"],
            "cloud-upload-settings",
            "setting_section_id"
        );

        add_settings_field(
            "bucket_name",
            "Bucket Name",
            [$this, "bucket_name_callback"],
            "cloud-upload-settings",
            "setting_section_id"
        );
    }

    public function sanitize($input)
    {
        $new_input = [];
        if (isset($input["service_account_json"])) {
            $new_input["service_account_json"] = sanitize_textarea_field(
                $input["service_account_json"]
            );
        }
        if (isset($input["bucket_name"])) {
            $new_input["bucket_name"] = sanitize_text_field(
                $input["bucket_name"]
            );
        }
        return $new_input;
    }

    public function print_section_info()
    {
        echo "<p>Please enter your Google Cloud Storage configuration details below:</p>";
    }

    public function service_account_json_callback()
    {
        $options = get_option("cloud_upload_options");
        printf(
            '<textarea id="service_account_json" name="cloud_upload_options[service_account_json]" rows="10" cols="2" class="large-text code">%s</textarea>',
            isset($options["service_account_json"])
                ? esc_textarea($options["service_account_json"])
                : ""
        );
    }

    public function bucket_name_callback()
    {
        $options = get_option("cloud_upload_options");
        printf(
            '<input type="text" id="bucket_name" name="cloud_upload_options[bucket_name]" value="%s" class="regular-text" />',
            isset($options["bucket_name"])
                ? esc_attr($options["bucket_name"])
                : ""
        );
    }
}
