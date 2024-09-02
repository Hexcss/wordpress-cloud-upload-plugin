<?php

use Google\Cloud\Storage\StorageClient;

class Cloud_Storage_Client
{
    public $storage;
    public $bucketName;

    public function __construct($serviceAccountJson, $bucketName)
    {
        $this->bucketName = $bucketName;

        $this->storage = new StorageClient([
            "keyFile" => json_decode($serviceAccountJson, true),
        ]);
    }

    public function uploadFile($filePath, $uploadName)
    {
        $bucket = $this->storage->bucket($this->bucketName);
        $file = fopen($filePath, "r");

        // Upload the file to the bucket with the new path
        $object = $bucket->upload($file, [
            "name" => $uploadName,
        ]);

        // Construct the public URL
        $publicUrl = sprintf(
            "https://storage.googleapis.com/%s/%s",
            $this->bucketName,
            $uploadName
        );

        return $publicUrl;
    }

    public function deleteFile($gcsPath)
    {
        $bucket = $this->storage->bucket($this->bucketName);
        $object = $bucket->object($gcsPath);

        if ($object->exists()) {
            $object->delete();
        } else {
            error_log("File not found: " . $gcsPath);
        }
    }
}
