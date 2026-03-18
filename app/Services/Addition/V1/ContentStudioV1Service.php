<?php

namespace App\Services\Addition\V1;

use App\Models\Chunk;
use App\Models\ContentStudioGeneration;
use App\Models\ContentStudioGenerationItem;
use App\Models\Document;
use App\Services\Core\RAGService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentStudioV1Service
{
    public function __construct(private readonly RAGService $ragService)
    {
    }

    public function contentGenerate(array $input, string $orgId, int $userId): array
    {
        $docs = $this->resolveDocuments($orgId, $userId, $input['document_ids'] ?? []);
        if ($docs->isEmpty()) {
            return [
                'outputs' => [],
                'sources' => [],
            ];
        }

        $format = $input['format'] ?? 'campaign_pack';
        $maxOutputs = (int) ($input['max_outputs'] ?? 12);
        $maxOutputs = max(1, min(30, $maxOutputs));

        $sourceContext = $this->buildContentStudioSourceContext($docs);
        $prompt = $this->buildContentStudioPrompt($input, $sourceContext, $maxOutputs);

        $tokenBudget = max(900, min(2600, 320 * $maxOutputs));
        $llmResponse = $this->ragService->callLLM(
            $prompt,
            $tokenBudget,
            $orgId,
            $userId,
            null,
            (string) ($input['query'] ?? 'content generation')
        );

        $decoded = $this->decodeJsonObject((string) ($llmResponse['answer'] ?? ''));
        $outputs = $this->normalizeGeneratedOutputs($decoded['outputs'] ?? [], $format, $maxOutputs);

        if (empty($outputs)) {
            Log::warning('ContentStudioV1 contentGenerate: no usable outputs parsed from LLM response', [
                'org_id' => $orgId,
                'user_id' => $userId,
                'format' => $format,
                'raw_answer_preview' => mb_substr((string) ($llmResponse['answer'] ?? ''), 0, 500),
            ]);

            $keywords = $this->extractKeywords($this->collectChunkText($docs->pluck('id')->all(), 10));
            $outputs = $this->buildFallbackOutputs($format, $keywords);
        }

        $outputs = $this->attachGeneratedImages($outputs, $input);
        $generation = $this->storeGenerationRun($orgId, $userId, $input, $docs, $outputs);

        return [
            'outputs' => $outputs,
            'sources' => $this->mapSources($docs),
            'generation' => [
                'id' => $generation->id,
                'title' => $generation->title,
                'outputs_count' => $generation->outputs_count,
                'images_count' => $generation->images_count,
                'created_at' => $generation->created_at,
            ],
        ];
    }

    public function listContentRuns(string $orgId, int $userId, int $perPage = 20, string $paginationMode = 'offset'): array
    {
        $perPage = max(1, min(100, $perPage));
        $paginationMode = strtolower($paginationMode);

        $base = ContentStudioGeneration::where('org_id', $orgId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($paginationMode === 'cursor') {
            $page = $base->cursorPaginate($perPage)->withQueryString();
        } elseif ($paginationMode === 'simple') {
            $page = $base->simplePaginate($perPage)->withQueryString();
        } else {
            $page = $base->paginate($perPage)->withQueryString();
        }

        return [
            'data' => collect($page->items())->map(function (ContentStudioGeneration $run) {
                return [
                    'id' => $run->id,
                    'title' => $run->title,
                    'query' => $run->query,
                    'format' => $run->format,
                    'tone' => $run->tone,
                    'channel' => $run->channel,
                    'outputs_count' => (int) $run->outputs_count,
                    'images_count' => (int) $run->images_count,
                    'status' => $run->status,
                    'created_at' => $run->created_at,
                ];
            })->values()->all(),
            'pagination_mode' => $paginationMode,
            'pagination' => $this->formatPagination($page, $paginationMode),
        ];
    }

    public function getContentRun(string $runId, string $orgId, int $userId): ?array
    {
        $run = ContentStudioGeneration::where('id', $runId)
            ->where('org_id', $orgId)
            ->where('user_id', $userId)
            ->first();

        if (!$run) {
            return null;
        }

        $items = ContentStudioGenerationItem::where('generation_id', $run->id)
            ->orderBy('sort_order')
            ->get();

        return [
            'run' => [
                'id' => $run->id,
                'title' => $run->title,
                'query' => $run->query,
                'format' => $run->format,
                'tone' => $run->tone,
                'channel' => $run->channel,
                'max_outputs' => (int) $run->max_outputs,
                'outputs_count' => (int) $run->outputs_count,
                'images_count' => (int) $run->images_count,
                'source_document_ids' => $run->source_document_ids ?? [],
                'source_tags' => $run->source_tags ?? [],
                'created_at' => $run->created_at,
            ],
            'items' => $items->map(function (ContentStudioGenerationItem $item) {
                return [
                    'id' => $item->id,
                    'type' => $item->item_type,
                    'title' => $item->title,
                    'content' => $item->content,
                    'cta' => $item->cta,
                    'image_url' => $item->image_url,
                    'image_prompt' => $item->image_prompt,
                    'source_document_ids' => $item->source_document_ids ?? [],
                ];
            })->values()->all(),
        ];
    }

    public function getOverview(string $orgId, int $userId): array
    {
        // One aggregate query instead of count+sum+sum+first.
        $agg = ContentStudioGeneration::where('org_id', $orgId)
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as runs, COALESCE(SUM(outputs_count),0) as outputs, COALESCE(SUM(images_count),0) as images, MAX(created_at) as last_generated_at')
            ->first();

        $recentRuns = ContentStudioGeneration::where('org_id', $orgId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function (ContentStudioGeneration $run) {
                return [
                    'id' => $run->id,
                    'title' => $run->title,
                    'format' => $run->format,
                    'outputs_count' => (int) $run->outputs_count,
                    'images_count' => (int) $run->images_count,
                    'created_at' => $run->created_at,
                ];
            })->values()->all();

        $topTypes = ContentStudioGenerationItem::where('org_id', $orgId)
            ->where('user_id', $userId)
            ->selectRaw('item_type, COUNT(*) as count')
            ->groupBy('item_type')
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'type' => $row->item_type,
                'count' => (int) $row->count,
            ])->values()->all();

        return [
            'totals' => [
                'runs' => (int) ($agg->runs ?? 0),
                'outputs' => (int) ($agg->outputs ?? 0),
                'images' => (int) ($agg->images ?? 0),
                'last_generated_at' => $agg->last_generated_at ?? null,
            ],
            'recent_runs' => $recentRuns,
            'top_output_types' => $topTypes,
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
        // Avoid OR to keep indexes usable at scale: UNION org + personal, then hydrate.
        $documentIds = array_values(array_filter($documentIds));

        $orgDocs = Document::query()
            ->select('id', 'created_at')
            ->where('org_id', $orgId)
            ->where('source_scope', 'organization');

        $personalDocs = Document::query()
            ->select('id', 'created_at')
            ->where('org_id', $orgId)
            ->where('source_scope', 'personal')
            ->where('user_id', $userId);

        if (!empty($documentIds)) {
            $orgDocs->whereIn('id', $documentIds);
            $personalDocs->whereIn('id', $documentIds);
        }

        $union = $orgDocs->unionAll($personalDocs);
        $rows = DB::query()
            ->fromSub($union, 'd')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $orderedIds = $rows->pluck('id')->values();
        if ($orderedIds->isEmpty()) {
            return collect();
        }

        $docsById = Document::whereIn('id', $orderedIds)
            ->get(['id', 'title', 'doc_type', 'source_url', 's3_path', 'created_at'])
            ->keyBy('id');

        return $orderedIds->map(fn ($id) => $docsById->get($id))->filter()->values();
    }

    private function formatPagination(object $page, string $mode): array
    {
        $out = [
            'per_page' => method_exists($page, 'perPage') ? $page->perPage() : null,
        ];

        if ($mode === 'cursor' && method_exists($page, 'nextCursor')) {
            $out['next_cursor'] = $page->nextCursor()?->encode();
            $out['prev_cursor'] = $page->previousCursor()?->encode();
            return $out;
        }

        if ($mode === 'simple') {
            $out['current_page'] = method_exists($page, 'currentPage') ? $page->currentPage() : null;
            $out['next_page_url'] = method_exists($page, 'nextPageUrl') ? $page->nextPageUrl() : null;
            $out['prev_page_url'] = method_exists($page, 'previousPageUrl') ? $page->previousPageUrl() : null;
            return $out;
        }

        if (method_exists($page, 'currentPage')) {
            $out['current_page'] = $page->currentPage();
        }

        if (method_exists($page, 'lastPage')) {
            $out['last_page'] = $page->lastPage();
        }

        if (method_exists($page, 'total')) {
            $out['total'] = $page->total();
        }

        return $out;
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

    private function buildContentStudioSourceContext(Collection $docs): array
    {
        $context = [];

        foreach ($docs->take(8) as $doc) {
            $chunks = Chunk::where('document_id', $doc->id)
                ->orderBy('chunk_index')
                ->limit(6)
                ->pluck('text');

            $excerpt = mb_substr($chunks->implode("\n"), 0, 2600);

            $context[] = [
                'document_id' => (string) $doc->id,
                'title' => (string) $doc->title,
                'doc_type' => (string) ($doc->doc_type ?? 'general'),
                'excerpt' => $excerpt,
            ];
        }

        return $context;
    }

    private function buildContentStudioPrompt(array $input, array $sourceContext, int $maxOutputs): string
    {
        $query = (string) ($input['query'] ?? 'Create campaign assets');
        $format = (string) ($input['format'] ?? 'campaign_pack');
        $tone = (string) ($input['tone'] ?? 'direct');
        $channel = (string) ($input['channel'] ?? 'mixed');

        $buffer = "You are a senior direct-response marketing strategist and copywriter.\n";
        $buffer .= "Create high-converting marketing assets grounded only in the provided source documents.\n\n";

        $buffer .= "REQUEST:\n";
        $buffer .= "- Query: {$query}\n";
        $buffer .= "- Format: {$format}\n";
        $buffer .= "- Tone: {$tone}\n";
        $buffer .= "- Channel: {$channel}\n";
        $buffer .= "- Max outputs: {$maxOutputs}\n\n";

        $buffer .= "SOURCE DOCUMENTS:\n";
        foreach ($sourceContext as $index => $source) {
            $n = $index + 1;
            $buffer .= "[{$n}] ID={$source['document_id']} | TITLE={$source['title']} | TYPE={$source['doc_type']}\n";
            $buffer .= "EXCERPT:\n{$source['excerpt']}\n\n";
        }

        $buffer .= "RULES:\n";
        $buffer .= "1) Do not invent product claims, names, numbers, guarantees, or testimonials not present in source docs.\n";
        $buffer .= "2) Make copy concrete and usable immediately (specific hooks, angles, CTAs).\n";
        $buffer .= "3) Keep style aligned with requested tone/channel.\n";
        $buffer .= "4) Return strict JSON only, with this schema:\n";
        $buffer .= "{\n";
        $buffer .= "  \"outputs\": [\n";
        $buffer .= "    {\n";
        $buffer .= "      \"type\": \"social_post|email|blog_outline|ad_hook|caption\",\n";
        $buffer .= "      \"title\": \"string\",\n";
        $buffer .= "      \"content\": \"string\",\n";
        $buffer .= "      \"cta\": \"string\",\n";
        $buffer .= "      \"source_document_ids\": [\"uuid\"]\n";
        $buffer .= "    }\n";
        $buffer .= "  ]\n";
        $buffer .= "}\n";
        $buffer .= "5) Output count must be between 1 and {$maxOutputs}.\n";
        $buffer .= "6) campaign_pack should include a mix of social, email, and at least one long-form asset.\n";

        return $buffer;
    }

    private function decodeJsonObject(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($trimmed, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function normalizeGeneratedOutputs(mixed $outputs, string $format, int $maxOutputs): array
    {
        if (!is_array($outputs)) {
            return [];
        }

        $allowedByFormat = match ($format) {
            'social' => ['social_post', 'caption', 'ad_hook'],
            'email' => ['email'],
            'blog' => ['blog_outline'],
            default => ['social_post', 'caption', 'ad_hook', 'email', 'blog_outline'],
        };

        $normalized = [];
        foreach ($outputs as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? 'social_post');
            if (!in_array($type, $allowedByFormat, true)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? 'Generated Asset'));
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $normalizedItem = [
                'type' => $type,
                'title' => $title === '' ? 'Generated Asset' : $title,
                'content' => $content,
            ];

            $cta = trim((string) ($item['cta'] ?? ''));
            if ($cta !== '') {
                $normalizedItem['cta'] = $cta;
            }

            $sourceIds = $item['source_document_ids'] ?? [];
            if (is_array($sourceIds) && !empty($sourceIds)) {
                $normalizedItem['source_document_ids'] = array_values(array_filter(array_map('strval', $sourceIds)));
            }

            $normalized[] = $normalizedItem;
            if (count($normalized) >= $maxOutputs) {
                break;
            }
        }

        return $normalized;
    }

    private function buildFallbackOutputs(string $format, array $keywords): array
    {
        $outputs = [];

        if ($format === 'social' || $format === 'campaign_pack') {
            $outputs[] = [
                'type' => 'social_post',
                'title' => 'Authority Post',
                'content' => 'Key insight: ' . implode(', ', array_slice($keywords, 0, 6)) . '. Use this to improve outcomes this week.',
                'cta' => 'Reply "PLAN" to get implementation steps.',
            ];
        }

        if ($format === 'email' || $format === 'campaign_pack') {
            $outputs[] = [
                'type' => 'email',
                'title' => 'Email Draft',
                'content' => "Subject: Quick win from your source material\n\nAction themes: " . implode(', ', array_slice($keywords, 0, 8)) . ".",
                'cta' => 'Apply these steps in the next 24 hours.',
            ];
        }

        if ($format === 'blog' || $format === 'campaign_pack') {
            $outputs[] = [
                'type' => 'blog_outline',
                'title' => 'Blog Outline',
                'content' => '1) Problem\n2) Strategy\n3) Tactics\n4) Execution checklist\n5) Summary',
                'cta' => 'Expand each section into a complete article.',
            ];
        }

        return $outputs;
    }

    private function attachGeneratedImages(array $outputs, array $input): array
    {
        $enabled = filter_var((string) env('CONTENT_STUDIO_ENABLE_IMAGES', 'true'), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled || empty($outputs)) {
            return $outputs;
        }

        $openAiKey = config('services.openai.key', env('OPENAI_API_KEY'));
        if (empty($openAiKey)) {
            return $outputs;
        }

        $limit = max(0, min(8, (int) env('CONTENT_STUDIO_IMAGE_LIMIT', 3)));
        if ($limit === 0) {
            return $outputs;
        }

        $imageModel = (string) env('OPENAI_IMAGE_MODEL', 'gpt-image-1');
        $generated = 0;

        foreach ($outputs as $idx => $output) {
            if ($generated >= $limit) {
                break;
            }

            if (!is_array($output)) {
                continue;
            }

            $prompt = $this->buildImagePrompt($output, $input);
            try {
                $resp = Http::withToken($openAiKey)
                    ->timeout(120)
                    ->retry(2, 1500)
                    ->post('https://api.openai.com/v1/images/generations', [
                        'model' => $imageModel,
                        'prompt' => $prompt,
                        'size' => '1024x1024',
                    ]);

                if (!$resp->successful()) {
                    Log::warning('ContentStudio image generation failed', [
                        'status' => $resp->status(),
                        'body' => mb_substr($resp->body(), 0, 500),
                    ]);
                    continue;
                }

                $json = $resp->json();
                $b64 = $json['data'][0]['b64_json'] ?? null;
                $url = $json['data'][0]['url'] ?? null;

                if (is_string($b64) && $b64 !== '') {
                    $outputs[$idx]['image_url'] = 'data:image/png;base64,' . $b64;
                    $outputs[$idx]['image_prompt'] = $prompt;
                    $generated++;
                    continue;
                }

                if (is_string($url) && $url !== '') {
                    $outputs[$idx]['image_url'] = $url;
                    $outputs[$idx]['image_prompt'] = $prompt;
                    $generated++;
                }
            } catch (\Throwable $e) {
                Log::warning('ContentStudio image generation exception', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $outputs;
    }

    private function buildImagePrompt(array $output, array $input): string
    {
        $tone = (string) ($input['tone'] ?? 'direct');
        $channel = (string) ($input['channel'] ?? 'mixed');
        $title = mb_substr((string) ($output['title'] ?? ''), 0, 120);
        $content = mb_substr((string) ($output['content'] ?? ''), 0, 500);

        return "Create a high-quality marketing visual for this content asset. Tone: {$tone}. Channel: {$channel}. "
            . "Title: {$title}. Core message: {$content}. No text overlays, modern composition, brand-safe, photoreal or premium illustration style.";
    }

    private function storeGenerationRun(string $orgId, int $userId, array $input, Collection $docs, array $outputs): ContentStudioGeneration
    {
        $query = (string) ($input['query'] ?? 'Generated campaign assets');
        $generation = ContentStudioGeneration::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'title' => mb_substr($query, 0, 120),
            'query' => $query,
            'format' => (string) ($input['format'] ?? 'campaign_pack'),
            'tone' => (string) ($input['tone'] ?? 'direct'),
            'channel' => (string) ($input['channel'] ?? 'mixed'),
            'max_outputs' => (int) ($input['max_outputs'] ?? 12),
            'source_document_ids' => $docs->pluck('id')->values()->all(),
            'source_tags' => $this->extractKeywords($this->collectChunkText($docs->pluck('id')->all(), 30)),
            'outputs_count' => count($outputs),
            'images_count' => count(array_filter($outputs, fn ($o) => !empty($o['image_url']))),
            'status' => 'completed',
            'metadata' => [
                'search_scope' => $input['search_scope'] ?? 'both',
            ],
        ]);

        foreach ($outputs as $index => $output) {
            ContentStudioGenerationItem::create([
                'generation_id' => $generation->id,
                'org_id' => $orgId,
                'user_id' => $userId,
                'sort_order' => $index,
                'item_type' => (string) ($output['type'] ?? 'content'),
                'title' => (string) ($output['title'] ?? 'Generated Asset'),
                'content' => (string) ($output['content'] ?? ''),
                'cta' => isset($output['cta']) ? (string) $output['cta'] : null,
                'image_url' => isset($output['image_url']) ? (string) $output['image_url'] : null,
                'image_prompt' => isset($output['image_prompt']) ? (string) $output['image_prompt'] : null,
                'source_document_ids' => $output['source_document_ids'] ?? [],
                'metadata' => null,
            ]);
        }

        return $generation;
    }
}
