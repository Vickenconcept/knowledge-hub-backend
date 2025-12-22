<?php

namespace App\Http\Controllers;

use App\Models\Connector;
use App\Models\IngestJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\IngestConnectorJob;
use Google\Client as GoogleClient;

class ConnectorController extends Controller
{
    /**
     * Get usage status and limits for organization
     */
    public function getUsageStatus(Request $request)
    {
        $orgId = $request->user()->org_id;
        
        $status = \App\Services\UsageLimitService::getUsageStatus($orgId);
        
        return response()->json($status);
    }
    
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;
        
        // Clean up stuck jobs (running/queued for more than 1 hour)
        IngestJob::where('org_id', $orgId)
            ->whereIn('status', ['running', 'queued'])
            ->where('created_at', '<', now()->subHour())
            ->update(['status' => 'failed']);
        
        // Get connectors with proper access control
        $connectors = Connector::where('org_id', $orgId)
            ->where(function($query) use ($userId) {
                // Show organization connectors to everyone
                $query->where('connection_scope', 'organization')
                      // Show personal connectors only to users with permission
                      ->orWhere(function($q) use ($userId) {
                          $q->where('connection_scope', 'personal')
                            ->whereHas('userPermissions', function($permQuery) use ($userId) {
                                $permQuery->where('user_id', $userId);
                            });
                      });
            })
            ->withCount([
                'documents' => function($query) {
                    // Exclude system guide documents from count
                    $query->where(function($q) {
                        $q->where('doc_type', '!=', 'guide')
                          ->orWhereNull('doc_type');
                    });
                },
                'chunks'
            ])
            ->get();
        
        // Rename count fields to match frontend expectations and check for running jobs
        foreach ($connectors as $connector) {
            $connector->documents_count = $connector->documents_count ?? 0;
            $connector->chunks_count = $connector->chunks_count ?? 0;
            
            // Check for running ingest jobs and update connector status
            $runningJob = IngestJob::where('connector_id', $connector->id)
                ->whereIn('status', ['running', 'queued', 'processing_large_files'])
                ->first();
            
            if ($runningJob) {
                $connector->status = 'syncing';
            }
        }
        
