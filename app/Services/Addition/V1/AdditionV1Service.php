<?php

namespace App\Services\Addition\V1;

use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Support\Collection;

class AdditionV1Service
{
    public function contentGenerate(array $input, string $orgId, int $userId): array
    {
        $docs = $this->resolveDocuments($orgId, $userId, $input['document_ids'] ?? []);
        if ($docs->isEmpty()) {
            return [
                'outputs' => [],
                'sources' => [],
            ];
        }

        $keywords = $this->extractKeywords($this->collectChunkText($docs->pluck('id')->all(), 6));
        $format = $input['format'] ?? 'campaign_pack';

        $outputs = [];
        if ($format === 'social' || $format === 'campaign_pack') {
            $outputs[] = [
                'type' => 'social_post',
                'title' => 'Authority Post',
                'content' => 'Key insight: ' . implode(', ', array_slice($keywords, 0, 5)) . '. Use this to improve your campaign outcomes this week.',
                'cta' => 'Reply \"PLAN\" to get the implementation steps.',
            ];
        }

        if ($format === 'email' || $format === 'campaign_pack') {
            $outputs[] = [
                'type' => 'email',
                'title' => 'Email Sequence Draft',
                'content' => "Subject: Quick win from your document\n\nHere is the distilled action set: " . implode(', ', array_slice($keywords, 0, 7)) . ".\n\nUse this as your next campaign seed.",
                'cta' => 'Apply these steps in the next 24 hours.',
            ];
        }

        if ($format === 'blog' || $format === 'campaign_pack') {
            $outputs[] = [
                'type' => 'blog_outline',
                'title' => 'Blog Outline',
                'content' => '1) Problem\n2) Strategy\n3) Tactics\n4) Execution checklist\n5) Summary',
                'cta' => 'Expand each section into a 700-word draft.',
            ];
        }

        return [
            'outputs' => $outputs,
            'sources' => $this->mapSources($docs),
        ];
    }

    public function courseCoach(array $input, string $orgId, int $userId): array
    {
        $docs = $this->resolveDocuments($orgId, $userId, $input['document_ids'] ?? []);
        $question = (string) ($input['question'] ?? '');
        $lines = $this->findRelevantLines($docs->pluck('id')->all(), $question, 6);

        $answer = $lines->isNotEmpty()
            ? "Here are the most relevant implementation points:\n- " . $lines->implode("\n- ")
            : 'No strong match was found in the selected course materials. Try a more specific module/chapter question.';

        $response = [
            'answer' => $answer,
            'sources' => $this->mapSources($docs),
        ];

        if (!empty($input['include_checklist'])) {
            $response['checklist'] = [
                'Identify one lesson to execute this week.',
                'Create a 3-step implementation plan.',
                'Track one metric for 7 days.',
            ];
        }

        return $response;
    }

    public function pdfChat(array $input, string $orgId, int $userId): array
    {
        $documentId = (string) $input['document_id'];
        $doc = $this->resolveDocuments($orgId, $userId, [$documentId])->first();

        if (!$doc) {
            return [
                'answer' => 'Document not found or access denied.',
                'sources' => [],
            ];
        }

        $question = (string) ($input['query'] ?? '');
        $topK = (int) ($input['top_k'] ?? 8);
        $snippets = $this->findRelevantLines([$doc->id], $question, max(1, min(20, $topK)));

        $answer = $snippets->isNotEmpty()
            ? "Answer based on selected PDF:\n- " . $snippets->implode("\n- ")
            : 'I could not find a direct match in this PDF. Try asking with terms from the section title.';

        return [
            'answer' => $answer,
            'sources' => [[
                'document_id' => $doc->id,
                'title' => $doc->title,
                'excerpt' => $snippets->first() ?? 'No matching excerpt.',
            ]],
        ];
    }

