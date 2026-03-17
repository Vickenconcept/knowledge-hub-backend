<?php

namespace App\Http\Controllers\Addition\V1;

use App\Http\Controllers\Addition\Controller;
use App\Services\Addition\V1\ContentStudioV1Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContentStudioV1Controller extends Controller
{
    public function __construct(private readonly ContentStudioV1Service $service)
    {
    }

    public function health(): JsonResponse
    {
        return $this->ok([
            'ok' => true,
            'module' => 'addition',
            'version' => 'v1',
            'timestamp' => now()->toIso8601String(),
        ], microtime(true));
    }

    public function contentGenerate(Request $request): JsonResponse
    {
        $start = microtime(true);
        $data = $request->validate([
            'query' => 'required|string|max:2000',
            'document_ids' => 'nullable|array',
            'document_ids.*' => 'string|uuid',
            'format' => 'nullable|string|in:blog,social,email,campaign_pack',
            'tone' => 'nullable|string|in:direct,friendly,authority,story',
            'channel' => 'nullable|string|in:blog,email,x,linkedin,facebook,mixed',
            'max_outputs' => 'nullable|integer|min:1|max:30',
            'search_scope' => 'nullable|string|in:organization,personal,both',
        ]);

        try {
            $result = $this->service->contentGenerate($data, (string) $request->user()->org_id, (int) $request->user()->id);
            return $this->ok($result, $start);
        } catch (\Throwable $e) {
            \Log::error('Addition v1 content generation failed', [
                'user_id' => $request->user()?->id,
                'org_id' => $request->user()?->org_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Content generation failed',
                'message' => 'AI generation is currently unavailable. Check OPENAI_API_KEY / OPENAI_CHAT_MODEL and try again.',
            ], 503);
        }
    }

    public function courseCoach(Request $request): JsonResponse
    {
        $start = microtime(true);
        $data = $request->validate([
            'question' => 'required|string|max:1500',
            'document_ids' => 'nullable|array',
            'document_ids.*' => 'string|uuid',
            'mode' => 'nullable|string|in:coach,explain,quiz,recap',
            'response_style' => 'nullable|string|in:concise,structured,deep',
            'include_checklist' => 'nullable|boolean',
        ]);

        $result = $this->service->courseCoach($data, (string) $request->user()->org_id, (int) $request->user()->id);
        return $this->ok($result, $start);
    }

    public function pdfChat(Request $request): JsonResponse
    {
        $start = microtime(true);
        $data = $request->validate([
            'document_id' => 'required|string|uuid',
            'query' => 'required|string|max:1500',
            'top_k' => 'nullable|integer|min:1|max:20',
            'response_style' => 'nullable|string|in:concise,structured,deep,bullet_brief',
        ]);

        $result = $this->service->pdfChat($data, (string) $request->user()->org_id, (int) $request->user()->id);
        return $this->ok($result, $start);
    }

    public function summaryGenerate(Request $request): JsonResponse
    {
        $start = microtime(true);
        $data = $request->validate([
            'document_ids' => 'required|array|min:1',
            'document_ids.*' => 'string|uuid',
            'depth' => 'nullable|string|in:short,one_page,detailed',
            'audience' => 'nullable|string|in:beginner,operator,executive',
            'include_key_points' => 'nullable|boolean',
            'include_actions' => 'nullable|boolean',
        ]);

        $result = $this->service->summaryGenerate($data, (string) $request->user()->org_id, (int) $request->user()->id);
        return $this->ok($result, $start);
    }

    public function strategyPlan(Request $request): JsonResponse
    {
        $start = microtime(true);
        $data = $request->validate([
            'goal' => 'required|string|max:1000',
            'document_ids' => 'nullable|array',
            'document_ids.*' => 'string|uuid',
            'timeframe_days' => 'nullable|integer|min:7|max:120',
            'plan_type' => 'nullable|string|in:execution,sop,campaign',
            'output_format' => 'nullable|string|in:checklist,milestones,table',
        ]);

        $result = $this->service->strategyPlan($data);
        return $this->ok($result, $start);
    }

    private function ok(array $data, float $start, ?string $message = null): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data,
            'meta' => [
                'request_id' => (string) Str::uuid(),
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ],
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return response()->json($response);
    }
}
