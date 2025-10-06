<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Chunk;
use App\Services\EmbeddingService;
use App\Services\VectorStoreService;
use App\Services\RAGService;
use App\Models\QueryLog;

class ChatController extends Controller
{
    public function ask(Request $request, EmbeddingService $embeddingService, VectorStoreService $vectorStore, RAGService $rag)
    {
        $user = $request->user();
        $orgId = $user?->org_id;
        $request->validate([
            'query' => 'required|string',
            'top_k' => 'nullable|integer|min:1|max:50',
        ]);

        $queryText = $request->input('query');
        $topK = (int) $request->input('top_k', 6);

        try {
            $embedding = $embeddingService->embed($queryText);
            try {
                $matches = $vectorStore->query($embedding, $topK, $orgId);
            } catch (\Throwable $e) {
                $matches = [];
            }
            $chunkIds = array_map(fn($m) => $m['id'], $matches);

            if (empty($chunkIds)) {
                return response()->json([
                    'answer' => "I don't know — no relevant documents found.",
                    'sources' => [],
                    'raw' => null,
                ]);
            }

            $chunks = Chunk::whereIn('id', $chunkIds)->get()->keyBy('id');

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
                    'score' => $m['score'] ?? null,
                ];
            }

            $prompt = $rag->assemblePrompt($queryText, $snippets);
            $llmResponse = $rag->callLLM($prompt);
            $finalAnswer = $llmResponse['answer'] ?? null;
            $parsed = null;
            if (is_string($finalAnswer)) {
                $try = json_decode($finalAnswer, true);
                if (is_array($try) && isset($try['answer'])) {
                    $parsed = $try;
                    $finalAnswer = $try['answer'];
                }
            }

            // Build sources array either from parsed JSON ids or from snippets fallback
            $sourcesOut = [];
            if (is_array($parsed) && isset($parsed['sources']) && is_array($parsed['sources'])) {
                foreach ($parsed['sources'] as $src) {
                    $sid = $src['id'] ?? null;
                    if ($sid !== null && isset($snippets[$sid-1])) {
                        $s = $snippets[$sid-1];
                        $sourcesOut[] = [
                            'chunk_id' => $s['chunk_id'],
                            'document_id' => $s['document_id'],
                            'excerpt' => mb_substr($s['text'], 0, 800),
                            'char_start' => $s['char_start'],
                            'char_end' => $s['char_end'],
                            'score' => $s['score'] ?? null,
                        ];
                    } elseif (isset($src['document_id'], $src['char_start'], $src['char_end'])) {
                        $sourcesOut[] = [
                            'chunk_id' => $src['chunk_id'] ?? null,
                            'document_id' => $src['document_id'],
                            'excerpt' => null,
                            'char_start' => $src['char_start'],
                            'char_end' => $src['char_end'],
                            'score' => null,
                        ];
                    }
                }
            }
            if (empty($sourcesOut)) {
                foreach ($snippets as $s) {
                    $sourcesOut[] = [
                        'chunk_id' => $s['chunk_id'],
                        'document_id' => $s['document_id'],
                        'excerpt' => mb_substr($s['text'], 0, 800),
                        'char_start' => $s['char_start'],
                        'char_end' => $s['char_end'],
                        'score' => $s['score'] ?? null,
                    ];
                }
            }

            $response = [
                'answer' => $finalAnswer,
                'sources' => $sourcesOut,
                'raw' => $llmResponse['raw'] ?? null,
            ];

            // Log query analytics
            try {
                QueryLog::create([
                    'org_id' => $orgId,
                    'user_id' => $user?->id,
                    'query_text' => $queryText,
                    'top_k' => $topK,
                    'result_chunk_ids' => array_map(fn($s) => $s['chunk_id'], $snippets),
                    'model_used' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
                    'cost_estimate' => null,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // swallow analytics errors
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            Log::error('ChatController@ask error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    public function search(Request $request, EmbeddingService $embeddingService, VectorStoreService $vectorStore)
    {
        $user = $request->user();
        $orgId = $user?->org_id;
        $request->validate([
            'query' => 'required|string',
            'top_k' => 'nullable|integer|min:1|max:50',
        ]);
        $embedding = $embeddingService->embed($request->input('query'));
        try {
            $matches = $vectorStore->query($embedding, (int) $request->input('top_k', 6), $orgId);
        } catch (\Throwable $e) {
            $matches = [];
        }
        return response()->json(['matches' => $matches]);
    }

    public function feedback(Request $request)
    {
        $request->validate([
            'query_id' => 'nullable|string',
            'useful' => 'required|boolean',
            'notes' => 'nullable|string',
        ]);
        return response()->json(['status' => 'ok']);
    }
}


