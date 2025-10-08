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
            // Create new conversation
            $conversation = Conversation::create([
                'org_id' => $orgId,
                'user_id' => $user->id,
                'title' => mb_substr($queryText, 0, 50), // Use first query as title
                'last_message_at' => now(),
            ]);
        }

        // Save user message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $queryText,
        ]);

        try {
            // 1. Create embedding for query
            $embedding = $embeddingService->embed($queryText);

            // 2. Query vector store for nearest chunks
                $matches = $vectorStore->query($embedding, $topK, $orgId);

            // matches expected: array of ['id' => chunk_id, 'score' => float, 'metadata' => [...] ]
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
                    $q->select('id', 'title', 'source_url', 's3_path', 'org_id', 'connector_id');
                }, 'document.connector'])
                ->get()
                ->keyBy('id');

            // Build an ordered snippets array based on matches
            $snippets = [];
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
                ];
            }

            // 4. Assemble prompt & call LLM
            $prompt = $rag->assemblePrompt($queryText, $snippets);
            $llmResponse = $rag->callLLM($prompt);

            // Parse the LLM response (it returns JSON with answer + sources)
            $parsedAnswer = json_decode($llmResponse['answer'] ?? '{}', true);
            $answerText = is_array($parsedAnswer) ? ($parsedAnswer['answer'] ?? $llmResponse['answer']) : $llmResponse['answer'];
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
                    'type' => $sourceType
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
}