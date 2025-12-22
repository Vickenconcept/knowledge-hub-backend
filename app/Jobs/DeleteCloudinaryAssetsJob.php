<?php

namespace App\Jobs;

use App\Services\FileUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteCloudinaryAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Keep this bounded so it doesn't run forever on big connectors.
     */
    public int $timeout = 600;
    public int $tries = 3;

    /**
     * @param array<int, array{document_id?:string, public_id?:string|null, url?:string|null, resource_type?:string|null}> $targets
     */
    public function __construct(public array $targets)
    {
    }

    public function handle(FileUploadService $uploader): void
    {
        $ok = 0;
        $notFound = 0;
        $skipped = 0;
        $errors = 0;

        Log::info('Cloudinary cleanup job started', [
            'count' => count($this->targets),
        ]);

        foreach ($this->targets as $t) {
            $publicId = $t['public_id'] ?? null;
            $url = $t['url'] ?? null;
            $resourceType = $t['resource_type'] ?? 'raw';

            $res = !empty($publicId)
                ? $uploader->destroyByPublicId($publicId, $resourceType)
                : $uploader->destroyFromUrl($url, $resourceType);

            $result = $res['result'] ?? null;
            if (!empty($res['skipped'])) {
                $skipped++;
                continue;
            }

            if ($result === 'ok') {
                $ok++;
            } elseif ($result === 'not found') {
                $notFound++;
            } elseif (!empty($res['error'])) {
                $errors++;
                Log::warning('Cloudinary cleanup failed for asset', [
                    'document_id' => $t['document_id'] ?? null,
                    'public_id' => $publicId,
                    'url' => $url,
                    'error' => $res['error'],
                ]);
            } else {
                // Unknown response - still count as error for visibility
                $errors++;
                Log::warning('Cloudinary cleanup returned unexpected response', [
                    'document_id' => $t['document_id'] ?? null,
                    'public_id' => $publicId,
                    'url' => $url,
                    'response' => $res,
                ]);
            }
        }

        Log::info('Cloudinary cleanup job finished', [
            'count' => count($this->targets),
            'ok' => $ok,
            'not_found' => $notFound,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }
}


