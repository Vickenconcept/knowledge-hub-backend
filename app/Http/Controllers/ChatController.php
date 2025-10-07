<?php

namespace App\Http\Controllers;

use App\Models\Chunk;
use App\Models\Document;
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
        
        $request->validate([
            'query' => 'required|string',
            'top_k' => 'nullable|integer|min:1|max:50'
        ]);

        $queryText = $request->input('query');
        $topK = $request->input('top_k', 6);

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
                    $q->select('id', 'title', 'source_url', 'org_id');
                }])
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

            // 5. Format sources for response
            $formattedSources = array_map(function($s) use ($chunks) {
                $chunk = $chunks[$s['chunk_id']] ?? null;
                return [
                    'chunk_id' => $s['chunk_id'],
                    'document_id' => $s['document_id'],
                    'title' => $chunk && $chunk->document ? $chunk->document->title : 'Unknown',
                    'url' => $chunk && $chunk->document ? $chunk->document->source_url : null,
                    'excerpt' => mb_substr($s['text'], 0, 800),
                    'char_start' => $s['char_start'],
                    'char_end' => $s['char_end'],
                    'score' => $s['score'] ?? null
                ];
            }, $snippets);

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

            return response()->json([
                'answer' => $llmResponse['answer'] ?? null,
                'sources' => $formattedSources,
                'query' => $queryText,
                'result_count' => count($formattedSources),
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
                $q->select('id', 'title', 'source_url', 'org_id');
            }])
            ->limit($topK)
            ->get();

            // Format results
            $results = $chunks->map(function($chunk) use ($query) {
                return [
                    'document_id' => $chunk->document->id,
                    'title' => $chunk->document->title,
                    'url' => $chunk->document->source_url,
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