<?php

namespace App\Http\Controllers\Connectors;

use App\Models\Connector;
use App\Models\Document;
use App\Services\FileUploadService;
use App\Services\DocumentExtractionService;
use App\Services\DocumentClassificationService;
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
     * Create both organization and personal manual upload connectors at once
     * Smart logic: Only creates what's missing for the organization
     */
    public function createAllConnectors(Request $request)
    {
        $userId = $request->user()->id;
        $orgId = $request->user()->org_id;
        
        \Log::info('=== MANUAL UPLOAD SMART CREATE REQUEST ===', [
            'user_id' => $userId,
            'org_id' => $orgId
        ]);

        $createdConnectors = [];
        $existingConnectors = [];
        $skippedConnectors = [];

        // Check if organization Manual Upload connector exists
        $orgConnector = Connector::where('org_id', $orgId)
            ->where('type', 'manual_upload')
            ->where('connection_scope', 'organization')
            ->first();

        if (!$orgConnector) {
            // Create organization connector (first user in org)
            $orgConnector = Connector::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'type' => 'manual_upload',
                'label' => 'Manual Upload',
                'connection_scope' => 'organization',
                'workspace_name' => null,
                'status' => 'connected',
                'metadata' => [
                    'created_at' => now()->toISOString(),
                    'upload_count' => 0,
                    'last_upload_at' => null
                ]
            ]);
            $createdConnectors[] = $orgConnector;
            \Log::info('Created organization Manual Upload connector (first user)', [
                'connector_id' => $orgConnector->id
            ]);
        } else {
            $existingConnectors[] = $orgConnector;
            \Log::info('Organization Manual Upload connector already exists', [
                'connector_id' => $orgConnector->id
            ]);
        }

        // Check if personal Manual Upload connector exists for this user
        $personalConnector = Connector::where('org_id', $orgId)
            ->where('type', 'manual_upload')
            ->where('connection_scope', 'personal')
            ->whereHas('userPermissions', function($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->first();

        if (!$personalConnector) {
            // Create personal connector for this user
            $personalConnector = Connector::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'type' => 'manual_upload',
                'label' => 'Manual Upload',
                'connection_scope' => 'personal',
                'workspace_name' => 'My Personal Uploads',
                'status' => 'connected',
                'metadata' => [
                    'created_at' => now()->toISOString(),
                    'upload_count' => 0,
                    'last_upload_at' => null
                ]
            ]);

            // Create user permission for personal connector
            \App\Models\UserConnectorPermission::create([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'connector_id' => $personalConnector->id,
                'permission_level' => 'admin',
            ]);

            $createdConnectors[] = $personalConnector;
            \Log::info('Created personal Manual Upload connector for user', [
                'connector_id' => $personalConnector->id,
                'user_id' => $userId
            ]);
        } else {
            $existingConnectors[] = $personalConnector;
            \Log::info('Personal Manual Upload connector already exists for user', [
                'connector_id' => $personalConnector->id,
                'user_id' => $userId
            ]);
        }

        \Log::info('=== MANUAL UPLOAD SMART CREATE SUCCESS ===', [
            'created_count' => count($createdConnectors),
            'existing_count' => count($existingConnectors),
            'user_id' => $userId
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Manual upload connectors processed successfully',
            'created_connectors' => $createdConnectors,
            'existing_connectors' => $existingConnectors,
            'total_connectors' => count($createdConnectors) + count($existingConnectors),
            'user_id' => $userId,
            'org_id' => $orgId
        ]);
    }

    /**
     * Create or get manual upload connector for organization or personal
     */
    public function createConnector(Request $request)
    {
        $validated = $request->validate([
            'connection_scope' => 'nullable|string|in:organization,personal',
            'workspace_name' => 'nullable|string|max:255',
            'workspace_id' => 'nullable|string|max:255',
            'is_primary' => 'nullable|boolean',
            'workspace_metadata' => 'nullable|array',
        ]);

        $userId = $request->user()->id;
        $orgId = $request->user()->org_id;
        $connectionScope = $validated['connection_scope'] ?? 'organization';
        $workspaceName = $validated['workspace_name'] ?? null;
        
        \Log::info('=== MANUAL UPLOAD CONNECTOR CREATE REQUEST ===', [
            'user_id' => $userId,
            'org_id' => $orgId,
            'connection_scope' => $connectionScope,
            'workspace_name' => $workspaceName,
            'request_data' => $request->all()
        ]);

        // Check if manual upload connector already exists for this scope
        $query = Connector::where('org_id', $orgId)
            ->where('type', 'manual_upload')
            ->where('connection_scope', $connectionScope);
            
        // For personal scope, also check workspace name AND user permissions
        if ($connectionScope === 'personal') {
            if ($workspaceName) {
                $query->where('workspace_name', $workspaceName);
            }
            // CRITICAL: Check user permissions for personal connectors
            $query->whereHas('userPermissions', function($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }
        
        $existingConnector = $query->first();

        if ($existingConnector) {
            \Log::info('Manual upload connector already exists', [
                'connector_id' => $existingConnector->id,
                'connection_scope' => $connectionScope,
                'workspace_name' => $workspaceName
            ]);
            
            return response()->json([
                'success' => true,
                'connector' => $existingConnector,
                'message' => 'Manual upload connector already exists'
            ]);
        }

        // Create new manual upload connector with workspace info
        $connector = Connector::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'type' => 'manual_upload',
            'label' => 'Manual Upload',
            'connection_scope' => $connectionScope,
            'workspace_name' => $workspaceName,
            'workspace_id' => $validated['workspace_id'] ?? null,
            'is_primary' => $validated['is_primary'] ?? false,
            'workspace_metadata' => $validated['workspace_metadata'] ?? null,
            'status' => 'connected', // Manual upload is always "connected"
            'metadata' => [
                'created_at' => now()->toISOString(),
                'upload_count' => 0,
                'last_upload_at' => null
            ]
        ]);

        // For personal connectors, create user permission
        if ($connectionScope === 'personal') {
            \App\Models\UserConnectorPermission::create([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'connector_id' => $connector->id,
                'permission_level' => 'admin', // Creator gets admin access
            ]);
            
            \Log::info('Created user permission for personal manual upload connector', [
                'connector_id' => $connector->id,
                'user_id' => $userId,
                'permission_level' => 'admin'
            ]);
        }

        \Log::info('=== MANUAL UPLOAD CONNECTOR CREATE SUCCESS ===', [
            'connector_id' => $connector->id,
            'org_id' => $orgId,
            'connection_scope' => $connectionScope,
            'workspace_name' => $workspaceName
        ]);

        return response()->json([
            'success' => true,
            'connector' => $connector,
            'message' => 'Manual upload connector created successfully'
        ]);
    }
    
    /**
     * Handle bulk file upload
     */
    public function uploadFiles(Request $request, FileUploadService $uploader, DocumentExtractionService $extractor, DocumentClassificationService $classifier)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:50', // Max 50 files per upload
            'files.*' => 'required|file|max:50000', // Max 50MB per file
        ]);

        $user = $request->user();
        $orgId = $user->org_id;
        $userId = $user->id;
        
        // Get workspace info from request
        $connectionScope = $request->input('connection_scope', 'organization');
        $workspaceName = $request->input('workspace_name');
        
        \Log::info('=== MANUAL UPLOAD FILES REQUEST ===', [
            'user_id' => $userId,
            'org_id' => $orgId,
            'connection_scope' => $connectionScope,
            'workspace_name' => $workspaceName,
            'files_count' => count($request->file('files', []))
        ]);
        
        // Get or create manual upload connector with workspace awareness
        $query = Connector::where('org_id', $orgId)
            ->where('type', 'manual_upload')
            ->where('connection_scope', $connectionScope);
            
        // For personal scope, also check workspace name AND user permissions
        if ($connectionScope === 'personal') {
            if ($workspaceName) {
                $query->where('workspace_name', $workspaceName);
            }
            // CRITICAL: Check user permissions for personal connectors
            $query->whereHas('userPermissions', function($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }
        
        $connector = $query->first();

        if (!$connector) {
            // Create connector if it doesn't exist with workspace info
            $connector = Connector::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'type' => 'manual_upload',
                'label' => 'Manual Upload',
                'connection_scope' => $connectionScope,
                'workspace_name' => $workspaceName,
                'status' => 'connected',
                'metadata' => [
                    'created_at' => now()->toISOString(),
                    'upload_count' => 0,
                    'last_upload_at' => null
                ]
            ]);
            
            // For personal connectors, create user permission
            if ($connectionScope === 'personal') {
                \App\Models\UserConnectorPermission::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'connector_id' => $connector->id,
                    'permission_level' => 'admin',
                ]);
            }
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

                // Extract text for classification and metadata extraction
                $extractedText = '';
                try {
                    $extractedText = $extractor->extractText($tmpPath, $mime);
                    Log::info('Text extracted for classification', [
                        'filename' => $file->getClientOriginalName(),
                        'text_length' => strlen($extractedText)
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to extract text for classification', [
                        'filename' => $file->getClientOriginalName(),
                        'error' => $e->getMessage()
                    ]);
                }

                // Classify document and extract metadata
                $classification = $classifier->classifyDocument(
                    $extractedText,
                    $file->getClientOriginalName(),
                    $mime
                );

                // Create document record with extracted metadata
                $document = Document::create([
                    'org_id' => $orgId,
                    'connector_id' => $connector->id,
                    'user_id' => $userId, // Track which user uploaded this document
                    'title' => $file->getClientOriginalName() ?: 'Untitled',
                    'source_url' => null,
                    'mime_type' => $mime,
                    'sha256' => null,
                    'size' => $fileSize,
                    's3_path' => $upload['secure_url'] ?? null,
                    'fetched_at' => now(),
                    'doc_type' => $classification['doc_type'],
                    'tags' => $classification['tags'],
                    'metadata' => array_merge([
                        'upload_type' => 'manual',
                        'uploaded_by' => $user->id,
                        'uploaded_at' => now()->toISOString(),
                        'original_filename' => $file->getClientOriginalName(),
                        'file_extension' => $ext,
                        'tmp_path' => $tmpPath, // Keep temp path for ingestion job
                        'extracted_text' => $extractedText, // Store extracted text for reuse
                    ], $classification['metadata'])
                ]);

                // Defer processing to a single ingestion job (like Google Drive)
                // Don't delete temp file yet; ingestion job will handle it

                $uploadedFiles[] = [
                    'id' => $document->id,
                    'title' => $document->title,
                    'size' => $fileSize,
                    'mime_type' => $mime,
                    'doc_type' => $classification['doc_type'],
                    'tags' => $classification['tags'],
                    'metadata' => $classification['metadata'],
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
