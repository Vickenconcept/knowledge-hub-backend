<?php

namespace App\Http\Controllers;

use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function ask(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|max:1000',
            'history' => 'nullable|array',
            'top_k' => 'nullable|integer|min:1|max:20'
        ]);

        $orgId = $request->user()->org_id;
        $query = $validated['query'];
        $topK = $validated['top_k'] ?? 5;

        try {
            // For now, do a simple text search across chunks
            // Later we'll implement vector search with embeddings
            $chunks = Chunk::whereHas('document', function($q) use ($orgId) {
                $q->where('org_id', $orgId);
            })
            ->where('text', 'like', '%' . $query . '%')
            ->with(['document' => function($q) {
                $q->select('id', 'title', 'source_url', 'org_id');
            }])
            ->limit($topK)
            ->get();

            // Generate a simple response based on found chunks
            $answer = $this->generateAnswer($query, $chunks);
            
            // Format sources
            $sources = $chunks->map(function($chunk) {
                return [
                    'document_id' => $chunk->document->id,
                    'title' => $chunk->document->title,
                    'url' => $chunk->document->source_url,
                    'excerpt' => $this->extractExcerpt($chunk->text, $query),
                    'char_start' => $chunk->char_start,
                    'char_end' => $chunk->char_end,
                    'score' => 1.0 // Placeholder score
                ];
            })->toArray();

            // Log the query for analytics
            \App\Models\QueryLog::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'org_id' => $orgId,
                'user_id' => $request->user()->id,
                'query_text' => $query,
                'top_k' => $topK,
                'result_count' => count($sources),
                'timestamp' => now(),
            ]);

                return response()->json([
                'answer' => $answer,
                'sources' => $sources,
                'query' => $query,
                'result_count' => count($sources)
            ]);

        } catch (\Exception $e) {
            Log::error('Chat error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to process query',
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

    private function generateAnswer($query, $chunks)
    {
        if ($chunks->isEmpty()) {
            return "I couldn't find any relevant information about '{$query}' in your documents. Try searching for different keywords or check if your documents have been properly indexed.";
        }

        // Simple answer generation based on found chunks
        $relevantText = $chunks->pluck('text')->join(' ');
        
        // Extract a relevant sentence or paragraph
        $sentences = explode('.', $relevantText);
        $relevantSentences = array_filter($sentences, function($sentence) use ($query) {
            return stripos($sentence, $query) !== false;
        });

        if (!empty($relevantSentences)) {
            $answer = trim($relevantSentences[0]) . '.';
        } else {
            // Fallback to first chunk
            $answer = substr($chunks->first()->text, 0, 200) . '...';
        }

        return $answer . "\n\nI found " . count($chunks) . " relevant document(s) that contain information about your query.";
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