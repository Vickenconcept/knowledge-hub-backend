<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleDriveService
{
    public function listRecentFiles(array $tokens, int $pageSize = 10): array
    {
        $accessToken = $tokens['access_token'] ?? null;
        if (!$accessToken) return [];

        $resp = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/drive/v3/files', [
                'pageSize' => $pageSize,
                'fields' => 'files(id,name,mimeType,modifiedTime,webContentLink,size)',
                'orderBy' => 'modifiedTime desc',
                'q' => "trashed = false",
            ]);
        if (!$resp->successful()) return [];
        $files = $resp->json('files') ?? [];
        return array_map(function ($f) {
            return [
                'id' => $f['id'] ?? null,
                'title' => $f['name'] ?? null,
                'mime_type' => $f['mimeType'] ?? null,
                'size' => isset($f['size']) ? (int) $f['size'] : null,
            ];
        }, $files);
    }

    public function downloadFile(array $tokens, string $fileId, string $destPath): bool
    {
        $accessToken = $tokens['access_token'] ?? null;
        if (!$accessToken) return false;
        $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($fileId);
        $resp = Http::withToken($accessToken)
            ->withHeaders(['Accept' => 'application/octet-stream'])
            ->get($url, ['alt' => 'media']);
        if (!$resp->successful()) return false;
        @file_put_contents($destPath, $resp->body());
        return is_readable($destPath);
    }
}


