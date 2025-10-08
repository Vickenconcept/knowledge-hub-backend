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
        
        $validated = $request->validate([
            'query' => 'required|string',
            'conversation_id' => 'nullable|string|uuid',
            'top_k' => 'nullable|integer|min:1|max:50'
        ]);

        $queryText = $validated['query'];
        $topK = $validated['top_k'] ?? 15; // Increased from 6 to 15 for more comprehensive results
        $conversationId = $validated['conversation_id'] ?? null;

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
            $embedding = $embeddingService->embed($queryText);
                $matches = $vectorStore->query($embedding, $topK, $orgId);
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
            
            $llmResponse = $rag->callLLM($prompt, $maxTokens);

            // Parse the LLM response (it returns JSON with answer + sources)
            $parsedAnswer = json_decode($llmResponse['answer'] ?? '{}', true);
            $answerText = is_array($parsedAnswer) ? ($parsedAnswer['answer'] ?? $llmResponse['answer']) : $llmResponse['answer'];
            
            // Convert literal \n to actual newlines for better formatting
            $answerText = str_replace(['\\n', '\n'], "\n", $answerText);
            
            $llmSources = is_array($parsedAnswer) ? ($parsedAnswer['sources'] ?? []) : [];

            // 5. Format sources with full details (title, URL, excerpt, type)
            // Map the LLM's numbered sources back to actual chunks
            $formattedSources = [];
            foreach ($snippets as $idx => $snippet) {
                $chunk = $chunks[$snippet['chunk_id']] ?? null;
                if (!$chunk || !$chunk->document) continue;

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
                }

                $formattedSources[] = [
                    'chunk_id' => $snippet['chunk_id'],
                    'document_id' => $snippet['document_id'],
                    'title' => $chunk->document->title,
                    'url' => $chunk->document->s3_path ?: $chunk->document->source_url, // Use Cloudinary URL if available, fallback to source_url
                    'excerpt' => mb_substr($snippet['text'], 0, 300),
                    'char_start' => $snippet['char_start'],
                    'char_end' => $snippet['char_end'],
                    'score' => $snippet['score'] ?? null,
                    'type' => $sourceType,
                    'doc_type' => $chunk->document->doc_type,
                    'tags' => $chunk->document->tags ?? [],
                    'metadata' => $chunk->document->metadata ?? null,
                ];
            }

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
        
        $conversations = Conversation::where('user_id', $user->id)
            ->orderBy('last_message_at', 'desc')
            ->with(['messages' => function($q) {
                $q->orderBy('created_at', 'desc')->limit(1);
            }])
            ->get();

        return response()->json($conversations->map(function($conv) {
            $lastMessage = $conv->messages->first();
            return [
                'id' => $conv->id,
                'title' => $conv->title,
                'last_message' => $lastMessage ? $lastMessage->content : null,
                'last_message_at' => $conv->last_message_at,
                'created_at' => $conv->created_at,
            ];
        }));
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
}