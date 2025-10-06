<?php

namespace App\Services;

use Cloudinary\Cloudinary;

class FileUploadService
{
    protected Cloudinary $cloudinary;

    public function __construct()
    {
        $this->cloudinary = new Cloudinary();
    }

    /**
     * Upload raw bytes to Cloudinary and return secure_url and public_id
     */
    public function uploadRawPath(string $localPath, string $folder = 'knowledgehub/raw', ?string $publicId = null): array
    {
        $options = [
            'resource_type' => 'raw',
            'folder' => $folder,
            'use_filename' => true,
            'unique_filename' => true,
        ];
        if (!empty($publicId)) {
            $options['public_id'] = $publicId; // without extension
        }
        $res = $this->cloudinary->uploadApi()->upload($localPath, $options);
        return [
            'secure_url' => $res['secure_url'] ?? null,
            'public_id' => $res['public_id'] ?? null,
        ];
    }
}


