<?php

namespace App\Http\Controllers\Connectors;

use App\Models\Connector;
use App\Models\Document;
use App\Services\FileUploadService;
use App\Services\DocumentExtractionService;
use App\Jobs\CreateChunksJob;
use App\Jobs\IngestConnectorJob;
use App\Http\Traits\StandardizedErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

/**
 * Manual Upload Connector Controller
 * 
 * Handles manual file uploads and creates a connector for the organization
 */
class ManualUploadController extends BaseConnectorController
{
    use StandardizedErrorResponse;
    protected function getConnectorType(): string
    {
        return 'manual_upload';
    }
    
    protected function getConnectorLabel(): string
    {
        return 'Manual Upload';
    }
    
    /**
     * Create or get manual upload connector for organization
     */
    public function createConnector(Request $request)
    {
        $orgId = $request->user()->org_id;
        
        // Check if manual upload connector already exists
        $existingConnector = Connector::where('org_id', $orgId)
            ->where('type', 'manual_upload')
            ->first();

        if ($existingConnector) {
            return response()->json([
                'connector' => $existingConnector,
                'message' => 'Manual upload connector already exists'
            ]);
        }

        // Create new manual upload connector
        $connector = Connector::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'type' => 'manual_upload',
            'label' => 'Manual Upload',
            'status' => 'connected', // Manual upload is always "connected"
            'metadata' => [
                'created_at' => now()->toISOString(),
                'upload_count' => 0,
                'last_upload_at' => null
            ]
        ]);

        Log::info('Manual upload connector created', [
            'connector_id' => $connector->id,
            'org_id' => $orgId
        ]);

        return response()->json([
            'connector' => $connector,
            'message' => 'Manual upload connector created successfully'
        ]);
    }
    
    /**
     * Handle bulk file upload
     */
    public function uploadFiles(Request $request, FileUploadService $uploader, DocumentExtractionService $extractor)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:50', // Max 50 files per upload
            'files.*' => 'required|file|max:50000', // Max 50MB per file
        ]);

        $user = $request->user();
        $orgId = $user->org_id;
        
        // Get or create manual upload connector
        $connector = Connector::where('org_id', $orgId)
            ->where('type', 'manual_upload')
            ->first();

        if (!$connector) {
            // Create connector if it doesn't exist
            $connector = Connector::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'type' => 'manual_upload',
                'label' => 'Manual Upload',
                'status' => 'connected',
                'metadata' => [
                    'created_at' => now()->toISOString(),
                    'upload_count' => 0,
                    'last_upload_at' => null
                ]
            ]);
        }

        // CHECK DOCUMENT LIMIT BEFORE UPLOAD
        $docLimit = \App\Services\UsageLimitService::canAddDocument($orgId);
        if (!$docLimit['allowed']) {
            return $this->usageLimitErrorResponse(
                $docLimit['reason'],
                'max_documents',
                $docLimit['current_usage'],
                $docLimit['limit'],
                $docLimit['tier']
            );
        }

        $uploadedFiles = [];
        $errors = [];
        $totalSize = 0;

        foreach ($request->file('files') as $index => $file) {
            try {
                $mime = $file->getMimeType();
                $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
                $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $fileSize = $file->getSize();
                
                // Check individual file size limit
                if ($fileSize > 50 * 1024 * 1024) { // 50MB
                    $errors[] = "File '{$file->getClientOriginalName()}' is too large (max 50MB)";
                    continue;
                }
                
                $totalSize += $fileSize;
                
                // Create temporary file
                $tmpDir = storage_path('app/tmp_uploads');
                if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
                $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . (\Illuminate\Support\Str::uuid() . '.' . $ext);
                @copy($file->getRealPath(), $tmpPath);

                // Upload to cloud storage
                $upload = $uploader->uploadRawPath($tmpPath, 'knowledgehub/uploads', $original);

                // Create document record
                $document = Document::create([
                    'org_id' => $orgId,
                    'connector_id' => $connector->id,
                    'title' => $file->getClientOriginalName() ?: 'Untitled',
                    'source_url' => null,
                    'mime_type' => $mime,
                    'sha256' => null,
                    'size' => $fileSize,
                    's3_path' => $upload['secure_url'] ?? null,
                    'fetched_at' => now(),
                    'metadata' => [
                        'upload_type' => 'manual',
                        'uploaded_by' => $user->id,
                        'uploaded_at' => now()->toISOString(),
                        'original_filename' => $file->getClientOriginalName(),
                        'file_extension' => $ext,
                        'tmp_path' => $tmpPath, // Keep temp path for ingestion job
                    ]
                ]);

                // Defer processing to a single ingestion job (like Google Drive)
                // Don't delete temp file yet; ingestion job will handle it

                $uploadedFiles[] = [
                    'id' => $document->id,
                    'title' => $document->title,
                    'size' => $fileSize,
                    'mime_type' => $mime,
                    'status' => 'queued'
                ];

            } catch (\Exception $e) {
                Log::error('Manual upload failed for file', [
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $errors[] = "Failed to upload '{$file->getClientOriginalName()}': " . $e->getMessage();
            }
        }

        // Update connector metadata
        $metadata = $connector->metadata ?? [];
        $metadata['upload_count'] = ($metadata['upload_count'] ?? 0) + count($uploadedFiles);
        $metadata['last_upload_at'] = now()->toISOString();
        $metadata['total_uploads'] = ($metadata['total_uploads'] ?? 0) + count($uploadedFiles);
        $connector->metadata = $metadata;
        $connector->save();

        // Dispatch a single ingestion job for the connector (mirrors Google Drive pattern)
        IngestConnectorJob::dispatch($connector->id, $orgId)->onQueue('default');

        Log::info('Manual upload completed', [
            'connector_id' => $connector->id,
            'org_id' => $orgId,
            'files_uploaded' => count($uploadedFiles),
            'errors' => count($errors),
            'total_size' => $totalSize
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Files uploaded successfully',
            'uploaded_files' => $uploadedFiles,
            'errors' => $errors,
            'connector_id' => $connector->id,
            'total_files' => count($uploadedFiles),
            'total_size' => $totalSize,
            'processing_status' => 'queued'
        ]);
    }
    
    /**
     * Get upload history for manual upload connector
     */
    public function getUploadHistory(Request $request)
    {
        $orgId = $request->user()->org_id;
        
        $connector = Connector::where('org_id', $orgId)
            ->where('type', 'manual_upload')
            ->first();

        if (!$connector) {
            return response()->json([
                'uploads' => [],
                'message' => 'No manual upload connector found'
            ]);
        }

        $documents = Document::where('connector_id', $connector->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'uploads' => $documents->items(),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total()
            ]
        ]);
    }
    
    /**
     * Delete uploaded file
     */
    public function deleteUpload(Request $request, string $documentId)
    {
        $orgId = $request->user()->org_id;
        
        $document = Document::where('id', $documentId)
            ->where('org_id', $orgId)
            ->whereHas('connector', function($query) {
                $query->where('type', 'manual_upload');
            })
            ->first();

        if (!$document) {
            return $this->notFoundErrorResponse('Document');
        }

        // Delete from vector store
        $chunkIds = \App\Models\Chunk::where('document_id', $document->id)->pluck('id')->all();
        if (!empty($chunkIds)) {
            try {
                $vectorStore = new \App\Services\VectorStoreService();
                $vectorStore->delete($chunkIds);
            } catch (\Exception $e) {
                Log::warning('Failed to delete vectors for manual upload', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Delete chunks and document
        \App\Models\Chunk::where('document_id', $document->id)->delete();
        $document->delete();

        Log::info('Manual upload document deleted', [
            'document_id' => $documentId,
            'org_id' => $orgId
        ]);

        return response()->json([
            'message' => 'Upload deleted successfully'
        ]);
    }
}
