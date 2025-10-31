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
            'workspace_ids.*' => 'string',
            'max_sources' => 'nullable|integer|min:1|max:10', // Max visible sources override
        ]);

        $queryText = $validated['query'];
        $topK = $validated['top_k'] ?? 10; // Reduced from 15 to 10 for faster responses (balance: quality vs speed)
        $conversationId = $validated['conversation_id'] ?? null;
        $requestedConnectorIds = $validated['connector_ids'] ?? null;
        $searchScope = $validated['search_scope'] ?? 'both';
        $workspaceIds = $validated['workspace_ids'] ?? null;
        $maxSourcesOverride = isset($validated['max_sources']) ? (int) $validated['max_sources'] : null;
        
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
                
                // Build workspace-aware vector metadata filter
                $vectorFilter = $this->buildWorkspaceAwareFilter($orgId, $user->id, $connectorIds, $searchScope, $workspaceIds);
                
                $matches = $vectorStore->query($embedding, $topK, $orgId, $orgId, $conversation->id, $vectorFilter);
            $chunkIds = array_map(fn($m) => $m['id'], $matches);
            
            Log::info('🔍 Vector search results', [
                'user_id' => $user->id,
                'org_id' => $orgId,
                'search_scope' => $searchScope,
                'vector_filter' => $vectorFilter,
                'matches_count' => count($matches),
                'chunk_ids' => $chunkIds
            ]);

            if (empty($chunkIds)) {
                // FALLBACK: Check if user has any documents at all, if not, provide getting started guidance
                $userDocumentCount = Document::where('org_id', $orgId)->count();
                
                if ($userDocumentCount === 0) {
                    // New user with no documents - provide getting started guidance
                    $gettingStartedResponse = $this->getGettingStartedGuidance($queryText);
                    
                    return response()->json([
                        'answer' => $gettingStartedResponse,
                        'sources' => [],
                        'query' => $queryText,
                        'result_count' => 0,
                        'is_guidance' => true,
                        'raw' => null
                    ]);
                } else {
                    // User has documents but no relevant matches found
                    return response()->json([
                        'answer' => "I don't know — no relevant documents found.",
                        'sources' => [],
                        'query' => $queryText,
                        'result_count' => 0,
                        'raw' => null
                    ]);
                }
            }

                // 3. Fetch chunk rows with SECURITY FILTERING
                $chunks = Chunk::whereIn('id', $chunkIds)
                    ->select('id', 'document_id', 'org_id', 'chunk_index', 'text', 'char_start', 'char_end', 'token_count', 'source_scope', 'workspace_name')
                    ->with(['document' => function($q) {
                        $q->select('id', 'title', 'source_url', 's3_path', 'org_id', 'connector_id', 'doc_type', 'tags', 'metadata', 'source_scope', 'user_id');
                    }, 'document.connector.userPermissions'])
                    ->get()
                    ->keyBy('id');
                
                // CRITICAL SECURITY: Filter chunks by user permissions
                $filteredChunks = [];
                foreach ($chunks as $chunk) {
                    $connector = $chunk->document->connector ?? null;
                    
                    // System documents (connector_id = null) are accessible to all users
                    if (!$connector) {
                        $filteredChunks[$chunk->id] = $chunk;
                        Log::info('✅ System document access granted', [
                            'user_id' => $user->id,
                            'chunk_id' => $chunk->id,
                            'document_id' => $chunk->document_id,
                            'document_title' => $chunk->document->title ?? 'Unknown'
                        ]);
                        continue;
                    }
                    
                    // Check user access to this chunk's document based on DOCUMENT source_scope
                    if (self::userHasAccessToChunkByDocumentScope($chunk, $user->id)) {
                        $filteredChunks[$chunk->id] = $chunk;
                    } else {
                        Log::info('🚫 User denied access to chunk', [
                            'user_id' => $user->id,
                            'chunk_id' => $chunk->id,
                            'document_id' => $chunk->document_id,
                            'connector_id' => $connector->id,
                            'chunk_source_scope' => $chunk->source_scope,
                            'document_source_scope' => $chunk->document->source_scope ?? 'unknown'
                        ]);
                    }
                }
                
                $chunks = $filteredChunks;
                
                Log::info('🔒 Security filtering results', [
                    'user_id' => $user->id,
                    'original_chunks' => count($chunkIds),
                    'filtered_chunks' => count($filteredChunks),
                    'access_denied_count' => count($chunkIds) - count($filteredChunks)
                ]);

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
            
            // Intelligent name matching for person queries
            $nameMatchingResult = null;
            if (self::isPersonQuery($queryText)) {
                $nameMatchingResult = \App\Services\NameMatchingService::findNameMatches($queryText, $snippets, $orgId, $user->id);
                
                // If no exact match found, provide intelligent response
                if ($nameMatchingResult['no_matches'] || $nameMatchingResult['confidence'] < 0.5) {
                    $intelligentResponse = \App\Services\NameMatchingService::generateIntelligentResponse($nameMatchingResult, $queryText);
                    
                    // Create a message with the intelligent response
                    Message::create([
                        'conversation_id' => $conversation->id,
                        'role' => 'assistant',
                        'content' => $intelligentResponse,
                        'sources' => [],
                    ]);
                    
                    return response()->json([
                        'answer' => $intelligentResponse,
                        'sources' => [],
                        'query' => $queryText,
                        'result_count' => 0,
                        'conversation_id' => $conversation->id,
                        'route_type' => 'intelligent_name_matching',
                        'name_matching' => $nameMatchingResult,
                    ]);
                }
            }
            
            // Get enhanced context based on routing decision
            $enhancedContext = \App\Services\ContextRouter::buildEnhancedContext($routing, $conversation->id, $conversationHistory);
            
            // Log context before RAG processing
            Log::info('🧠 RAG CONTEXT ASSEMBLY', [
                'user_id' => $user->id,
                'query' => $queryText,
                'snippets_count' => count($snippets),
                'snippet_previews' => array_map(function($s) {
                    return [
                        'chunk_id' => $s['chunk_id'],
                        'document_id' => $s['document_id'],
                        'score' => $s['score'],
                        'text_preview' => mb_substr($s['text'], 0, 100) . '...'
                    ];
                }, array_slice($snippets, 0, 3)),
                'response_style' => $responseStyle,
                'routing' => $routing,
                'enhanced_context_length' => is_array($enhancedContext['conversation_history'] ?? null) ? count($enhancedContext['conversation_history']) : strlen($enhancedContext['conversation_history'] ?? '')
            ]);
            
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
            
            Log::info('📝 RAG PROMPT ASSEMBLED', [
                'user_id' => $user->id,
                'prompt_length' => strlen($prompt),
                'prompt_preview' => mb_substr($prompt, 0, 500) . '...',
                'max_tokens' => $maxTokens,
                'style' => $responseStyle,
                'detail_level' => $styleConfig['config']['detail_level']
            ]);
            
            $llmResponse = $rag->callLLM($prompt, $maxTokens, $orgId, $user->id, $conversation->id, $queryText);
            
            Log::info('🤖 LLM RESPONSE RECEIVED', [
                'user_id' => $user->id,
                'response_type' => gettype($llmResponse),
                'response_preview' => is_string($llmResponse) ? mb_substr($llmResponse, 0, 200) . '...' : 'Non-string response',
                'response_length' => is_string($llmResponse) ? strlen($llmResponse) : 'N/A',
                'has_answer_key' => isset($llmResponse['answer']),
                'answer_preview' => isset($llmResponse['answer']) ? mb_substr($llmResponse['answer'], 0, 200) . '...' : 'No answer key'
            ]);

            // Parse and normalize the LLM response into a strict contract
            $rawAnswer = $llmResponse['answer'] ?? '';
            $answerText = is_string($rawAnswer) ? $rawAnswer : '';
            $llmSources = [];
            
            Log::info('📋 PARSING LLM RESPONSE', [
                'user_id' => $user->id,
                'raw_answer_type' => gettype($rawAnswer),
                'raw_answer_preview' => is_string($rawAnswer) ? mb_substr($rawAnswer, 0, 300) . '...' : 'Non-string',
                'answer_text_preview' => is_string($answerText) ? mb_substr($answerText, 0, 300) . '...' : 'Non-string'
            ]);

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
            
            Log::info('🔍 PROCESSING SNIPPETS FOR SOURCES', [
                'user_id' => $user->id,
                'snippets_count' => count($snippets),
                'query' => $queryText,
                'snippet_previews' => array_map(function($s) {
                    return [
                        'chunk_id' => $s['chunk_id'],
                        'document_id' => $s['document_id'],
                        'score' => $s['score'],
                        'text_preview' => mb_substr($s['text'], 0, 100) . '...'
                    ];
                }, array_slice($snippets, 0, 3))
            ]);
            
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
                        'connector' => $connector ? [
                            'id' => $connector->id,
                            'type' => $connector->type,
                            'label' => $connector->label,
                            'connection_scope' => $chunk->document->source_scope, // Use document's source_scope, not connector's
                            'workspace_name' => $connector->workspace_name,
                        ] : null,
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
            
            Log::info('📚 BUILDING FINAL SOURCES', [
                'user_id' => $user->id,
                'sources_by_document_count' => count($sourcesByDocument),
                'source_documents' => array_map(function($source) {
                    return [
                        'document_id' => $source['document_id'],
                        'title' => $source['title'],
                        'type' => $source['type'],
                        'chunk_count' => $source['chunk_count'],
                        'connection_scope' => $source['connector']['connection_scope'] ?? 'unknown'
                    ];
                }, $sourcesByDocument)
            ]);
            
            foreach ($sourcesByDocument as $documentId => $source) {
                // Combine excerpts with separator, limit to 2-3 most relevant
                $topExcerpts = array_slice($source['excerpts'], 0, 3);
                $combinedExcerpt = implode("\n\n...\n\n", $topExcerpts);
                
                // Get workspace information from connector
                $workspaceInfo = null;
                if (isset($source['connector'])) {
                    $connector = $source['connector'];
                    $documentScope = $connector['connection_scope']; // This was set to document's source_scope on line 531
                    
                    Log::info('🔍 WORKSPACE INFO BUILDING', [
                        'document_id' => $documentId,
                        'document_title' => $source['title'],
                        'connector_connection_scope' => $connector['connection_scope'],
                        'document_scope_used' => $documentScope,
                        'workspace_name' => $connector['workspace_name'] ?? $connector['label']
                    ]);
                    
                    $workspaceInfo = [
                        'workspace_name' => $connector['workspace_name'] ?? $connector['label'],
                        'workspace_scope' => $documentScope, // Use the document's actual scope
                        'workspace_icon' => $documentScope === 'personal' ? '👤' : '🏢',
                        'workspace_label' => $documentScope === 'personal' ? 'Personal' : 'Organization'
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

            // Smart source filtering based on query complexity and relevance (with optional override)
            $filteredSources = $this->filterSourcesIntelligently($formattedSources, $queryText, $maxSourcesOverride);

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

        // Final response logging
        Log::info('🎯 FINAL RESPONSE ASSEMBLED', [
            'user_id' => $user->id,
            'query' => $queryText,
            'answer_length' => strlen($answerText),
            'answer_preview' => mb_substr($answerText, 0, 200) . '...',
            'sources_count' => count($filteredSources),
            'workspace_stats' => $workspaceStats,
            'search_scope' => $searchScope,
            'conversation_id' => $conversation->id
        ]);
        
        return response()->json([
            'answer' => $answerText,
            'sources' => $filteredSources,
            'all_sources' => $formattedSources,
            'result_count' => count($filteredSources),
            'all_sources_count' => count($formattedSources),
            'query' => $queryText,
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
     * Build workspace-aware vector metadata filter
     */
    private function buildWorkspaceAwareFilter($orgId, $userId, $connectorIds, $searchScope, $workspaceIds)
    {
        $filter = [];

        // NEW APPROACH: Filter by document source_scope instead of connector scope
        // This allows individual documents to be shared regardless of their connector
        
        // Get all connectors accessible to this user (for security)
        $accessibleConnectors = [];
        
        // Always include organization connectors (accessible to all org members)
        $orgConnectors = $this->getUserAccessibleConnectors($orgId, $userId, 'organization');
        $accessibleConnectors = array_merge($accessibleConnectors, $orgConnectors);
        
        // Include personal connectors only if user has access to them
        $personalConnectors = $this->getUserAccessibleConnectors($orgId, $userId, 'personal');
        $accessibleConnectors = array_merge($accessibleConnectors, $personalConnectors);

        // Apply search scope filtering
        if ($searchScope === 'organization') {
            // For organization scope: include ALL connectors in the org (for shared documents)
            // plus user's accessible connectors (for security)
            $allOrgConnectors = \App\Models\Connector::where('org_id', $orgId)->pluck('id')->toArray();
            $accessibleConnectors = array_unique(array_merge($allOrgConnectors, $accessibleConnectors));
        } elseif ($searchScope === 'personal') {
            // Only personal connectors that user has access to
            $accessibleConnectors = $personalConnectors;
        } else {
            // For 'both' scope: include ALL connectors in the org (for shared documents)
            // plus user's accessible connectors (for security)
            $allOrgConnectors = \App\Models\Connector::where('org_id', $orgId)->pluck('id')->toArray();
            $accessibleConnectors = array_unique(array_merge($allOrgConnectors, $accessibleConnectors));
        }
        
        // CRITICAL FIX: If user has selected specific connectors, ONLY search those connectors
        // This respects the user's explicit connector selection and prevents cross-contamination
        // between personal and organization scopes when user wants to search specific connectors
        if (!empty($connectorIds)) {
            Log::info('🔍 USER SELECTED SPECIFIC CONNECTORS - RESPECTING SELECTION', [
                'user_id' => $userId,
                'selected_connectors' => $connectorIds,
                'original_accessible_count' => count($accessibleConnectors),
                'search_scope' => $searchScope
            ]);
            
            // ONLY use the selected connectors - respect user's choice
            $accessibleConnectors = $connectorIds;
            
            Log::info('✅ RESPECTING USER SELECTION', [
                'user_id' => $userId,
                'final_accessible_count' => count($accessibleConnectors),
                'selected_connectors_only' => $connectorIds
            ]);
        }

        // Connector filtering is now handled above - respect user's selection

        if (!empty($accessibleConnectors)) {
            if (!empty($connectorIds)) {
                // When user explicitly selects connectors, EXCLUDE system docs
                $filter['connector_id'] = ['$in' => $accessibleConnectors];
            } else {
                // Include both accessible connectors AND system documents (connector_id = null)
                $filter['connector_id'] = ['$in' => array_merge($accessibleConnectors, [null])];
            }
        } else {
            // Even if no connectors, include system documents for getting started guide
            $filter['connector_id'] = ['$in' => [null]];
        }

        // CRITICAL: Add source_scope filtering based on user access
        if ($searchScope === 'organization') {
            // Only organization-scoped documents
            $filter['source_scope'] = 'organization';
        } elseif ($searchScope === 'personal') {
            // Only personal-scoped documents that user owns
            $filter['source_scope'] = 'personal';
            $filter['user_id'] = $userId; // Only documents uploaded by this user
        } else {
            // For 'both' scope: organization docs for all + personal docs for this user
            // Pass user_id to VectorStoreService to handle mixed scope filtering
            $filter['user_id'] = $userId;
        }

        // Add specific workspace filtering
        if (!empty($workspaceIds)) {
            $filter['workspace_id'] = ['$in' => $workspaceIds];
        }

        Log::info('=== WORKSPACE FILTER BUILT ===', [
            'user_id' => $userId,
            'org_id' => $orgId,
            'search_scope' => $searchScope,
            'accessible_connectors' => $accessibleConnectors,
            'filter' => $filter,
            'workspace_ids' => $workspaceIds
        ]);

        return !empty($filter) ? $filter : null;
    }

    /**
     * CRITICAL SECURITY: Check if user has access to a chunk based on DOCUMENT source_scope
     */
    private function userHasAccessToChunkByDocumentScope($chunk, string $userId): bool
    {
        // Organization-scoped documents: accessible to all org members
        if ($chunk->source_scope === 'organization') {
            Log::info('✅ Organization document access granted', [
                'user_id' => $userId,
                'chunk_id' => $chunk->id,
                'chunk_source_scope' => $chunk->source_scope
            ]);
            return true;
        }
        
        // Personal-scoped documents: only accessible to the user who uploaded them
        if ($chunk->source_scope === 'personal') {
            $hasAccess = $chunk->document && $chunk->document->user_id == $userId;
            Log::info('🔍 Personal document access check', [
                'user_id' => $userId,
                'chunk_id' => $chunk->id,
                'chunk_source_scope' => $chunk->source_scope,
                'document_user_id' => $chunk->document ? $chunk->document->user_id : 'no_document',
                'has_access' => $hasAccess
            ]);
            return $hasAccess;
        }
        
        // Default: deny access for unknown scopes
        Log::info('🚫 Unknown scope - access denied', [
            'user_id' => $userId,
            'chunk_id' => $chunk->id,
            'chunk_source_scope' => $chunk->source_scope
        ]);
        return false;
    }

    /**
     * LEGACY: Check if user has access to a chunk based on connector scope
     * @deprecated Use userHasAccessToChunkByDocumentScope instead
     */
    private function userHasAccessToChunk($connector, string $userId): bool
    {
        // Organization connectors: accessible to all org members
        if ($connector->connection_scope === 'organization') {
            return true;
        }
        
        // Personal connectors: only accessible to users with explicit permissions
        if ($connector->connection_scope === 'personal') {
            return $connector->userPermissions()
                ->where('user_id', $userId)
                ->exists();
        }
        
        // Default: deny access for unknown scopes
        return false;
    }

    /**
     * Check if query is asking about a person
     */
    private function isPersonQuery(string $query): bool
    {
        $query = strtolower(trim($query));
        
        // Patterns that indicate person queries
        $personPatterns = [
            '/\btell\s+me\s+about\s+([a-z\s]+)/',
            '/\bwho\s+is\s+([a-z\s]+)/',
            '/\bwhat\s+about\s+([a-z\s]+)/',
            '/\b([a-z\s]+)\s+background/',
            '/\b([a-z\s]+)\s+experience/',
            '/\b([a-z\s]+)\s+skills/',
            '/\b([a-z\s]+)\s+education/',
            '/\b([a-z\s]+)\s+qualification/',
        ];
        
        foreach ($personPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }
        
        // Check if query contains what looks like a person's name (2-3 words, proper case)
        if (preg_match('/\b([A-Z][a-z]+\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/', $query)) {
            return true;
        }
        
        return false;
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

    /**
     * Intelligently filter sources based on query complexity and relevance
     */
    private function filterSourcesIntelligently(array $sources, string $query, ?int $maxSourcesOverride = null): array
    {
        $query = strtolower(trim($query));
        $queryLength = strlen($query);
        $queryWords = str_word_count($query);
        
        // Simple greetings or short queries should have fewer sources
        $isSimpleGreeting = in_array($query, ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening']);
        $isShortQuery = $queryLength <= 10 || $queryWords <= 2;
        
        // Calculate relevance threshold based on query complexity
        $baseThreshold = 0.2; // Lower base threshold
        $relevanceThreshold = $baseThreshold;
        
        if ($isSimpleGreeting) {
            // For greetings, only show very relevant sources (high threshold)
            $relevanceThreshold = 0.5; // Reduced from 0.6
            $maxSources = 1;
        } elseif ($isShortQuery) {
            // For short queries, be more selective
            $relevanceThreshold = 0.3; // Reduced from 0.5
            $maxSources = 2;
        } elseif ($queryWords <= 5) {
            // Medium complexity queries
            $relevanceThreshold = 0.25; // Reduced from 0.4
            $maxSources = 3;
        } else {
            // Complex queries can have more sources
            $relevanceThreshold = 0.2; // Reduced from 0.3
            $maxSources = 5;
        }
        
        // Apply explicit override if provided
        if ($maxSourcesOverride !== null) {
            $maxSources = max(1, min(10, (int) $maxSourcesOverride));
        }
        
        // Filter by relevance threshold
        $filteredSources = array_filter($sources, function($source) use ($relevanceThreshold) {
            return ($source['score'] ?? 0) >= $relevanceThreshold;
        });
        
        // FALLBACK: If no sources pass the threshold, use the top sources anyway
        if (empty($filteredSources) && !empty($sources)) {
            Log::info('No sources passed relevance threshold, using top sources as fallback', [
                'query' => $query,
                'relevance_threshold' => $relevanceThreshold,
                'top_scores' => array_slice(array_map(function($s) { return $s['score'] ?? 0; }, $sources), 0, 3)
            ]);
            
            // Sort by score and take the top sources
            usort($sources, function($a, $b) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });
            $filteredSources = array_slice($sources, 0, min(3, count($sources)));
        }
        
        // SEMANTIC RELEVANCE FILTERING - Prioritize sources that match query context
        $semanticallyRelevantSources = $this->prioritizeSemanticRelevance($filteredSources, $query);
        
        // CRITICAL FIX: Remove duplicates based on title similarity BUT consider scope information
        // This prevents the same document from appearing twice when it exists in both personal and organization scopes
        // Priority: Organization scope > Personal scope (organization documents are more widely accessible)
        $uniqueSources = [];
        $seenTitles = [];
        
        foreach ($semanticallyRelevantSources as $source) {
            $title = strtolower($source['title'] ?? '');
            $sourceScope = $source['workspace_info']['workspace_scope'] ?? 'organization';
            $isDuplicate = false;
            $shouldReplace = false;
            $replaceIndex = -1;
            
            // Check for similar titles (fuzzy matching) but consider scope
            foreach ($seenTitles as $index => $seenTitle) {
                $similarity = similar_text($title, $seenTitle, $percent);
                if ($percent > 80) { // 80% similarity threshold
                    $isDuplicate = true;
                    $existingScope = $uniqueSources[$index]['workspace_info']['workspace_scope'] ?? 'organization';
                    
                    // CRITICAL FIX: Prioritize organization scope over personal scope
                    if ($sourceScope === 'organization' && $existingScope === 'personal') {
                        $shouldReplace = true;
                        $replaceIndex = $index;
                        Log::info('🔄 SCOPE PRIORITY: Replacing personal with organization scope', [
                            'title' => $title,
                            'existing_scope' => $existingScope,
                            'new_scope' => $sourceScope
                        ]);
                        break;
                    } elseif ($sourceScope === 'personal' && $existingScope === 'organization') {
                        // Keep organization scope, skip personal
                        Log::info('🔄 SCOPE PRIORITY: Keeping organization scope over personal', [
                            'title' => $title,
                            'existing_scope' => $existingScope,
                            'new_scope' => $sourceScope
                        ]);
                        break;
                    } else {
                        // Same scope, keep first one
                        break;
                    }
                }
            }
            
            if (!$isDuplicate) {
                $uniqueSources[] = $source;
                $seenTitles[] = $title;
            } elseif ($shouldReplace) {
                // Replace personal with organization scope
                $uniqueSources[$replaceIndex] = $source;
                $seenTitles[$replaceIndex] = $title;
            }
            
            // Stop if we've reached the maximum
            if (count($uniqueSources) >= $maxSources) {
                break;
            }
        }
        
        Log::info('Smart source filtering applied', [
            'original_count' => count($sources),
            'filtered_count' => count($uniqueSources),
            'query' => $query,
            'is_simple_greeting' => $isSimpleGreeting,
            'is_short_query' => $isShortQuery,
            'relevance_threshold' => $relevanceThreshold,
            'max_sources' => $maxSources,
            'scope_breakdown' => array_map(function($source) {
                return [
                    'title' => $source['title'],
                    'scope' => $source['workspace_info']['workspace_scope'] ?? 'unknown',
                    'score' => $source['score'] ?? 0
                ];
            }, $uniqueSources)
        ]);
        
        return $uniqueSources;
    }

    /**
     * Prioritize sources based on semantic relevance to the query
     */
    private function prioritizeSemanticRelevance(array $sources, string $query): array
    {
        // Extract key terms from query for semantic matching
        $queryTerms = $this->extractKeyTerms($query);
        
        // Score each source based on semantic relevance
        $scoredSources = [];
        foreach ($sources as $source) {
            $semanticScore = $this->calculateSemanticScore($source, $queryTerms, $query);
            $scoredSources[] = [
                'source' => $source,
                'semantic_score' => $semanticScore,
                'original_score' => $source['score'] ?? 0
            ];
        }
        
        // Sort by semantic relevance (higher is better)
        usort($scoredSources, function($a, $b) {
            return $b['semantic_score'] <=> $a['semantic_score'];
        });
        
        // Return sources in order of semantic relevance
        return array_map(function($item) {
            return $item['source'];
        }, $scoredSources);
    }

    /**
     * Extract key terms from query for semantic matching
     */
    private function extractKeyTerms(string $query): array
    {
        $query = strtolower($query);
        
        // Common stop words to ignore
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'can', 'must', 'shall', 'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'me', 'him', 'her', 'us', 'them', 'my', 'your', 'his', 'her', 'its', 'our', 'their'];
        
        // Extract meaningful words
        $words = preg_split('/\s+/', $query);
        $keyTerms = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });
        
        return array_values($keyTerms);
    }

    /**
     * Calculate semantic relevance score for a source
     */
    private function calculateSemanticScore(array $source, array $queryTerms, string $query): float
    {
        $score = 0.0;
        $title = strtolower($source['title'] ?? '');
        $type = strtolower($source['type'] ?? '');
        $excerpt = strtolower($source['excerpt'] ?? '');
        
        // 1. Platform/Type matching (reduced weight)
        if (strpos($query, 'slack') !== false && strpos($type, 'slack') !== false) {
            $score += 0.2; // Reduced from 0.4
        }
        if (strpos($query, 'notion') !== false && strpos($type, 'notion') !== false) {
            $score += 0.2; // Reduced from 0.4
        }
        if (strpos($query, 'google') !== false && strpos($type, 'google') !== false) {
            $score += 0.2; // Reduced from 0.4
        }
        if (strpos($query, 'dropbox') !== false && strpos($type, 'dropbox') !== false) {
            $score += 0.2; // Reduced from 0.4
        }
        
        // 2. Title relevance (reduced weight)
        foreach ($queryTerms as $term) {
            if (strpos($title, $term) !== false) {
                $score += 0.1; // Reduced from 0.2
            }
        }
        
        // 3. Content relevance (reduced weight)
        foreach ($queryTerms as $term) {
            if (strpos($excerpt, $term) !== false) {
                $score += 0.05; // Reduced from 0.1
            }
        }
        
        // 4. Document type relevance (reduced weight)
        if (strpos($query, 'conversation') !== false && strpos($title, 'conversation') !== false) {
            $score += 0.15; // Reduced from 0.3
        }
        if (strpos($query, 'discuss') !== false && strpos($title, 'conversation') !== false) {
            $score += 0.15; // Reduced from 0.3
        }
        if (strpos($query, 'resume') !== false && strpos($source['doc_type'] ?? '', 'resume') !== false) {
            $score += 0.15; // Reduced from 0.3
        }
        
        // 5. Base relevance bonus - give all sources a small boost
        $score += 0.1;
        
        return min(1.0, $score); // Cap at 1.0
    }

    /**
     * Provide getting started guidance for new users
     */
    private function getGettingStartedGuidance(string $query): string
    {
        $query = strtolower($query);
        
        // Check if user is asking about specific topics
        if (strpos($query, 'how') !== false || strpos($query, 'what') !== false || strpos($query, 'help') !== false) {
            return "🎉 Welcome to KHub! I'm your AI assistant, but I don't see any documents in your knowledge base yet.\n\n" .
                   "**To get started:**\n" .
                   "1. **Connect your cloud sources** - Go to 'Connectors' in the sidebar\n" .
                   "2. **Sync your data** - Click 'Sync' on any connected source\n" .
                   "3. **Ask questions** - Once synced, I can help you find information!\n\n" .
                   "**Popular connectors:**\n" .
                   "• **Google Drive** - Access your files and documents\n" .
                   "• **Slack** - Search through team conversations\n" .
                   "• **Dropbox** - Sync your cloud storage\n\n" .
                   "Once you have documents synced, I'll be able to answer questions like:\n" .
                   "• 'Show me all reports from last month'\n" .
                   "• 'Find conversations about the project'\n" .
                   "• 'Summarize the meeting notes'\n\n" .
                   "Ready to connect your first source? Click 'Connectors' to get started! 🚀";
        }
        
        if (strpos($query, 'connect') !== false || strpos($query, 'sync') !== false) {
            return "🔗 **Connecting Sources to KHub:**\n\n" .
                   "1. **Navigate to Connectors** - Click 'Connectors' in the left sidebar\n" .
                   "2. **Choose your source** - Select Google Drive, Slack, Dropbox, or others\n" .
                   "3. **Authorize access** - Grant KHub permission to read your files\n" .
                   "4. **Click Sync** - Watch as your data gets indexed\n" .
                   "5. **Start asking questions** - Once synced, I can help you find information!\n\n" .
                   "**Security:** Your data stays encrypted and secure. We only read files you authorize.\n\n" .
                   "Need help? Check the 'Getting Started' guide or contact support!";
        }
        
        if (strpos($query, 'search') !== false || strpos($query, 'find') !== false) {
            return "🔍 **Searching in KHub:**\n\n" .
                   "I'd love to help you search, but I don't see any documents in your knowledge base yet!\n\n" .
                   "**To enable search:**\n" .
                   "1. **Connect sources** - Go to 'Connectors' and link your cloud storage\n" .
                   "2. **Sync data** - Click 'Sync' to index your files\n" .
                   "3. **Ask questions** - Once synced, I can search through everything!\n\n" .
                   "**Example searches after syncing:**\n" .
                   "• 'Find all PDFs about marketing'\n" .
                   "• 'Show me Slack conversations from yesterday'\n" .
                   "• 'What documents mention the budget?'\n\n" .
                   "Ready to connect your first source? Let's get started! 🚀";
        }
        
        // Default response for any other queries
        return "👋 **Welcome to KHub!**\n\n" .
               "I'm your AI assistant, but I don't see any documents in your knowledge base yet.\n\n" .
               "**Quick Start Guide:**\n" .
               "1. **📁 Connect Sources** - Go to 'Connectors' in the sidebar\n" .
               "2. **🔄 Sync Data** - Click 'Sync' on any connected source\n" .
               "3. **💬 Ask Questions** - Once synced, I can help you find information!\n\n" .
               "**Popular Integrations:**\n" .
               "• **Google Drive** - Access your files and documents\n" .
               "• **Slack** - Search team conversations\n" .
               "• **Dropbox** - Sync your cloud storage\n" .
               "• **Notion** - Index your workspace pages\n\n" .
               "**Once synced, try asking:**\n" .
               "• 'What documents do I have about [topic]?'\n" .
               "• 'Find conversations about [project]'\n" .
               "• 'Summarize the latest reports'\n\n" .
               "Ready to connect your first source? Click 'Connectors' to get started! 🎉";
    }
}