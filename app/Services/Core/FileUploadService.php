<?php

namespace App\Services\Core;

use Cloudinary\Cloudinary;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FileUploadService
{
    protected Cloudinary $cloudinary;

    public function __construct()
    {
        $cloudinaryUrl = (string) config('services.cloudinary.url', '');
        if ($cloudinaryUrl !== '') {
            $this->cloudinary = new Cloudinary($cloudinaryUrl);
            return;
        }

        $cloudName = (string) config('services.cloudinary.cloud_name', '');
        $apiKey = (string) config('services.cloudinary.api_key', '');
        $apiSecret = (string) config('services.cloudinary.api_secret', '');

        if ($cloudName !== '' && $apiKey !== '' && $apiSecret !== '') {
            $this->cloudinary = new Cloudinary([
                'cloud' => ['cloud_name' => $cloudName],
                'url' => ['secure' => true],
                'api' => [
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                ],
            ]);
            return;
        }

        throw new RuntimeException(
            'Cloudinary is not configured. Set CLOUDINARY_URL or CLOUDINARY_CLOUD_NAME/CLOUDINARY_API_KEY/CLOUDINARY_API_SECRET.'
        );
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

    /**
     * Best-effort Cloudinary asset deletion (raw by default).
     * Returns Cloudinary response (e.g. ['result' => 'ok'|'not found'|...]).
     */
    public function destroyByPublicId(?string $publicId, string $resourceType = 'raw'): array
    {
        if (empty($publicId)) {
            return ['skipped' => true, 'reason' => 'empty_public_id'];
        }

        try {
            $res = $this->cloudinary->uploadApi()->destroy($publicId, [
                'resource_type' => $resourceType,
                'invalidate' => true,
            ]);

            // Cloudinary SDK may return ApiResponse (object) or array.
            if (is_array($res)) {
                return $res;
            }

            if (is_object($res)) {
                if (method_exists($res, 'getArrayCopy')) {
                    /** @var array $arr */
                    $arr = $res->getArrayCopy();
                    return $arr;
                }
                if ($res instanceof \JsonSerializable) {
                    $json = $res->jsonSerialize();
                    return is_array($json) ? $json : ['result' => $json];
                }
                if (method_exists($res, 'toArray')) {
                    $arr = $res->toArray();
                    return is_array($arr) ? $arr : ['result' => $arr];
                }
            }

            return ['result' => (string) $res];
        } catch (\Throwable $e) {
            Log::warning('Cloudinary destroy failed', [
                'public_id' => $publicId,
                'resource_type' => $resourceType,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Extract Cloudinary public_id from a Cloudinary URL.
     * Supports typical raw URLs: .../<resource_type>/upload/.../v123/<public_id>.<ext>
     */
    public function extractPublicIdFromUrl(?string $url): ?string
    {
        if (empty($url)) return null;

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !str_contains($host, 'res.cloudinary.com')) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        if ($path === '') return null;

        $path = ltrim($path, '/');
        $parts = explode('/upload/', $path, 2);
        if (count($parts) < 2) return null;

        $afterUpload = $parts[1]; // may include transformations + /v123/ + public path

        // Prefer stripping everything up to the last /v{digits}/ segment when present
        if (preg_match_all('#/v\d+/#', $afterUpload, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            $pos = $last[1] + strlen($last[0]);
            $afterUpload = substr($afterUpload, $pos);
        } else {
            // No version segment; assume the remainder is public path
            $afterUpload = ltrim($afterUpload, '/');
        }

        $afterUpload = trim($afterUpload, '/');
        if ($afterUpload === '') return null;

        // Strip query-like suffixes (shouldn't be present in path, but be safe)
        $afterUpload = explode('?', $afterUpload, 2)[0];
        $afterUpload = explode('#', $afterUpload, 2)[0];

        // Strip file extension (Cloudinary public_id typically excludes extension)
        $afterUpload = preg_replace('#\.[^./]+$#', '', $afterUpload);

        $publicId = urldecode($afterUpload);
        return $publicId !== '' ? $publicId : null;
    }

    /**
     * Best-effort delete by Cloudinary URL (parses public_id from URL).
     */
    public function destroyFromUrl(?string $url, string $resourceType = 'raw'): array
    {
        $publicId = $this->extractPublicIdFromUrl($url);
        if (empty($publicId)) {
            return ['skipped' => true, 'reason' => 'no_public_id_from_url'];
        }
        return $this->destroyByPublicId($publicId, $resourceType);
    }
}


