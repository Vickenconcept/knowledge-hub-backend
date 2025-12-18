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
        $userId = $request->user()->id;
        
        // Smart filtering: Show user's personal documents + organization documents
        $docs = Document::where('org_id', $orgId)
            ->where(function($query) use ($userId) {
                $query->where(function($q) use ($userId) {
                    // User's personal documents (uploaded by this user with personal scope)
                    $q->where('user_id', $userId)
                      ->where('source_scope', 'personal');
                })
                ->orWhere(function($q) {
                    // Organization documents (accessible to all team members)
                    $q->where('source_scope', 'organization');
                });
            })
            ->where(function($query) {
                $query->where('doc_type', '!=', 'guide')
                      ->orWhereNull('doc_type');
            })
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
            } else {
                // Check if it's a system document
                $metadata = is_string($doc->metadata) ? json_decode($doc->metadata, true) : $doc->metadata;
                $isSystemDoc = $metadata['is_system_document'] ?? false;
                
                if ($isSystemDoc || $doc->doc_type === 'guide') {
                    $sourceType = 'KHub Guide';
                } elseif ($doc->s3_path) {
                    $sourceType = 'Uploaded';
                }
            }
            
            return [
                'id' => $doc->id,
                'title' => $doc->title,
                'source' => $sourceType,
                'source_url' => $doc->s3_path ?: $doc->source_url,
                'mime_type' => $doc->mime_type,
                'doc_type' => $doc->doc_type,
                'tags' => $doc->tags ?? [],
                'metadata' => $doc->metadata ?? null,
                'size' => $doc->size,
                'chunks_count' => $doc->chunks_count,
                'created_at' => $doc->created_at,
                'fetched_at' => $doc->fetched_at,
                'scope' => $doc->source_scope ?? 'unknown',
                'workspace_name' => $connector ? $connector->workspace_name : null,
                'is_personal' => $doc->source_scope === 'personal',
                'is_organization' => $doc->source_scope === 'organization',
                'scope_label' => $doc->source_scope === 'personal' ? 'Personal' : ($doc->source_scope === 'organization' ? 'Organization' : 'Unknown'),
                'scope_icon' => $doc->source_scope === 'personal' ? '👤' : ($doc->source_scope === 'organization' ? '🏢' : '❓'),
                'uploaded_by' => $doc->user_id,
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $formatted,
            'pagination' => [
                'current_page' => $docs->currentPage(),
                'last_page' => $docs->lastPage(),
                'per_page' => $docs->perPage(),
                'total' => $docs->total(),
                'from' => $docs->firstItem(),
                'to' => $docs->lastItem(),
            ]
        ]);
    }

    public function show(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;
        
        // Apply same security filtering as index method
        $doc = Document::where('org_id', $orgId)
            ->where('id', $id)
            ->where(function($query) use ($userId) {
                $query->where(function($q) use ($userId) {
                    // User's personal documents (uploaded by this user with personal scope)
                    $q->where('user_id', $userId)
                      ->where('source_scope', 'personal');
                })
                ->orWhere(function($q) {
                    // Organization documents (accessible to all team members)
                    $q->where('source_scope', 'organization');
                });
            })
            ->with('connector')
            ->first();
            
        if (!$doc) {
            return response()->json(['error' => 'Document not found or access denied'], 404);
        }
        return response()->json($doc);
    }

    public function chunks(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;
        
        // Apply same security filtering as index method
        $doc = Document::where('org_id', $orgId)
            ->where('id', $id)
            ->where(function($query) use ($userId) {
                $query->where(function($q) use ($userId) {
                    // User's personal documents (uploaded by this user with personal scope)
                    $q->where('user_id', $userId)
                      ->where('source_scope', 'personal');
                })
                ->orWhere(function($q) {
                    // Organization documents (accessible to all team members)
                    $q->where('source_scope', 'organization');
                });
            })
            ->first();
            
        if (!$doc) {
            return response()->json(['error' => 'Document not found or access denied'], 404);
        }
        $chunks = Chunk::where('document_id', $doc->id)
            ->select('id', 'document_id', 'org_id', 'chunk_index', 'text', 'char_start', 'char_end', 'token_count', 'created_at', 'updated_at')
            ->orderBy('chunk_index')
            ->paginate(50);
        
        // Format pagination response properly (excluding embedding binary data)
        return response()->json([
            'success' => true,
            'data' => $chunks->items(),
            'pagination' => [
                'current_page' => $chunks->currentPage(),
                'last_page' => $chunks->lastPage(),
                'per_page' => $chunks->perPage(),
                'total' => $chunks->total(),
                'from' => $chunks->firstItem(),
                'to' => $chunks->lastItem(),
            ]
        ]);
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

        // Best-effort: delete file from Cloudinary if this document has a Cloudinary URL
        try {
            $uploader = new \App\Services\FileUploadService();
            $cloudinaryPublicId = $doc->metadata['cloudinary_public_id'] ?? null;
            $cloudinaryResourceType = $doc->metadata['cloudinary_resource_type'] ?? 'raw';

            $cloudinaryRes = !empty($cloudinaryPublicId)
                ? $uploader->destroyByPublicId($cloudinaryPublicId, $cloudinaryResourceType)
                : $uploader->destroyFromUrl($doc->s3_path, $cloudinaryResourceType);

            Log::info('Cloudinary delete attempted for document', [
                'document_id' => $doc->id,
                'public_id' => $cloudinaryPublicId,
                'cloudinary_result' => $cloudinaryRes['result'] ?? ($cloudinaryRes['skipped'] ?? false ? 'skipped' : null),
                'cloudinary_response' => $cloudinaryRes,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Cloudinary delete failed for document (continuing DB delete)', [
                'document_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Get all chunk IDs for vector deletion
        $chunkIds = Chunk::where('document_id', $doc->id)->pluck('id')->all();

        // Delete from vector store (local database)
        if (!empty($chunkIds)) {
            try { 
                $vector->delete($chunkIds); 
                Log::info('Deleted vectors from database', [
                    'chunk_count' => count($chunkIds),
                ]);
            } catch (\Throwable $e) { 
                Log::warning('Failed to delete vectors from database', [
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

        // Create once (avoid recreating Cloudinary client per document)
        $uploader = new \App\Services\FileUploadService();
        
        foreach ($documentIds as $documentId) {
            $doc = Document::where('org_id', $orgId)->where('id', $documentId)->first();
            
            if (!$doc) {
                continue; // Skip if not found or not owned by org
            }

            // Best-effort: delete file from Cloudinary if this document has a Cloudinary URL
            try {
                $cloudinaryPublicId = $doc->metadata['cloudinary_public_id'] ?? null;
                $cloudinaryResourceType = $doc->metadata['cloudinary_resource_type'] ?? 'raw';

                if (!empty($cloudinaryPublicId)) {
                    $uploader->destroyByPublicId($cloudinaryPublicId, $cloudinaryResourceType);
                } else {
                    $uploader->destroyFromUrl($doc->s3_path, $cloudinaryResourceType);
                }
            } catch (\Throwable $e) {
                Log::warning('Cloudinary delete failed for document in bulk destroy (continuing)', [
                    'document_id' => $doc->id,
                    'error' => $e->getMessage(),
                ]);
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
            'source_scope' => 'nullable|in:personal,organization',
        ]);

        $user = $request->user();
        $orgId = $user->org_id;
        $sourceScope = $request->input('source_scope', 'personal'); // Default to personal if not specified
        
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
            'user_id' => $user->id, // Track which user uploaded this document
            'title' => $request->input('title') ?? ($file->getClientOriginalName() ?: 'Untitled'),
            'source_url' => null,
            'mime_type' => $mime,
            'sha256' => null,
            'size' => $file->getSize(),
            's3_path' => $upload['secure_url'] ?? null,
            'metadata' => array_filter([
                'cloudinary_public_id' => $upload['public_id'] ?? null,
                'cloudinary_resource_type' => 'raw',
            ]),
            'fetched_at' => now(),
            'source_scope' => $sourceScope, // Use the scope from the request
        ]);
        
        Log::info('📄 DIRECT UPLOAD DOCUMENT CREATED IN DATABASE', [
            'document_id' => $doc->id,
            'title' => $doc->title,
            'connector_id' => null,
            'connector_scope' => 'no_connector',
            'document_source_scope' => $doc->source_scope,
            'requested_scope' => $sourceScope,
            'workspace_name' => null,
            'user_id' => $user->id,
            'upload_type' => 'direct'
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

    /**
     * Update document scope (personal to organization)
     */
    public function updateScope(Request $request, $id)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        Log::info('🔍 SCOPE UPDATE REQUEST START', [
            'document_id' => $id,
            'user_id' => $user->id,
            'org_id' => $orgId,
            'request_data' => $request->all()
        ]);

        $validated = $request->validate([
            'scope' => 'required|string|in:personal,organization'
        ]);

        $newScope = $validated['scope'];

        Log::info('📋 VALIDATION PASSED', [
            'document_id' => $id,
            'new_scope' => $newScope
        ]);

        // Find the document - only allow document owner to change scope
        $document = Document::where('id', $id)
            ->where('org_id', $orgId)
            ->where('user_id', $user->id) // Only document owner can change scope
            ->with('connector')
            ->first();

        if (!$document) {
            Log::warning('❌ DOCUMENT NOT FOUND OR NO PERMISSION', [
                'document_id' => $id,
                'user_id' => $user->id,
                'org_id' => $orgId
            ]);
            return response()->json([
                'error' => 'Document not found or you do not have permission to modify it'
            ], 404);
        }

        Log::info('✅ DOCUMENT FOUND', [
            'document_id' => $id,
            'document_title' => $document->title,
            'current_connector_scope' => $document->connector ? $document->connector->connection_scope : 'no_connector',
            'current_document_scope' => $document->source_scope
        ]);

        // Only allow changing from personal to organization
        if ($document->source_scope === 'organization') {
            Log::warning('⚠️ ALREADY ORGANIZATION SCOPE', [
                'document_id' => $id,
                'current_scope' => $document->source_scope
            ]);
            return response()->json([
                'error' => 'Document is already in organization scope'
            ], 400);
        }

        // Count chunks before update
        $chunksCount = Chunk::where('document_id', $id)->count();
        
        Log::info('📊 BEFORE UPDATE', [
            'document_id' => $id,
            'chunks_count' => $chunksCount,
            'connector_id' => $document->connector ? $document->connector->id : 'no_connector'
        ]);

        // Individual document scope update - only affects this specific document
        Log::info('📝 INDIVIDUAL DOCUMENT SCOPE UPDATE', [
            'document_id' => $id,
            'connector_id' => $document->connector ? $document->connector->id : 'no_connector',
            'reason' => 'Individual document scope change - does not affect connector or other documents'
        ]);

        // Update document source_scope and workspace_name
        $oldDocumentScope = $document->source_scope;
        $updateData = [
            'source_scope' => $newScope
        ];
        
        // When sharing to organization, update workspace_name to reflect organization-wide access
        if ($newScope === 'organization') {
            $updateData['workspace_name'] = 'Organization Shared';
        }
        
        $document->update($updateData);

        Log::info('📄 DOCUMENT UPDATED', [
            'document_id' => $id,
            'old_scope' => $oldDocumentScope,
            'new_scope' => $newScope
        ]);

        // Update all related chunks
        $chunkUpdateData = [
            'source_scope' => $newScope
        ];
        
        // When sharing to organization, update workspace_name to reflect organization-wide access
        if ($newScope === 'organization') {
            $chunkUpdateData['workspace_name'] = 'Organization Shared';
        }
        
        $updatedChunks = Chunk::where('document_id', $id)->update($chunkUpdateData);

        Log::info('🧩 CHUNKS UPDATED', [
            'document_id' => $id,
            'chunks_updated' => $updatedChunks,
            'total_chunks' => $chunksCount
        ]);

        // CRITICAL: Re-embed chunks with new source_scope metadata
        // This ensures the vector store has the correct scope information
        if ($newScope === 'organization') {
            Log::info('🔄 RE-EMBEDDING CHUNKS FOR ORGANIZATION SCOPE', [
                'document_id' => $id,
                'chunks_count' => $chunksCount,
                'reason' => 'Document shared to organization - updating vector store metadata'
            ]);
            
            // Get all chunks for this document
            $chunks = Chunk::where('document_id', $id)->get();
            
            if ($chunks->isNotEmpty()) {
                // Dispatch re-embedding job to update vector store metadata
                \App\Jobs\EmbedChunksBatchJob::dispatch(
                    $chunks->pluck('id')->toArray(),
                    $orgId
                );
                
                Log::info('✅ RE-EMBEDDING JOB DISPATCHED', [
                    'document_id' => $id,
                    'chunk_ids' => $chunks->pluck('id')->toArray(),
                    'org_id' => $orgId
                ]);
            }
        }

        // Verify the update
        $updatedDocument = Document::where('id', $id)->with('connector')->first();
        $updatedChunksCount = Chunk::where('document_id', $id)->where('source_scope', $newScope)->count();

        Log::info('✅ SCOPE UPDATE COMPLETED', [
            'document_id' => $id,
            'user_id' => $user->id,
            'org_id' => $orgId,
            'final_document_scope' => $updatedDocument->source_scope,
            'final_connector_scope' => $updatedDocument->connector ? $updatedDocument->connector->connection_scope : 'no_connector',
            'chunks_with_new_scope' => $updatedChunksCount,
            'total_chunks' => $chunksCount,
            'document_title' => $updatedDocument->title
        ]);

        return response()->json([
            'message' => 'Document scope updated successfully',
            'document' => $updatedDocument,
            'scope' => $newScope
        ]);
    }

    public function bulkUpdateScope(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        Log::info('🔍 BULK SCOPE UPDATE REQUEST START', [
            'user_id' => $user->id,
            'org_id' => $orgId,
            'request_data' => $request->all()
        ]);

        $validated = $request->validate([
            'document_ids' => 'required|array',
            'document_ids.*' => 'required|string|uuid',
            'scope' => 'required|string|in:personal,organization'
        ]);

        $documentIds = $validated['document_ids'];
        $newScope = $validated['scope'];

        Log::info('📋 BULK VALIDATION PASSED', [
            'document_count' => count($documentIds),
            'new_scope' => $newScope,
            'document_ids' => $documentIds
        ]);

        // Find documents that belong to the current user and organization
        $documents = Document::whereIn('id', $documentIds)
            ->where('org_id', $orgId)
            ->where('user_id', $user->id) // Only allow updating own documents
            ->with('connector')
            ->get();

        Log::info('📊 BULK DOCUMENTS FOUND', [
            'requested_count' => count($documentIds),
            'found_count' => $documents->count(),
            'document_titles' => $documents->pluck('title')->toArray()
        ]);

        if ($documents->isEmpty()) {
            Log::warning('❌ BULK NO DOCUMENTS FOUND', [
                'user_id' => $user->id,
                'org_id' => $orgId,
                'requested_ids' => $documentIds
            ]);
            return response()->json([
                'error' => 'No documents found or you do not have permission to modify them'
            ], 404);
        }

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($documents as $document) {
            Log::info('🔄 PROCESSING DOCUMENT', [
                'document_id' => $document->id,
                'document_title' => $document->title,
                'current_connector_scope' => $document->connector ? $document->connector->connection_scope : 'no_connector',
                'current_document_scope' => $document->source_scope
            ]);

            // Only allow changing from personal to organization
            if ($document->connector && $document->connector->connection_scope === 'organization') {
                Log::info('⏭️ SKIPPING - ALREADY ORGANIZATION', [
                    'document_id' => $document->id,
                    'current_scope' => $document->connector->connection_scope
                ]);
                $skippedCount++;
                continue; // Skip documents already in organization scope
            }

            // For bulk updates, we update each document individually
            // This allows documents from different connectors to be updated separately
            $updateData = [
                'source_scope' => $newScope
            ];
            
            // When sharing to organization, update workspace_name to reflect organization-wide access
            if ($newScope === 'organization') {
                $updateData['workspace_name'] = 'Organization Shared';
            }
            
            $document->update($updateData);

            Log::info('📄 BULK DOCUMENT UPDATED', [
                'document_id' => $document->id,
                'old_scope' => $document->source_scope,
                'new_scope' => $newScope
            ]);

            // Update all related chunks
            $chunksCount = Chunk::where('document_id', $document->id)->count();
            $chunkUpdateData = [
                'source_scope' => $newScope
            ];
            
            // When sharing to organization, update workspace_name to reflect organization-wide access
            if ($newScope === 'organization') {
                $chunkUpdateData['workspace_name'] = 'Organization Shared';
            }
            
            $updatedChunks = Chunk::where('document_id', $document->id)->update($chunkUpdateData);

            Log::info('🧩 BULK CHUNKS UPDATED', [
                'document_id' => $document->id,
                'chunks_updated' => $updatedChunks,
                'total_chunks' => $chunksCount
            ]);

            // CRITICAL: Re-embed chunks with new source_scope metadata
            if ($newScope === 'organization') {
                Log::info('🔄 BULK RE-EMBEDDING CHUNKS FOR ORGANIZATION SCOPE', [
                    'document_id' => $document->id,
                    'chunks_count' => $chunksCount,
                    'reason' => 'Document shared to organization - updating vector store metadata'
                ]);
                
                // Get all chunks for this document
                $chunks = Chunk::where('document_id', $document->id)->get();
                
                if ($chunks->isNotEmpty()) {
                    // Dispatch re-embedding job to update vector store metadata
                    \App\Jobs\EmbedChunksBatchJob::dispatch(
                        $chunks->pluck('id')->toArray(),
                        $orgId
                    );
                    
                    Log::info('✅ BULK RE-EMBEDDING JOB DISPATCHED', [
                        'document_id' => $document->id,
                        'chunk_ids' => $chunks->pluck('id')->toArray(),
                        'org_id' => $orgId
                    ]);
                }
            }

            $updatedCount++;

            Log::info('✅ BULK DOCUMENT COMPLETED', [
                'document_id' => $document->id,
                'user_id' => $user->id,
                'org_id' => $orgId,
                'new_scope' => $newScope,
                'document_title' => $document->title
            ]);
        }

        Log::info('🎉 BULK SCOPE UPDATE COMPLETED', [
            'user_id' => $user->id,
            'org_id' => $orgId,
            'requested_count' => count($documentIds),
            'processed_count' => $documents->count(),
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount,
            'new_scope' => $newScope
        ]);

        return response()->json([
            'message' => "Successfully updated {$updatedCount} document(s) scope to {$newScope}",
            'updated_count' => $updatedCount,
            'scope' => $newScope
        ]);
    }
}


