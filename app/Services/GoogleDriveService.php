<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    private $client;
    private $service;

    public function __construct($accessToken = null)
    {
        $this->client = new GoogleClient();
        $this->client->setClientId(env('GOOGLE_CLIENT_ID'));
        $this->client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $this->client->addScope([
            'https://www.googleapis.com/auth/drive.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ]);

        if ($accessToken) {
            $this->client->setAccessToken($accessToken);
        }

        $this->service = new Drive($this->client);
    }

    public function setAccessToken($accessToken)
    {
        $this->client->setAccessToken($accessToken);
    }

    public function listFiles($pageToken = null, $maxResults = 100)
    {
        try {
            $optParams = [
                'pageSize' => $maxResults,
                'fields' => 'nextPageToken, files(id, name, mimeType, size, modifiedTime, webViewLink, webContentLink)',
                'q' => "trashed=false and (mimeType contains 'text/' or mimeType contains 'application/pdf' or mimeType contains 'application/vnd.google-apps.document' or mimeType contains 'application/vnd.google-apps.presentation' or mimeType contains 'application/vnd.google-apps.spreadsheet')"
            ];

            if ($pageToken) {
                $optParams['pageToken'] = $pageToken;
            }

            $results = $this->service->files->listFiles($optParams);
            return $results;
        } catch (\Exception $e) {
            Log::error('Google Drive API error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getFileContent($fileId, $mimeType = null)
    {
        try {
            // Handle Google Docs/Sheets/Slides differently
            if (str_contains($mimeType, 'application/vnd.google-apps.')) {
                return $this->exportGoogleAppFile($fileId, $mimeType);
            }

            // For regular files, download directly
            $response = $this->service->files->get($fileId, [
                'alt' => 'media'
            ]);

            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            Log::error("Error fetching file content for ID {$fileId}: " . $e->getMessage());
            throw $e;
        }
    }

    private function exportGoogleAppFile($fileId, $mimeType)
    {
        $exportMimeType = $this->getExportMimeType($mimeType);
        
        try {
            $response = $this->service->files->export($fileId, $exportMimeType, [
                'alt' => 'media'
            ]);

            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            Log::error("Error exporting Google App file {$fileId}: " . $e->getMessage());
            throw $e;
        }
    }

    private function getExportMimeType($mimeType)
    {
        return match ($mimeType) {
            'application/vnd.google-apps.document' => 'text/plain',
            'application/vnd.google-apps.spreadsheet' => 'text/csv',
            'application/vnd.google-apps.presentation' => 'text/plain',
            default => 'text/plain'
        };
    }

    public function getFileMetadata($fileId)
    {
        try {
            $file = $this->service->files->get($fileId, [
                'fields' => 'id, name, mimeType, size, modifiedTime, webViewLink, createdTime, owners, lastModifyingUser'
            ]);

            return $file;
        } catch (\Exception $e) {
            Log::error("Error fetching file metadata for ID {$fileId}: " . $e->getMessage());
            throw $e;
        }
    }

    public function refreshToken($refreshToken)
    {
        try {
            $this->client->refreshToken($refreshToken);
            return $this->client->getAccessToken();
        } catch (\Exception $e) {
            Log::error('Error refreshing Google Drive token: ' . $e->getMessage());
            throw $e;
        }
    }
}