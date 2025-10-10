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
use Illuminate\Support\Facades\Log;

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
        $doc = Document::where('org_id', $orgId)->where('id', $id)->first();
        
        if (!$doc) {
            return response()->json(['error' => 'Document not found'], 404);
        }
        
        Log::info('Deleting document', [
            'document_id' => $doc->id,
            'title' => $doc->title,
            'user_id' => $request->user()->id,
        ]);
        
        // Get all chunk IDs for vector deletion
        $chunkIds = Chunk::where('document_id', $doc->id)->pluck('id')->all();

        // Delete from vector store (Pinecone)
        if (!empty($chunkIds)) {
            try { 
                $vector->delete($chunkIds); 
                Log::info('Deleted vectors from Pinecone', [
                    'chunk_count' => count($chunkIds),
                ]);
            } catch (\Throwable $e) { 
                Log::warning('Failed to delete vectors from Pinecone', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Delete chunks from database
        Chunk::where('document_id', $doc->id)->delete();
        
        // Delete document
        $doc->delete();
        
        Log::info('Document deleted successfully', [
            'document_id' => $id,
        ]);
        
        return response()->json([
            'message' => 'Document deleted successfully',
            'deleted_chunks' => count($chunkIds),
        ]);
    }
    
    public function bulkDestroy(Request $request, VectorStoreService $vector)
    {
        $request->validate([
            'document_ids' => 'required|array|min:1',
            'document_ids.*' => 'required|string',
        ]);
        
        $orgId = $request->user()->org_id;
        $documentIds = $request->input('document_ids');
        
        Log::info('Bulk deleting documents', [
            'count' => count($documentIds),
            'user_id' => $request->user()->id,
        ]);
        
        $deletedCount = 0;
        $totalChunksDeleted = 0;
        
        foreach ($documentIds as $documentId) {
            $doc = Document::where('org_id', $orgId)->where('id', $documentId)->first();
            
            if (!$doc) {
                continue; // Skip if not found or not owned by org
            }
            
            // Get all chunk IDs for vector deletion
            $chunkIds = Chunk::where('document_id', $doc->id)->pluck('id')->all();

            // Delete from vector store
            if (!empty($chunkIds)) {
                try { 
                    $vector->delete($chunkIds); 
                    $totalChunksDeleted += count($chunkIds);
                } catch (\Throwable $e) { 
                    Log::warning('Failed to delete vectors for document', [
                        'document_id' => $doc->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Delete chunks and document
            Chunk::where('document_id', $doc->id)->delete();
            $doc->delete();
            $deletedCount++;
        }
        
        Log::info('Bulk delete completed', [
            'deleted_documents' => $deletedCount,
            'deleted_chunks' => $totalChunksDeleted,
        ]);
        
        return response()->json([
            'message' => "{$deletedCount} document(s) deleted successfully",
            'deleted_count' => $deletedCount,
            'deleted_chunks' => $totalChunksDeleted,
        ]);
    }

    public function upload(Request $request, FileUploadService $uploader, DocumentExtractionService $extractor)
    {
        $request->validate([
            'file' => 'required|file',
            'title' => 'nullable|string',
        ]);

        $user = $request->user();
        $orgId = $user->org_id;
        
        // CHECK DOCUMENT LIMIT BEFORE UPLOAD
        $docLimit = \App\Services\UsageLimitService::canAddDocument($orgId);
        if (!$docLimit['allowed']) {
            return response()->json([
                'error' => 'Document limit exceeded',
                'message' => $docLimit['reason'],
                'limit_type' => 'max_documents',
                'current_usage' => $docLimit['current_usage'],
                'limit' => $docLimit['limit'],
                'tier' => $docLimit['tier'],
                'upgrade_required' => true,
            ], 429);
        }

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


