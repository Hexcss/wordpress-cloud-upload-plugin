# Cloud Upload Plugin

**Version**: 1.0.0
**Author**: Javier Cáder Suay

## Overview

The **Cloud Upload Plugin** allows you to seamlessly upload WordPress media files to Google Cloud Storage, eliminating the need for local storage. This plugin automatically uploads files to a specified Google Cloud Storage bucket, organizes them by year and month, and replaces the local file URLs with public URLs from Google Cloud. Additionally, when media files are deleted from the WordPress media library, they are also removed from Google Cloud Storage.

## Features

- Uploads media files to Google Cloud Storage.
- Organizes uploaded files in a `year/month` directory structure within the bucket.
- Replaces local file URLs with public URLs from Google Cloud Storage.
- Automatically deletes media files from Google Cloud Storage when removed from WordPress.

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Google Cloud Platform (GCP) account
- Google Cloud Storage bucket with Uniform Bucket-Level Access enabled

## Installation

1. **Clone the Repository:**

   ```bash
   git clone https://github.com/yourusername/cloud-upload-plugin.git wp-content/plugins/cloud-upload-plugin
   ```
2. **Navigate to the Plugin Directory:**

    ```bash
    cd wp-content/plugins/cloud-upload-plugin
    ```
3. **Install Composer Dependencies:**

    Ensure you have [Composer](https://getcomposer.org/) installed on your system. Then, run:
    ```bash
    composer install
    ```
4. **Activate the Plugin:**

## Configuration

1. **Google Cloud Setup:**

    - Create a Google Cloud Storage bucket with Uniform Bucket-Level Access enabled.
    - Create a service account with Storage Admin permissions.
    - Download the service account key in JSON format.

2. **Plugin Configuration:**

    - In the WordPress admin dashboard, go to **Settings > Cloud Upload.**
    - Paste the contents of your Google Cloud service account JSON into the **Service Account JSON** field.
    - Enter the name of your Google Cloud Storage bucket in the **Bucket Name** field.
    - Save the settings.

## Usage

### Uploading Media Files

    - When you upload a file through the WordPress media library, it will automatically be uploaded to Google Cloud Storage.
    - The file will be stored in a year/month directory structure within your bucket.
    - The URL of the file in WordPress will be replaced with the public URL from Google Cloud Storage.

### Deleting Media Files

    - When you delete a media file from the WordPress media library, the plugin will automatically delete the corresponding file from Google Cloud Storage.

### Troubleshooting

    - File Not Found: Ensure the Google Cloud Storage bucket is publicly accessible.
    - Permissions Issues: Double-check the service account permissions and that the service account JSON is correctly configured in the plugin setting