    public function summaryGenerate(array $input, string $orgId, int $userId): array
    {
        $docs = $this->resolveDocuments($orgId, $userId, $input['document_ids'] ?? []);
        $depth = (string) ($input['depth'] ?? 'one_page');
        $text = $this->collectChunkText($docs->pluck('id')->all(), 12);

        $maxChars = match ($depth) {
            'short' => 500,
            'detailed' => 2200,
            default => 1200,
        };

        $summary = mb_substr(trim($text), 0, $maxChars);
        if ($summary === '') {
            $summary = 'No content available for summarization from selected documents.';
        }

        $keywords = $this->extractKeywords($text);

        return [
            'summary' => $summary,
            'key_points' => array_map(fn ($k) => 'Key theme: ' . $k, array_slice($keywords, 0, 5)),
            'actions' => !empty($input['include_actions'])
                ? [
                    'Convert key points into a checklist.',
                    'Assign owners and due dates.',
                    'Review progress weekly.',
                ]
                : [],
            'sources' => $this->mapSources($docs),
        ];
    }

    public function strategyPlan(array $input): array
    {
        $goal = (string) ($input['goal'] ?? '');
        $days = (int) ($input['timeframe_days'] ?? 30);
        $weeks = max(1, (int) ceil($days / 7));

        $phases = [];
        for ($i = 1; $i <= min($weeks, 8); $i++) {
            $phases[] = [
                'name' => 'Week ' . $i,
                'tasks' => [
                    'Define objective and KPI for week ' . $i,
                    'Execute one focused action tied to: ' . $goal,
                    'Review blockers and adjust next steps.',
                ],
            ];
        }

        return [
            'plan' => ['phases' => $phases],
            'risks' => [
                'Scope creep from too many simultaneous priorities.',
                'Execution lag without weekly review.',
            ],
        ];
    }

    private function resolveDocuments(string $orgId, int $userId, array $documentIds): Collection
    {
        $query = Document::where('org_id', $orgId)
            ->where(function ($q) use ($userId) {
                $q->where('source_scope', 'organization')
                    ->orWhere(function ($qq) use ($userId) {
                        $qq->where('source_scope', 'personal')->where('user_id', $userId);
                    });
            });

        if (!empty($documentIds)) {
            $query->whereIn('id', $documentIds);
        }

        return $query->orderByDesc('created_at')->limit(20)->get(['id', 'title', 'doc_type', 'source_url', 's3_path']);
    }

    private function collectChunkText(array $documentIds, int $maxChunks): string
    {
        if (empty($documentIds)) {
            return '';
        }

        $chunks = Chunk::whereIn('document_id', $documentIds)
            ->orderBy('chunk_index')
            ->limit(max(1, $maxChunks))
            ->pluck('text');

        return $chunks->implode("\n");
    }

    private function findRelevantLines(array $documentIds, string $query, int $limit): Collection
    {
        if (empty($documentIds)) {
            return collect();
        }

        $terms = array_values(array_filter(preg_split('/\s+/', mb_strtolower($query)), function ($t) {
            return strlen($t) > 2;
        }));

        $chunks = Chunk::whereIn('document_id', $documentIds)
            ->orderBy('chunk_index')
            ->limit(200)
            ->pluck('text');

        $lines = collect();
        foreach ($chunks as $text) {
            $split = preg_split('/\r\n|\r|\n/', (string) $text);
            foreach ($split as $line) {
                $lineNorm = mb_strtolower(trim($line));
                if ($lineNorm === '') {
                    continue;
                }
                if (empty($terms)) {
                    $lines->push(trim($line));
                    continue;
                }
                foreach ($terms as $term) {
                    if (str_contains($lineNorm, $term)) {
                        $lines->push(trim($line));
                        break;
                    }
                }
            }
        }

        return $lines->unique()->take($limit)->values();
    }

    private function mapSources(Collection $docs): array
    {
        return $docs->map(function (Document $doc) {
            return [
                'document_id' => $doc->id,
                'title' => $doc->title,
                'url' => $doc->s3_path ?: $doc->source_url,
                'doc_type' => $doc->doc_type,
            ];
        })->values()->all();
    }

    private function extractKeywords(string $text): array
    {
        $tokens = preg_split('/[^a-zA-Z0-9]+/', mb_strtolower($text));
        $tokens = array_filter($tokens, function ($token) {
            return strlen($token) >= 4;
        });

        $freq = [];
        foreach ($tokens as $token) {
            $freq[$token] = ($freq[$token] ?? 0) + 1;
        }

        arsort($freq);
        return array_slice(array_keys($freq), 0, 12);
    }
}
