<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RAGService
{
    protected string $openAiKey;
    protected string $chatModel;

    public function __construct()
    {
        $this->openAiKey = config('services.openai.key', env('OPENAI_API_KEY'));
        $this->chatModel = env('OPENAI_CHAT_MODEL', 'gpt-4o-mini');
    }

    public function assemblePrompt(string $query, array $snippets): string
    {
        $maxSnip = 6;
        $buf = "You are an assistant that must answer using ONLY the provided context snippets. Prefer a direct, helpful answer when snippets contain relevant information. If the answer truly isn't present in the snippets, reply: \"I don't know — please check the source documents.\" Do not hallucinate.\n\n";
        $buf .= "Context snippets (id, excerpt, char range):\n";
        $count = 0;
        foreach ($snippets as $s) {
            if ($count >= $maxSnip) break;
            $count++;
            $title = $s['document_id'] ?? 'unknown_doc';
            $excerpt = mb_substr($s['text'] ?? '', 0, 800);
            $buf .= "[{$count}] Document: {$title} | excerpt: \"{$excerpt}\" | chars: " . ($s['char_start'] ?? 0) . "-" . ($s['char_end'] ?? 0) . "\n\n";
        }
        $buf .= "User question: \"{$query}\"\n\n";
        $buf .= "INSTRUCTIONS:\n";
        $buf .= "- Provide a concise answer (3-6 sentences) using the snippets.\n";
        $buf .= "- If at least one snippet contains relevant information, do NOT answer \"I don't know\".\n";
        $buf .= "- Return STRICT JSON with keys: answer (string), sources (array of {id:number, document_id:string, char_start:number, char_end:number}).\n";
        $buf .= "- sources should list the snippet ids you used, each with its document_id and char range.\n";
        return $buf;
    }

    public function callLLM(string $prompt): array
    {
        if (empty($this->openAiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY not configured for RAGService.');
        }

        $payload = [
            'model' => $this->chatModel,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant that must cite source excerpts provided.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 512,
            'temperature' => 0.0,
            'response_format' => ['type' => 'json_object'],
        ];

        $resp = Http::withToken($this->openAiKey)->post('https://api.openai.com/v1/chat/completions', $payload);
        if (!$resp->successful()) {
            Log::error('RAGService LLM call failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException('LLM call failed.');
        }

        $json = $resp->json();
        $rawText = $json['choices'][0]['message']['content'] ?? null;

        return [
            'answer' => $rawText,
            'raw' => $json,
        ];
    }
}