        return response()->json($connectors);
    }

    public function create(Request $request)
    {
        try {
            \Log::info('=== CONNECTOR CREATE REQUEST ===', [
                'user_id' => $request->user()->id,
                'org_id' => $request->user()->org_id,
                'request_data' => $request->all(),
                'headers' => $request->headers->all()
            ]);

            $validated = $request->validate([
                'type' => 'required|string',
                'label' => 'nullable|string|max:255',
                'connection_scope' => 'nullable|string|in:organization,personal',
                'workspace_name' => 'nullable|string|max:255',
                'workspace_id' => 'nullable|string|max:255',
                'is_primary' => 'nullable|boolean',
                'workspace_metadata' => 'nullable|array',
            ]);

            $userId = $request->user()->id;
            $orgId = $request->user()->org_id;
            $connectionScope = $validated['connection_scope'] ?? 'organization';
            
            // Check for duplicate connector: prevent having more than one connector of the same type
            // in the same scope (organization OR personal, but not both of the same scope)
            $existingConnector = Connector::where('org_id', $orgId)
                ->where('type', $validated['type'])
                ->where('connection_scope', $connectionScope)
                ->first();
            
            if ($existingConnector) {
                // For personal connectors, check if it belongs to a different user
                if ($connectionScope === 'personal') {
                    $hasUserPermission = $existingConnector->userPermissions()
                        ->where('user_id', $userId)
                        ->exists();
                    
                    if ($hasUserPermission) {
                        \Log::warning('Duplicate personal connector creation prevented', [
                            'user_id' => $userId,
                            'org_id' => $orgId,
                            'type' => $validated['type'],
                            'connection_scope' => $connectionScope,
                            'existing_connector_id' => $existingConnector->id
                        ]);
                        
                        return response()->json([
                            'success' => false,
                            'error' => 'You already have a personal connector of this type',
                            'existing_connector' => $existingConnector
                        ], 409);
                    }
                } else {
                    // Organization scope: return existing connector
                    \Log::warning('Duplicate organization connector creation prevented', [
                        'user_id' => $userId,
                        'org_id' => $orgId,
                        'type' => $validated['type'],
                        'connection_scope' => $connectionScope,
                        'existing_connector_id' => $existingConnector->id
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'error' => 'Organization connector of this type already exists',
                        'existing_connector' => $existingConnector
                    ], 409);
                }
            }
            
            // Try to create connector with race condition handling
            try {
                $connector = Connector::create([
                    'id' => (string) Str::uuid(),
                    'org_id' => $orgId,
                    'type' => $validated['type'],
                    'label' => $validated['label'] ?? ucfirst($validated['type']),
                    'connection_scope' => $connectionScope,
                    'workspace_name' => $validated['workspace_name'] ?? null,
                    'workspace_id' => $validated['workspace_id'] ?? null,
                    'is_primary' => $validated['is_primary'] ?? false,
                    'workspace_metadata' => $validated['workspace_metadata'] ?? null,
                    'status' => 'disconnected',
                ]);
            } catch (\Exception $e) {
                // Race condition: another request created it between our check and creation
                \Log::warning('Race condition detected when creating connector', [
                    'error' => $e->getMessage(),
                    'type' => $validated['type'],
                    'connection_scope' => $connectionScope,
                    'user_id' => $userId
                ]);
                
                // Reload the connector that was just created
                $connector = Connector::where('org_id', $orgId)
                    ->where('type', $validated['type'])
                    ->where('connection_scope', $connectionScope)
                    ->first();
                
                if (!$connector) {
                    throw $e; // If we can't find it, rethrow the error
                }
                
                // Return the existing connector
                \Log::info('Returning existing connector after race condition', [
                    'connector_id' => $connector->id,
                    'type' => $validated['type']
                ]);
                
                return response()->json([
                    'success' => true,
                    'connector' => $connector,
                    'message' => 'Connector already exists'
                ]);
            }

            // For personal connectors, create user permission
            if ($connector->connection_scope === 'personal') {
                try {
                    \App\Models\UserConnectorPermission::create([
                        'id' => (string) Str::uuid(),
                        'user_id' => $userId,
                        'connector_id' => $connector->id,
                        'permission_level' => 'admin', // Creator gets admin access
                    ]);
                    
                    \Log::info('Created user permission for personal connector', [
                        'connector_id' => $connector->id,
                        'user_id' => $userId,
                        'permission_level' => 'admin'
                    ]);
                } catch (\Exception $e) {
                    // Permission may already exist due to race condition
                    \Log::warning('Failed to create user permission (may already exist)', [
                        'connector_id' => $connector->id,
                        'user_id' => $userId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $authUrl = url("/connectors/oauth/{$connector->id}");

            \Log::info('=== CONNECTOR CREATE SUCCESS ===', [
                'connector_id' => $connector->id,
                'type' => $connector->type,
                'connection_scope' => $connector->connection_scope,
                'workspace_name' => $connector->workspace_name,
                'auth_url' => $authUrl
            ]);

            return response()->json([
                'success' => true,
                'connector' => $connector,
                'auth_url' => $authUrl,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('=== CONNECTOR CREATE VALIDATION ERROR ===', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            \Log::error('=== CONNECTOR CREATE ERROR ===', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to create connector: ' . $e->getMessage()
            ], 500);
        }
    }

    public function oauthCallback(Request $request, string $id)
    {
        $connector = Connector::findOrFail($id);

        $data = $request->only(['access_token','refresh_token','expires_at']);
        $connector->encrypted_tokens = encrypt(json_encode($data));
        $connector->status = 'connected';
        $connector->save();

        return response()->json([
            'message' => 'Connector connected successfully',
            'connector' => $connector,
        ]);
    }

    public function getJobStatus(Request $request, string $connectorId)
    {
        $connector = Connector::where('id', $connectorId)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        // Get the latest job for this connector
        $job = IngestJob::where('connector_id', $connectorId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$job) {
            return response()->json([
                'has_job' => false,
                'message' => 'No sync job found'
            ]);
        }

        // Ensure stats is an array (handle PostgreSQL JSON type)
        $stats = is_array($job->stats) ? $job->stats : json_decode($job->stats, true) ?? [];
        
        // Calculate progress percentage based on connector type
        $progressPercentage = 0;
        if (isset($stats['total_channels']) && $stats['total_channels'] > 0) {
            // Slack: use channels
            $progressPercentage = round(($stats['processed_channels'] / $stats['total_channels']) * 100, 1);
        } elseif (isset($stats['total_files']) && $stats['total_files'] > 0) {
            // Google Drive, Dropbox: use files
            $progressPercentage = round(($stats['processed_files'] / $stats['total_files']) * 100, 1);
        }
        
        \Log::debug('Job status API response', [
            'connector_id' => $connectorId,
            'progress_percentage' => $progressPercentage,
            'stats' => $stats,
        ]);
        
        return response()->json([
            'has_job' => true,
            'job_id' => $job->id,
            'status' => $job->status,
            'stats' => $stats,
            'created_at' => $job->created_at,
            'finished_at' => $job->finished_at,
            'progress_percentage' => $progressPercentage,
        ]);
    }

    public function startIngest(Request $request, string $id)
    {
        \Log::info('=== SYNC STARTED ===', [
            'connector_id' => $id,
            'user_id' => $request->user()->id,
            'org_id' => $request->user()->org_id
        ]);

        $orgId = $request->user()->org_id;
        
        // CHECK IF ALREADY AT DOCUMENT LIMIT
        $docLimit = \App\Services\UsageLimitService::canAddDocument($orgId);
        if (!$docLimit['allowed']) {
            return response()->json([
                'error' => 'Document limit exceeded',
                'message' => $docLimit['reason'],
                'current_usage' => $docLimit['current_usage'],
                'limit' => $docLimit['limit'],
                'tier' => $docLimit['tier'],
                'upgrade_required' => true,
            ], 429);
        }
        
        // Warn if close to limit
        if ($docLimit['remaining'] && $docLimit['remaining'] < 10) {
            \Log::warning('Organization close to document limit', [
                'org_id' => $orgId,
                'current' => $docLimit['current_usage'],
                'limit' => $docLimit['limit'],
                'remaining' => $docLimit['remaining'],
            ]);
        }

        $connector = Connector::where('id', $id)
            ->where('org_id', $orgId)
            ->firstOrFail();

        \Log::info('Connector found for sync', [
            'connector_id' => $connector->id,
            'type' => $connector->type,
            'status' => $connector->status,
            'has_tokens' => !empty($connector->encrypted_tokens)
        ]);

        // Dispatch job - the job itself will create the IngestJob record
        IngestConnectorJob::dispatch($connector->id, $request->user()->org_id)->onQueue('default');

        \Log::info('IngestConnectorJob dispatched to queue', [
            'connector_id' => $connector->id,
            'org_id' => $request->user()->org_id,
            'queue' => 'default'
        ]);

        return response()->json([
            'message' => 'Ingestion started',
            'job_id' => null, // Job will be created by the job itself
        ]);
    }

    public function stopSync(Request $request, string $id)
    {
        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        \Log::info('=== STOP SYNC REQUESTED ===', [
            'connector_id' => $connector->id,
            'user_id' => $request->user()->id,
            'current_status' => $connector->status
        ]);

        // Find any running/queued jobs for this connector
        $runningJobs = IngestJob::where('connector_id', $connector->id)
            ->whereIn('status', ['running', 'queued', 'processing_large_files'])
            ->get();

        if ($runningJobs->isEmpty()) {
            \Log::info('No running jobs found to stop', ['connector_id' => $connector->id]);
            return response()->json(['message' => 'No sync in progress'], 400);
        }

        // Mark all running jobs as cancelled
        foreach ($runningJobs as $job) {
            $oldStatus = $job->status;
            $job->status = 'cancelled';
            $job->finished_at = now();
            
            // Update stats to indicate cancellation
            $stats = $job->stats ?? [];
            $stats['cancelled_at'] = now()->toISOString();
            $stats['cancelled_by'] = $request->user()->id;
            $stats['current_file'] = 'Cancelled by user';
            $job->stats = $stats;
            
            $job->save();

            \Log::info('IngestJob marked as cancelled', [
                'job_id' => $job->id,
                'old_status' => $oldStatus,
                'new_status' => 'cancelled'
            ]);
        }

        // Reset connector status
        $connector->status = 'connected';
        $connector->save();

        \Log::info('Connector status reset after sync stop', [
            'connector_id' => $connector->id,
            'status' => 'connected'
        ]);

        return response()->json([
            'message' => 'Sync stopped successfully',
            'connector' => $connector,
            'cancelled_jobs' => $runningJobs->count()
        ]);
    }

    public function disconnect(Request $request, string $id)
    {
        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        \Log::info('Disconnecting and deleting connector', [
            'connector_id' => $connector->id,
            'type' => $connector->type,
            'user_id' => $request->user()->id,
            'documents_count' => $connector->documents()->count(),
        ]);

        // For Slack: Leave all joined channels before disconnecting
        if ($connector->type === 'slack') {
            try {
                $tokens = $connector->encrypted_tokens ? json_decode(decrypt($connector->encrypted_tokens), true) : null;
                $accessToken = $tokens['access_token'] ?? null;
                
                if ($accessToken) {
                    $joinedChannels = $connector->metadata['joined_channels'] ?? [];
                    
                    if (!empty($joinedChannels)) {
                        \Log::info('Leaving Slack channels before disconnect', [
                            'connector_id' => $connector->id,
                            'channels_to_leave' => count($joinedChannels),
                        ]);
                        
                        $slack = new \App\Services\SlackService();
                        $leaveResults = $slack->leaveChannels($accessToken, $joinedChannels);
                        
                        \Log::info('Left Slack channels', [
                            'connector_id' => $connector->id,
                            'total' => $leaveResults['total'],
                            'succeeded' => $leaveResults['succeeded'],
                            'failed' => $leaveResults['failed'],
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Could not leave Slack channels', [
                    'connector_id' => $connector->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue with deletion even if leaving channels fails
            }
        }

        // Delete associated data
        // Note: Documents, chunks, and ingest jobs will be cascade deleted
        // if foreign keys are set up, otherwise delete manually
        
        $documentsCount = $connector->documents()->count();
        $chunksCount = $connector->chunks()->count();
        
        // Get document IDs and chunk IDs for vector deletion
        $documentIds = $connector->documents()->pluck('id')->toArray();

        // Cloudinary cleanup can be slow for connectors with many docs.
        // Dispatch to queue so the HTTP request returns fast.
        try {
            $docsForCloudinary = \App\Models\Document::whereIn('id', $documentIds)
                ->get(['id', 's3_path', 'metadata']);

            $targets = [];
            foreach ($docsForCloudinary as $doc) {
                $publicId = $doc->metadata['cloudinary_public_id'] ?? null;
                $resourceType = $doc->metadata['cloudinary_resource_type'] ?? 'raw';
                $url = $doc->s3_path;

                if (empty($publicId) && empty($url)) {
                    continue;
                }

                $targets[] = [
                    'document_id' => $doc->id,
                    'public_id' => $publicId,
                    'url' => $url,
                    'resource_type' => $resourceType,
                ];
            }

            foreach (array_chunk($targets, 50) as $chunk) {
                \App\Jobs\DeleteCloudinaryAssetsJob::dispatch($chunk)->onQueue('default');
            }

            \Log::info('Queued Cloudinary cleanup jobs for connector disconnect', [
                'connector_id' => $connector->id,
                'documents' => count($documentIds),
                'cloudinary_targets' => count($targets),
                'jobs_dispatched' => (int) ceil(count($targets) / 50),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Could not queue Cloudinary cleanup jobs (continuing)', [
                'connector_id' => $connector->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Get all chunk IDs to delete vectors
        $chunkIds = \App\Models\Chunk::whereIn('document_id', $documentIds)
            ->pluck('id')
            ->toArray();
        
        // Delete vectors from database (set embedding to NULL)
        if (!empty($chunkIds)) {
            try {
                $vectorStore = new \App\Services\VectorStoreService();
                $vectorStore->delete($chunkIds);
                
                \Log::info('Deleted vectors from database', [
                    'chunk_count' => count($chunkIds),
                    'connector_id' => $connector->id,
                ]);
            } catch (\Exception $e) {
                \Log::warning('Could not delete vectors', [
                    'error' => $e->getMessage(),
                    'chunk_count' => count($chunkIds),
                ]);
                // Continue with deletion even if vector cleanup fails
            }
        }
        
        // Delete chunks from database
        \App\Models\Chunk::whereIn('document_id', $documentIds)->delete();
        
        // Delete documents
        $connector->documents()->delete();
        
        // Delete ingest jobs
        \App\Models\IngestJob::where('connector_id', $connector->id)->delete();
        
        // Delete the connector itself
        $connector->delete();

        \Log::info('Connector deleted successfully', [
            'connector_id' => $id,
            'type' => $connector->type,
            'documents_deleted' => $documentsCount,
            'chunks_deleted' => $chunksCount,
        ]);

        return response()->json([
            'message' => 'Connector disconnected and deleted successfully',
            'deleted' => [
                'documents' => $documentsCount,
                'chunks' => $chunksCount,
            ],
        ]);
    }

    /**
     * Get user permissions for a connector
     */
    public function getPermissions(Request $request, string $id)
    {
        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        // Only show permissions for personal connectors
        if ($connector->connection_scope !== 'personal') {
            return response()->json(['message' => 'Only personal connectors have user permissions'], 400);
        }

        $permissions = $connector->userPermissions()
            ->with('user:id,name,email')
            ->get();

        return response()->json($permissions);
    }

    /**
     * Add user permission to a personal connector
     */
    public function addPermission(Request $request, string $id)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'permission_level' => 'required|string|in:read,write,admin',
        ]);

        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        // Only allow permission management for personal connectors
        if ($connector->connection_scope !== 'personal') {
            return response()->json(['message' => 'Only personal connectors support user permissions'], 400);
        }

        // Check if user is in the same organization
        $targetUser = \App\Models\User::where('id', $validated['user_id'])
            ->where('org_id', $request->user()->org_id)
            ->first();

        if (!$targetUser) {
            return response()->json(['message' => 'User not found in organization'], 404);
        }

        // Create or update permission
        $permission = \App\Models\UserConnectorPermission::updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'connector_id' => $id,
            ],
            [
                'permission_level' => $validated['permission_level'],
            ]
        );

        return response()->json([
            'message' => 'Permission added successfully',
            'permission' => $permission->load('user:id,name,email'),
        ]);
    }

    /**
     * Remove user permission from a personal connector
     */
    public function removePermission(Request $request, string $id, string $userId)
    {
        $connector = Connector::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->firstOrFail();

        // Only allow permission management for personal connectors
        if ($connector->connection_scope !== 'personal') {
            return response()->json(['message' => 'Only personal connectors support user permissions'], 400);
        }

        $permission = \App\Models\UserConnectorPermission::where('connector_id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$permission) {
            return response()->json(['message' => 'Permission not found'], 404);
        }

        $permission->delete();

        return response()->json(['message' => 'Permission removed successfully']);
    }
}


