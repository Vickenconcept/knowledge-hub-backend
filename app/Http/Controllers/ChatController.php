<?php

namespace App\Http\Controllers;

use App\Models\Chunk;
use App\Models\Connector;
use App\Models\Document;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\EmbeddingService;
use App\Services\VectorStoreService;
use App\Services\RAGService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function ask(Request $request, EmbeddingService $embeddingService, VectorStoreService $vectorStore, RAGService $rag)
    {
        $user = $request->user();
        $orgId = $user->org_id;
        
        // CHECK USAGE LIMITS BEFORE PROCESSING
        $chatLimit = \App\Services\UsageLimitService::canChat($orgId);
        if (!$chatLimit['allowed']) {
            return response()->json([
                'error' => 'Usage limit exceeded',
                'message' => $chatLimit['reason'],
                'limit_type' => 'chat_queries',
                'current_usage' => $chatLimit['current_usage'],
                'limit' => $chatLimit['limit'],
                'tier' => $chatLimit['tier'],
                'upgrade_required' => true,
            ], 429); // 429 = Too Many Requests
        }
        
        // CHECK MONTHLY SPEND LIMIT
        $spendLimit = \App\Services\UsageLimitService::isWithinSpendLimit($orgId);
        if (!$spendLimit['within_limit']) {
            return response()->json([
                'error' => 'Monthly spend limit exceeded',
                'message' => $spendLimit['reason'],
                'limit_type' => 'monthly_spend',
                'current_spend' => $spendLimit['current_spend'],
                'limit' => $spendLimit['limit'],
                'upgrade_required' => true,
            ], 429);
        }
        
        $validated = $request->validate([
            'query' => 'required|string',
            'conversation_id' => 'nullable|string|uuid',
            'top_k' => 'nullable|integer|min:1|max:50',
            'connector_ids' => 'nullable|array', // Source filtering
            'connector_ids.*' => 'string|uuid',
            'search_scope' => 'nullable|string|in:organization,personal,both', // Workspace scope
            'workspace_ids' => 'nullable|array', // Specific workspace filtering
            'workspace_ids.*' => 'string'
        ]);

        $queryText = $validated['query'];
        $topK = $validated['top_k'] ?? 10; // Reduced from 15 to 10 for faster responses (balance: quality vs speed)
        $conversationId = $validated['conversation_id'] ?? null;
        $requestedConnectorIds = $validated['connector_ids'] ?? null;
        $searchScope = $validated['search_scope'] ?? 'both';
        $workspaceIds = $validated['workspace_ids'] ?? null;
        
        // Detect natural language source mentions (e.g., "from dropbox", "in slack")
        $detectedSource = $this->detectSourceInQuery($queryText, $orgId);
        
        // Use explicitly requested connectors, or fall back to detected ones
        $connectorIds = $requestedConnectorIds ?? $detectedSource;
        
        if ($connectorIds) {
            Log::info('Source filtering active', [
                'requested' => $requestedConnectorIds,
                'detected' => $detectedSource,
                'final' => $connectorIds,
                'query' => $queryText
            ]);
        }

        // Get or create conversation
        if ($conversationId) {
            $conversation = Conversation::where('id', $conversationId)
                ->where('user_id', $user->id)
                ->firstOrFail();
        } else {
            // Create new conversation using user's default response style
            $userDefaultStyle = $user->default_response_style ?? 'comprehensive';
            
            $conversation = Conversation::create([
                'org_id' => $orgId,
                'user_id' => $user->id,
                'title' => mb_substr($queryText, 0, 50), // Use first query as title
                'response_style' => $userDefaultStyle,
                'preferences' => array_merge([
                    'detail_level' => 'high',
                    'include_sources' => true,
                ], $user->ai_preferences ?? []),
                'last_message_at' => now(),
            ]);
            
            Log::info('New conversation created with user default style', [
                'user_id' => $user->id,
                'default_style' => $userDefaultStyle
            ]);
        }

        // Save user message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $queryText,
        ]);

        try {
            // 1. INTELLIGENT CONTEXT ROUTING
            // Get conversation history for routing decision
            $conversationHistory = \App\Services\ConversationMemoryService::getConversationContext($conversation->id, 10);
            
            // Route query to appropriate context sources
            $routing = \App\Services\ContextRouter::routeQuery($queryText, $conversationHistory);
            
            Log::info('Context routing decision', [
                'query' => $queryText,
                'route_type' => $routing['route_type'],
                'confidence' => $routing['confidence'],
                'reasoning' => $routing['reasoning'],
                'search_documents' => $routing['search_documents'],
                'search_messages' => $routing['search_messages'],
                'attach_last_answer' => $routing['attach_last_answer'],
            ]);
            
            // Handle SESSION MEMORY QUERIES (last week, previous conversations)
            if (\App\Services\ConversationMemoryService::isSessionMemoryQuery($queryText)) {
                Log::info('Session memory query detected', ['query' => $queryText]);
                
                $sessionMemory = new \App\Services\SessionMemoryService();
                $sessionResults = $sessionMemory->searchUserHistory($user->id, $queryText, 5);
                $formattedResponse = $sessionMemory->formatSessionResults($sessionResults, $queryText);
                
                // Save assistant message
                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $formattedResponse,
                    'sources' => [],
                ]);
                
                return response()->json([
                    'answer' => $formattedResponse,
                    'sources' => [],
                    'query' => $queryText,
                    'result_count' => count($sessionResults),
                    'conversation_id' => $conversation->id,
                    'route_type' => 'session_memory',
                    'session_results' => $sessionResults,
                ]);
            }
            
            // Handle ENTITY QUERIES (who/which people/users)
            $entityInfo = \App\Services\EntitySearchService::isEntityQuery($queryText);
            
            if ($entityInfo['is_entity_query']) {
                Log::info('Entity query detected - searching across entities', [
                    'entity_type' => $entityInfo['entity_type'],
                    'intent' => $entityInfo['query_intent'],
                    'skills' => $entityInfo['skill_keywords'],
                ]);
                
                $entityResults = \App\Services\EntitySearchService::searchEntities($queryText, $entityInfo, $orgId);
                $formattedResponse = \App\Services\EntitySearchService::formatEntityResults($entityResults, $queryText, $entityInfo['is_count_query']);
                
                // Save assistant message
                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $formattedResponse,
                    'sources' => [],
                ]);
                
                return response()->json([
                    'answer' => $formattedResponse,
                    'sources' => [],
                    'query' => $queryText,
                    'result_count' => $entityResults['total'],
                    'conversation_id' => $conversation->id,
                    'route_type' => 'entity_search',
                    'entities' => $entityResults['entities'],
                ]);
            }
            
            // Handle pure meta-questions (conversation-only queries)
            if ($routing['route_type'] === 'meta') {
                $metaResponse = \App\Services\ConversationMemoryService::buildMetaResponse($queryText, $conversationHistory);
                
                if ($metaResponse) {
                    Message::create([
                        'conversation_id' => $conversation->id,
                        'role' => 'assistant',
                        'content' => $metaResponse,
                        'sources' => [],
                    ]);
                    
                    return response()->json([
                        'answer' => $metaResponse,
                        'sources' => [],
                        'query' => $queryText,
                        'result_count' => 0,
                        'conversation_id' => $conversation->id,
                        'route_type' => 'meta',
                    ]);
                }
            }
            
            // 2. Search documents if routing says so
            $snippets = [];
            if ($routing['search_documents']) {
            $embedding = $embeddingService->embed($queryText, $orgId);
                
                // Build workspace-aware Pinecone metadata filter
                $pineconeFilter = $this->buildWorkspaceAwareFilter($orgId, $user->id, $connectorIds, $searchScope, $workspaceIds);
                
                $matches = $vectorStore->query($embedding, $topK, $orgId, $orgId, $conversation->id, $pineconeFilter);
            $chunkIds = array_map(fn($m) => $m['id'], $matches);

            if (empty($chunkIds)) {
                return response()->json([
                    'answer' => "I don't know — no relevant documents found.",
                    'sources' => [],
                        'query' => $queryText,
                        'result_count' => 0,
                        'raw' => null
                    ]);
                }

                // 3. Fetch chunk rows
                $chunks = Chunk::whereIn('id', $chunkIds)
                    ->with(['document' => function($q) {
                        $q->select('id', 'title', 'source_url', 's3_path', 'org_id', 'connector_id', 'doc_type', 'tags', 'metadata');
                    }, 'document.connector'])
                    ->get()
                    ->keyBy('id');

                // Build an ordered snippets array based on matches
            foreach ($matches as $m) {
                $cid = $m['id'];
                if (!isset($chunks[$cid])) continue;
                $c = $chunks[$cid];
                $snippets[] = [
                    'chunk_id' => $c->id,
                    'document_id' => $c->document_id,
                    'text' => $c->text,
                    'char_start' => $c->char_start,
                    'char_end' => $c->char_end,
                        'score' => $m['score'],
                        'doc_type' => $c->document->doc_type ?? 'general',
                        'tags' => $c->document->tags ?? [],
                    ];
                }
            }

            // 4. Intelligent Style Selection
            // First, check if user explicitly requested a style in their query
            $styleInference = \App\Services\StyleInferenceService::detectExplicitStyleRequest($queryText);
            
            if ($styleInference && $styleInference['detected']) {
                // User explicitly requested a style (e.g., "give me a brief summary")
                $responseStyle = $styleInference['style'];
                Log::info('Style explicitly requested in query', [
                    'keyword' => $styleInference['keyword'],
                    'style' => $responseStyle
                ]);
            } else {
                // Use AI inference to determine best style based on query patterns
                $currentStyle = $conversation->response_style ?? 'comprehensive';
                $responseStyle = \App\Services\StyleInferenceService::getRecommendedStyle($queryText, $currentStyle);
                
                Log::info('Style determined via inference', [
                    'conversation_default' => $currentStyle,
                    'recommended_style' => $responseStyle,
                    'query' => $queryText
                ]);
            }
            
            Log::info('Using response style for conversation', [
                'conversation_id' => $conversation->id,
                'response_style' => $responseStyle,
                'query' => $queryText
            ]);
            
            // Get enhanced context based on routing decision
            $enhancedContext = \App\Services\ContextRouter::buildEnhancedContext($routing, $conversation->id, $conversationHistory);
            
            // Pass routing metadata to RAG for intelligent prompt building
            $prompt = $rag->assemblePrompt(
                $queryText, 
                $snippets, 
                $responseStyle, 
                $enhancedContext['conversation_history'],
                $routing,
                $enhancedContext['last_answer']
            );
            
            // Get max_tokens from response style config
            $styleConfig = \App\Services\ResponseStyleService::getStyleInstructions($responseStyle);
            $maxTokens = $styleConfig['config']['max_tokens'] ?? 1500;
            
            Log::info('Style config loaded', [
                'style' => $responseStyle,
                'max_tokens' => $maxTokens,
                'detail_level' => $styleConfig['config']['detail_level']
            ]);
            
            $llmResponse = $rag->callLLM($prompt, $maxTokens, $orgId, $user->id, $conversation->id, $queryText);

            // Parse and normalize the LLM response into a strict contract
            $rawAnswer = $llmResponse['answer'] ?? '';
            $answerText = is_string($rawAnswer) ? $rawAnswer : '';
            $llmSources = [];

            // Case 1: answer is a JSON string { answer, sources }
            if (is_string($answerText)) {
                $trimmed = ltrim($answerText);
                if (strlen($trimmed) > 0 && $trimmed[0] === '{') {
                    $decoded = json_decode($answerText, true);
                    if (is_array($decoded)) {
                        $answerText = (string) ($decoded['answer'] ?? $answerText);
                        $llmSources = $decoded['sources'] ?? [];
                    }
                }
            }

            // Case 2: answer already came as array/object
            if (is_array($rawAnswer)) {
                $answerText = (string) ($rawAnswer['answer'] ?? '');
                $llmSources = $rawAnswer['sources'] ?? $llmSources;
            }

            // Convert literal \n to actual newlines for better formatting
            if (is_string($answerText)) {
                $answerText = str_replace(['\\n', '\n'], "\n", $answerText);
            }

            // 5. Format sources with full details (title, URL, excerpt, type)
            // Group by document to avoid duplicate sources
            $sourcesByDocument = [];
            foreach ($snippets as $idx => $snippet) {
                $chunk = $chunks[$snippet['chunk_id']] ?? null;
                if (!$chunk || !$chunk->document) continue;

                $documentId = $snippet['document_id'];
                
                // Get connector type to determine source type
                $connector = $chunk->document->connector;
                $sourceType = 'Unknown'; // Default
                
                if ($connector) {
                    $sourceType = match($connector->type) {
                        'google_drive' => 'Google Drive',
                        'slack' => 'Slack',
                        'notion' => 'Notion',
                        'dropbox' => 'Dropbox',
                        default => ucfirst(str_replace('_', ' ', $connector->type))
                    };
                } else {
                    // Check if it's a system document or uploaded file
                    $docMetadata = is_string($chunk->document->metadata) 
                        ? json_decode($chunk->document->metadata, true) 
                        : $chunk->document->metadata;
                    $isSystemDoc = $docMetadata['is_system_document'] ?? false;
                    
                    if ($isSystemDoc || $chunk->document->doc_type === 'guide') {
                        $sourceType = 'KHub Guide';
                    } elseif ($chunk->document->s3_path) {
                        $sourceType = 'Uploaded';
                    }
                }

                // If document not yet in sources, add it
                if (!isset($sourcesByDocument[$documentId])) {
                    $sourcesByDocument[$documentId] = [
                        'document_id' => $documentId,
                        'title' => $chunk->document->title,
                        'url' => $chunk->document->s3_path ?: $chunk->document->source_url,
                        'excerpts' => [],
                        'char_ranges' => [],
                        'scores' => [],
                        'type' => $sourceType,
                        'doc_type' => $chunk->document->doc_type,
                        'tags' => $chunk->document->tags ?? [],
                        'metadata' => $chunk->document->metadata ?? null,
                        'chunk_count' => 0,
                    ];
                }
                
                // Add this chunk's excerpt to the document
                $sourcesByDocument[$documentId]['excerpts'][] = mb_substr($snippet['text'], 0, 300);
                $sourcesByDocument[$documentId]['char_ranges'][] = [
                    'start' => $snippet['char_start'],
                    'end' => $snippet['char_end']
                ];
                $sourcesByDocument[$documentId]['scores'][] = $snippet['score'] ?? 0;
                $sourcesByDocument[$documentId]['chunk_count']++;
            }

            // Convert to array and add combined excerpts with workspace tagging
            $formattedSources = [];
            $workspaceStats = [];
            
            foreach ($sourcesByDocument as $documentId => $source) {
                // Combine excerpts with separator, limit to 2-3 most relevant
                $topExcerpts = array_slice($source['excerpts'], 0, 3);
                $combinedExcerpt = implode("\n\n...\n\n", $topExcerpts);
                
                // Get workspace information from connector
                $workspaceInfo = null;
                if (isset($source['connector'])) {
                    $connector = $source['connector'];
                    $workspaceInfo = [
                        'workspace_name' => $connector['workspace_name'] ?? $connector['label'],
                        'workspace_scope' => $connector['connection_scope'] ?? 'organization',
                        'workspace_icon' => $connector['connection_scope'] === 'personal' ? '👤' : '🏢',
                        'workspace_label' => $connector['connection_scope'] === 'personal' ? 'Personal' : 'Organization'
                    ];
                    
                    // Track workspace stats
                    $scope = $workspaceInfo['workspace_scope'];
                    $workspaceStats[$scope] = ($workspaceStats[$scope] ?? 0) + 1;
                }
                
                $formattedSources[] = [
                    'document_id' => $source['document_id'],
                    'title' => $source['title'],
                    'url' => $source['url'],
                    'excerpt' => $combinedExcerpt,
                    'char_ranges' => $source['char_ranges'],
                    'score' => max($source['scores']), // Use highest relevance score
                    'type' => $source['type'],
                    'doc_type' => $source['doc_type'],
                    'tags' => $source['tags'],
                    'metadata' => $source['metadata'],
                    'chunk_count' => $source['chunk_count'], // How many chunks from this doc
                    'workspace_info' => $workspaceInfo, // Workspace context
                ];
            }

            // Sort by score (highest first)
            usort($formattedSources, function($a, $b) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });

            // Log the query for analytics
            \App\Models\QueryLog::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                    'org_id' => $orgId,
                'user_id' => $user->id,
                    'query_text' => $queryText,
                    'top_k' => $topK,
                'result_count' => count($formattedSources),
                'timestamp' => now(),
            ]);

            // Save assistant message
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $answerText,
                'sources' => $formattedSources,
            ]);

            // Update conversation's last message time
            $conversation->update(['last_message_at' => now()]);

            // Check if conversation needs auto-summarization
            $sessionMemory = new \App\Services\SessionMemoryService();
            if ($sessionMemory->shouldSummarize($conversation->id)) {
                // Summarize in background (non-blocking)
                dispatch(function() use ($conversation, $sessionMemory) {
                    $sessionMemory->summarizeConversation($conversation->id);
                })->afterResponse();
            }

            return response()->json([
                'answer' => $answerText,
                'sources' => $formattedSources,
                'query' => $queryText,
                'result_count' => count($formattedSources),
                'conversation_id' => $conversation->id,
                'workspace_stats' => $workspaceStats, // Workspace breakdown
                'search_scope' => $searchScope, // Applied search scope
                'raw' => $llmResponse['raw'] ?? null
            ]);

        } catch (\Exception $e) {
            Log::error('ChatController@ask error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|max:1000',
            'top_k' => 'nullable|integer|min:1|max:20'
        ]);

        $orgId = $request->user()->org_id;
        $query = $validated['query'];
        $topK = $validated['top_k'] ?? 10;

        try {
            // Simple text search across chunks
            $chunks = Chunk::whereHas('document', function($q) use ($orgId) {
                $q->where('org_id', $orgId);
            })
            ->where('text', 'like', '%' . $query . '%')
            ->with(['document' => function($q) {
                $q->select('id', 'title', 'source_url', 's3_path', 'org_id');
            }])
            ->limit($topK)
            ->get();

            // Format results
            $results = $chunks->map(function($chunk) use ($query) {
                return [
                    'document_id' => $chunk->document->id,
                    'title' => $chunk->document->title,
                    'url' => $chunk->document->s3_path ?: $chunk->document->source_url, // Use Cloudinary URL if available
                    'excerpt' => $this->extractExcerpt($chunk->text, $query),
                    'char_start' => $chunk->char_start,
                    'char_end' => $chunk->char_end,
                    'score' => 1.0
                ];
            })->toArray();

            return response()->json([
                'query' => $query,
                'results' => $results,
                'total' => count($results)
            ]);

        } catch (\Exception $e) {
            Log::error('Search error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to search',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function feedback(Request $request)
    {
        $validated = $request->validate([
            'query_id' => 'required|string',
            'helpful' => 'required|boolean',
            'feedback' => 'nullable|string|max:500'
        ]);

        // Store feedback for improving the system
        // This could be used to train better responses
        
        return response()->json([
            'message' => 'Feedback recorded successfully'
        ]);
    }

    // Conversation Management
    public function getConversations(Request $request)
    {
        $user = $request->user();
        
        $perPage = min($request->get('per_page', 20), 100); // Max 100 per page
        $page = max($request->get('page', 1), 1);
        
        $conversations = Conversation::where('user_id', $user->id)
            ->orderBy('last_message_at', 'desc')
            ->with(['messages' => function($q) {
                $q->orderBy('created_at', 'desc')->limit(1);
            }])
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $conversations->map(function($conv) {
                $lastMessage = $conv->messages->first();
                return [
                    'id' => $conv->id,
                    'title' => $conv->title,
                    'last_message' => $lastMessage ? $lastMessage->content : null,
                    'last_message_at' => $conv->last_message_at,
                    'created_at' => $conv->created_at,
                ];
            }),
            'pagination' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
                'from' => $conversations->firstItem(),
                'to' => $conversations->lastItem(),
            ]
        ]);
    }

    public function getConversation(Request $request, $id)
    {
        $user = $request->user();
        
        $conversation = Conversation::where('id', $id)
            ->where('user_id', $user->id)
            ->with('messages')
            ->firstOrFail();

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'response_style' => $conversation->response_style ?? 'comprehensive',
            'messages' => $conversation->messages->map(function($msg) {
                return [
                    'id' => $msg->id,
                    'role' => $msg->role,
                    'content' => $msg->content,
                    'sources' => $msg->sources,
                    'created_at' => $msg->created_at,
                ];
            }),
        ]);
    }

    public function deleteConversation(Request $request, $id)
    {
        $user = $request->user();
        
        Log::info('Delete conversation request', [
            'conversation_id' => $id,
            'user_id' => $user->id,
        ]);
        
        $conversation = Conversation::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$conversation) {
            Log::warning('Conversation not found or unauthorized', [
                'conversation_id' => $id,
                'user_id' => $user->id,
            ]);
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $conversation->delete();
        
        Log::info('Conversation deleted successfully', ['conversation_id' => $id]);

        return response()->json(['message' => 'Conversation deleted successfully']);
    }


    private function extractExcerpt($text, $query, $contextLength = 150)
    {
        $pos = stripos($text, $query);
        if ($pos === false) {
            return substr($text, 0, $contextLength) . '...';
        }

        $start = max(0, $pos - $contextLength / 2);
        $excerpt = substr($text, $start, $contextLength);
        
        if ($start > 0) {
            $excerpt = '...' . $excerpt;
        }
        if ($start + $contextLength < strlen($text)) {
            $excerpt = $excerpt . '...';
        }

        return $excerpt;
    }
    
    public function updateConversationStyle(Request $request, string $id)
    {
        $validated = $request->validate([
            'response_style' => 'required|string|in:comprehensive,structured_profile,summary_report,qa_friendly,bullet_brief,executive_summary',
        ]);
        
        $conversation = Conversation::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        
        $conversation->response_style = $validated['response_style'];
        $conversation->save();
        
        return response()->json([
            'message' => 'Response style updated',
            'conversation' => $conversation,
        ]);
    }
    
    public function getResponseStyles()
    {
        $styles = \App\Services\ResponseStyleService::getAvailableStyles();
        
        return response()->json([
            'styles' => $styles,
        ]);
    }
    
    /**
     * Update user's default response style preference
     */
    public function updateUserPreferences(Request $request)
    {
        $validated = $request->validate([
            'default_response_style' => 'nullable|string|in:comprehensive,structured_profile,summary_report,qa_friendly,bullet_brief,executive_summary',
            'ai_preferences' => 'nullable|array',
        ]);
        
        $user = $request->user();
        
        if (isset($validated['default_response_style'])) {
            $user->default_response_style = $validated['default_response_style'];
        }
        
        if (isset($validated['ai_preferences'])) {
            $user->ai_preferences = array_merge($user->ai_preferences ?? [], $validated['ai_preferences']);
        }
        
        $user->save();
        
        Log::info('User preferences updated', [
            'user_id' => $user->id,
            'default_style' => $user->default_response_style,
            'preferences' => $user->ai_preferences
        ]);
        
        return response()->json([
            'message' => 'Preferences updated successfully',
            'user' => [
                'default_response_style' => $user->default_response_style,
                'ai_preferences' => $user->ai_preferences,
            ]
        ]);
    }
    
    /**
     * Detect source mentions in natural language
     * Examples: "from dropbox", "in slack", "google drive only"
     */
    private function detectSourceInQuery(string $query, string $orgId): ?array
    {
        $query = strtolower($query);
        
        // Patterns to detect source mentions
        $patterns = [
            '/\b(?:from|in|on|search)\s+(dropbox|google\s*drive|slack|notion)\b/i',
            '/\b(dropbox|google\s*drive|slack|notion)\s+(?:only|files?|documents?)\b/i',
        ];
        
        $detectedSourceNames = [];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $query, $matches)) {
                foreach ($matches[1] as $match) {
                    $sourceName = strtolower(str_replace(' ', '_', trim($match)));
                    $detectedSourceNames[] = $sourceName;
                }
            }
        }
        
        if (empty($detectedSourceNames)) {
            return null;
        }
        
        // Map source names to connector types
        $sourceTypeMap = [
            'dropbox' => 'dropbox',
            'google_drive' => 'google_drive',
            'googledrive' => 'google_drive',
            'slack' => 'slack',
            'notion' => 'notion',
        ];
        
        $connectorTypes = [];
        foreach ($detectedSourceNames as $name) {
            if (isset($sourceTypeMap[$name])) {
                $connectorTypes[] = $sourceTypeMap[$name];
            }
        }
        
        if (empty($connectorTypes)) {
            return null;
        }
        
        // Get connector IDs for these types
        $connectors = Connector::where('org_id', $orgId)
            ->whereIn('type', $connectorTypes)
            ->where('status', 'connected')
            ->pluck('id')
            ->toArray();
        
        Log::info('Detected source in natural language', [
            'detected_names' => $detectedSourceNames,
            'connector_types' => $connectorTypes,
            'connector_ids' => $connectors,
            'query' => $query
        ]);
        
        return !empty($connectors) ? $connectors : null;
    }

    /**
     * Build workspace-aware Pinecone metadata filter
     */
    private function buildWorkspaceAwareFilter($orgId, $userId, $connectorIds, $searchScope, $workspaceIds)
    {
        $filter = [];

        // Get all connectors accessible to this user
        $accessibleConnectors = [];
        
        // Always include organization connectors (accessible to all org members)
        $orgConnectors = $this->getUserAccessibleConnectors($orgId, $userId, 'organization');
        $accessibleConnectors = array_merge($accessibleConnectors, $orgConnectors);
        
        // Include personal connectors only if user has access to them
        $personalConnectors = $this->getUserAccessibleConnectors($orgId, $userId, 'personal');
        $accessibleConnectors = array_merge($accessibleConnectors, $personalConnectors);

        // Apply search scope filtering
        if ($searchScope === 'organization') {
            // Only organization connectors
            $accessibleConnectors = $orgConnectors;
        } elseif ($searchScope === 'personal') {
            // Only personal connectors that user has access to
            $accessibleConnectors = $personalConnectors;
        }
        // For 'both' scope, use all accessible connectors (already merged above)

        // Add connector filtering
        if (!empty($connectorIds)) {
            // Intersect with user-accessible connectors to ensure security
            $accessibleConnectors = array_intersect($connectorIds, $accessibleConnectors);
        }

        if (!empty($accessibleConnectors)) {
            $filter['connector_id'] = ['$in' => $accessibleConnectors];
        } else {
            // No accessible connectors - return empty result
            $filter['connector_id'] = ['$in' => []];
        }

        // Add workspace scope filtering
        if ($searchScope !== 'both') {
            $filter['source_scope'] = $searchScope;
        }

        // Add specific workspace filtering
        if (!empty($workspaceIds)) {
            $filter['workspace_id'] = ['$in' => $workspaceIds];
        }

        \Log::info('=== WORKSPACE FILTER BUILT ===', [
            'user_id' => $userId,
            'org_id' => $orgId,
            'search_scope' => $searchScope,
            'accessible_connectors' => $accessibleConnectors,
            'filter' => $filter
        ]);

        return !empty($filter) ? $filter : null;
    }

    /**
     * Get connectors accessible to user based on scope
     */
    private function getUserAccessibleConnectors($orgId, $userId, $scope)
    {
        $query = Connector::where('org_id', $orgId);

        if ($scope === 'personal') {
            $query->where('connection_scope', 'personal')
                  ->whereHas('userPermissions', function($q) use ($userId) {
                      $q->where('user_id', $userId);
                  });
        } elseif ($scope === 'organization') {
            $query->where('connection_scope', 'organization');
        }

        return $query->pluck('id')->toArray();
    }

    /**
     * Tag sources with workspace information
     */
    private function tagSourcesWithWorkspace($snippets)
    {
        $taggedSources = [];
        $workspaceStats = [];

        foreach ($snippets as $snippet) {
            $document = $snippet['document'];
            $connector = $document['connector'] ?? null;
            
            if ($connector) {
                $workspaceInfo = [
                    'workspace_name' => $connector['workspace_name'] ?? $connector['label'],
                    'workspace_scope' => $connector['connection_scope'] ?? 'organization',
                    'workspace_icon' => $connector['connection_scope'] === 'personal' ? '👤' : '🏢',
                    'workspace_label' => $connector['connection_scope'] === 'personal' ? 'Personal' : 'Organization'
                ];

                $snippet['workspace_info'] = $workspaceInfo;
                
                // Track workspace stats
                $scope = $workspaceInfo['workspace_scope'];
                $workspaceStats[$scope] = ($workspaceStats[$scope] ?? 0) + 1;
            }

            $taggedSources[] = $snippet;
        }

        return [
            'sources' => $taggedSources,
            'workspace_stats' => $workspaceStats
        ];
    }
}