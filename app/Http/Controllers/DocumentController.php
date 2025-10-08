<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Chunk;
use App\Services\VectorStoreService;
use App\Services\FileUploadService;
use App\Services\DocumentExtractionService;
use App\Jobs\CreateChunksJob;
use App\Jobs\ReindexDocumentJob;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        
        $docs = Document::where('org_id', $orgId)
            ->with('connector')
            ->withCount('chunks')
            ->orderByDesc('created_at')
            ->paginate(50);
            
        // Format response
        $formatted = $docs->getCollection()->map(function($doc) {
            $connector = $doc->connector;
            $sourceType = 'Unknown';
            if ($connector) {
                $sourceType = match($connector->type) {
                    'google_drive' => 'Google Drive',
                    'slack' => 'Slack',
                    'notion' => 'Notion',
                    'dropbox' => 'Dropbox',
                    default => ucfirst(str_replace('_', ' ', $connector->type))
                };
            }
            
            return [
                'id' => $doc->id,
                'title' => $doc->title,
                'source' => $sourceType,
                'source_url' => $doc->s3_path ?: $doc->source_url, // Use Cloudinary URL if available, fallback to source_url
                'mime_type' => $doc->mime_type,
                'doc_type' => $doc->doc_type,
                'tags' => $doc->tags ?? [],
                'metadata' => $doc->metadata ?? null,
                'size' => $doc->size,
                'chunks_count' => $doc->chunks_count,
                'created_at' => $doc->created_at,
                'fetched_at' => $doc->fetched_at,
            ];
        });
        
        return response()->json([
            'data' => $formatted,
            'total' => $docs->total(),
            'current_page' => $docs->currentPage(),
            'per_page' => $docs->perPage(),
            'last_page' => $docs->lastPage(),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $doc = Document::where('org_id', $orgId)->where('id', $id)->first();
        if (!$doc) {
            return response()->json(['error' => 'Document not found'], 404);
        }
        return response()->json($doc);
    }

    public function chunks(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $doc = Document::where('org_id', $orgId)->where('id', $id)->first();
        if (!$doc) {
            return response()->json(['error' => 'Document not found'], 404);
        }
        $chunks = Chunk::where('document_id', $doc->id)
            ->orderBy('chunk_index')
            ->paginate(50);
        return response()->json($chunks);
    }

    public function destroy(Request $request, string $id, VectorStoreService $vector)
    {
        $orgId = $request->user()->org_id;
        if (($request->user()->role ?? 'user') !== 'admin') {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $doc = Document::where('org_id', $orgId)->where('id', $id)->first();
        if (!$doc) {
            return response()->json(['error' => 'Document not found'], 404);
        }
        $chunkIds = Chunk::where('document_id', $doc->id)->pluck('id')->all();

        if (!empty($chunkIds)) {
            try { $vector->delete($chunkIds); } catch (\Throwable $e) { /* ignore */ }
        }

        Chunk::where('document_id', $doc->id)->delete();
        $doc->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function upload(Request $request, FileUploadService $uploader, DocumentExtractionService $extractor)
    {
        $request->validate([
            'file' => 'required|file',
            'title' => 'nullable|string',
        ]);

        $user = $request->user();
        $orgId = $user->org_id;

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $tmpDir = storage_path('app/tmp_uploads');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . (\Illuminate\Support\Str::uuid() . '.' . $ext);
        @copy($file->getRealPath(), $tmpPath);

        $upload = $uploader->uploadRawPath($tmpPath, 'knowledgehub/uploads', $original);

        $doc = Document::create([
            'org_id' => $orgId,
            'connector_id' => null,
            'title' => $request->input('title') ?? ($file->getClientOriginalName() ?: 'Untitled'),
            'source_url' => null,
            'mime_type' => $mime,
            'sha256' => null,
            'size' => $file->getSize(),
            's3_path' => $upload['secure_url'] ?? null,
            'fetched_at' => now(),
        ]);

        $text = $extractor->extractText($tmpPath, $mime);

        CreateChunksJob::dispatch($doc->id, $orgId, $text);

        return response()->json(['document' => $doc, 'job' => [ 'type' => 'chunk+embed', 'status' => 'queued' ]], 201);
    }

    public function reindex(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $doc = Document::where('org_id', $orgId)->where('id', $id)->first();
        if (!$doc) return response()->json(['error' => 'Document not found'], 404);
        ReindexDocumentJob::dispatch($doc->id, $orgId);
        return response()->json(['status' => 'queued']);
    }
}


