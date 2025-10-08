<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DropboxService
{
    protected string $accessToken;
    protected string $refreshToken;

    public function __construct(string $accessToken, ?string $refreshToken = null)
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken ?? '';
    }

    /**
     * List all files in Dropbox account (recursively)
     */
    public function listFiles(string $path = '', bool $recursive = true): array
    {
        $allFiles = [];
        $cursor = null;

        do {
            if ($cursor) {
                $response = $this->listFolderContinue($cursor);
            } else {
                $response = Http::withToken($this->accessToken)
                    ->timeout(60)
                    ->retry(3, 1000)
                    ->post('https://api.dropboxapi.com/2/files/list_folder', [
                        'path' => $path,
                        'recursive' => $recursive,
                        'include_media_info' => false,
                        'include_deleted' => false,
                        'include_has_explicit_shared_members' => false,
                    ]);

                if (!$response->successful()) {
                    Log::error('Dropbox list_folder failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    throw new \RuntimeException('Failed to list Dropbox files: ' . $response->body());
                }
            }

            $data = $response->json();
            $entries = $data['entries'] ?? [];

            // Filter for files only (not folders)
            foreach ($entries as $entry) {
                if ($entry['.tag'] === 'file') {
                    $allFiles[] = [
                        'id' => $entry['id'],
                        'name' => $entry['name'],
                        'path' => $entry['path_lower'],
                        'size' => $entry['size'],
                        'modified_time' => $entry['server_modified'] ?? null,
                        'mime_type' => $this->getMimeType($entry['name']),
                        'hash' => $entry['content_hash'] ?? null,
                    ];
                }
            }

            $cursor = $data['has_more'] ? $data['cursor'] : null;
        } while ($cursor);

        return $allFiles;
    }

    /**
     * Continue listing files using cursor
     */
    private function listFolderContinue(string $cursor): \Illuminate\Http\Client\Response
    {
        return Http::withToken($this->accessToken)
            ->timeout(60)
            ->retry(3, 1000)
            ->post('https://api.dropboxapi.com/2/files/list_folder/continue', [
                'cursor' => $cursor,
            ]);
    }

    /**
     * Download file content from Dropbox
     */
    public function downloadFile(string $path): string
    {
        // Use Guzzle directly to avoid Laravel HTTP client adding body
        $client = new \GuzzleHttp\Client();
        
        try {
            $response = $client->request('POST', 'https://content.dropboxapi.com/2/files/download', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Dropbox-API-Arg' => json_encode(['path' => $path]),
                ],
                'timeout' => 120,
                // No body parameter at all - this is key!
            ]);

            return $response->getBody()->getContents();
            
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            
            Log::error('Dropbox file download failed', [
                'path' => $path,
                'status' => $e->getCode(),
                'error' => $errorBody,
            ]);
            
            throw new \RuntimeException('Failed to download Dropbox file: ' . $errorBody);
        }
    }

    /**
     * Get file metadata
     */
    public function getFileMetadata(string $path): array
    {
        $response = Http::withToken($this->accessToken)
            ->timeout(30)
            ->retry(3, 1000)
            ->post('https://api.dropboxapi.com/2/files/get_metadata', [
                'path' => $path,
            ]);

        if (!$response->successful()) {
            Log::error('Dropbox get_metadata failed', [
                'path' => $path,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException('Failed to get Dropbox file metadata');
        }

        return $response->json();
    }

    /**
     * Get temporary download link (alternative to direct download)
     */
    public function getTemporaryLink(string $path): string
    {
        $response = Http::withToken($this->accessToken)
            ->timeout(30)
            ->retry(3, 1000)
            ->post('https://api.dropboxapi.com/2/files/get_temporary_link', [
                'path' => $path,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to get temporary link');
        }

        return $response->json()['link'];
    }

    /**
     * Determine MIME type from filename
     */
    private function getMimeType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'html', 'htm' => 'text/html',
            'md' => 'text/markdown',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
            default => 'application/octet-stream',
        };
    }

    /**
     * Check if file type is supported for text extraction
     */
    public function isSupportedFileType(string $mimeType): bool
    {
        $supportedTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // DOCX
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // XLSX
            'application/vnd.openxmlformats-officedocument.presentationml.presentation', // PPTX
            'text/plain',
            'text/html',
            'text/markdown',
            'text/csv',
        ];

        return in_array($mimeType, $supportedTypes);
    }
}

