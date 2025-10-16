<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FeedbackDashboardController extends Controller
{
    /**
     * Get comprehensive feedback dashboard data
     */
    public function index(Request $request): JsonResponse
    {
        // Handle paginated feedback request
        if ($request->has('page') || $request->has('per_page')) {
            return $this->getPaginatedFeedback($request);
        }
        
        return $this->getDashboardOverview($request);
    }

    /**
     * Get dashboard overview data
     */
    private function getDashboardOverview(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                Log::warning('Feedback dashboard accessed without authentication');
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Get feedback for user's organization
            $feedbackQuery = Feedback::with(['message.conversation', 'user'])
                ->whereHas('message.conversation', function ($query) use ($user) {
                    $query->where('org_id', $user->org_id);
                });

            // Overall stats - use separate queries to avoid query conflicts
            $totalFeedback = $feedbackQuery->count();
            $positiveFeedback = Feedback::with(['message.conversation', 'user'])
                ->whereHas('message.conversation', function ($query) use ($user) {
                    $query->where('org_id', $user->org_id);
                })
                ->where('rating', 'up')
                ->count();
            $negativeFeedback = Feedback::with(['message.conversation', 'user'])
                ->whereHas('message.conversation', function ($query) use ($user) {
                    $query->where('org_id', $user->org_id);
                })
                ->where('rating', 'down')
                ->count();
            $feedbackWithComments = Feedback::with(['message.conversation', 'user'])
                ->whereHas('message.conversation', function ($query) use ($user) {
                    $query->where('org_id', $user->org_id);
                })
                ->whereNotNull('comment')
                ->count();

            // Satisfaction rate
            $satisfactionRate = $totalFeedback > 0 ? round(($positiveFeedback / $totalFeedback) * 100, 1) : 0;

            // Recent feedback (last 30 days)
            $recentFeedback = Feedback::with(['message.conversation', 'user'])
                ->whereHas('message.conversation', function ($query) use ($user) {
                    $query->where('org_id', $user->org_id);
                })
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            // Feedback trends (last 7 days)
            $feedbackTrends = $feedbackQuery->clone()
                ->selectRaw('DATE(created_at) as date, rating, COUNT(*) as count')
                ->where('created_at', '>=', now()->subDays(7))
                ->groupBy('date', 'rating')
                ->orderBy('date')
                ->get()
                ->groupBy('date')
                ->map(function ($dayData) {
                    $positive = $dayData->where('rating', 'up')->sum('count');
                    $negative = $dayData->where('rating', 'down')->sum('count');
                    return [
                        'date' => $dayData->first()->date,
                        'positive' => $positive,
                        'negative' => $negative,
                        'total' => $positive + $negative,
                    ];
                })
                ->values();

            // Most common negative feedback comments
            $commonNegativeComments = $feedbackQuery->clone()
                ->where('rating', 'down')
                ->whereNotNull('comment')
                ->selectRaw('comment, COUNT(*) as count')
                ->groupBy('comment')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'comment' => $item->comment,
                        'count' => $item->count,
                    ];
                });

            // Feedback by source - Show which sources are involved in messages that received feedback
            // Note: This shows source involvement, not direct feedback attribution
            $feedbackBySource = collect();
            
            // Get all feedback with their message sources
            $feedbackWithSources = Feedback::with(['message'])
                ->whereHas('message.conversation', function ($query) use ($user) {
                    $query->where('org_id', $user->org_id);
                })
                ->get();

            // Count sources involved in feedback messages
            $sourceCounts = [];
            foreach ($feedbackWithSources as $feedback) {
                $sources = $feedback->message->sources ?? [];
                $sourceTypes = [];
                
                foreach ($sources as $source) {
                    if (isset($source['type'])) {
                        $sourceTypes[] = strtolower($source['type']);
                    }
                }
                
                // Count this feedback for each unique source type involved
                foreach (array_unique($sourceTypes) as $sourceType) {
                    if (!isset($sourceCounts[$sourceType])) {
                        $sourceCounts[$sourceType] = [
                            'source' => $sourceType,
                            'total_feedback' => 0,
                            'positive' => 0,
                            'negative' => 0
                        ];
                    }
                    
                    $sourceCounts[$sourceType]['total_feedback']++;
                    if ($feedback->rating === 'up') {
                        $sourceCounts[$sourceType]['positive']++;
                    } else {
                        $sourceCounts[$sourceType]['negative']++;
                    }
                }
            }
            
            // Convert to collection and sort
            $feedbackBySource = collect($sourceCounts)->values()->sortByDesc('total_feedback');

            // Recent feedback with details (limited for overview)
            $recentFeedbackDetails = Feedback::with(['message.conversation', 'user'])
                ->whereHas('message.conversation', function ($query) use ($user) {
                    $query->where('org_id', $user->org_id);
                })
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($feedback) {
                    return [
                        'id' => $feedback->id,
                        'rating' => $feedback->rating,
                        'comment' => $feedback->comment,
                        'created_at' => $feedback->created_at,
                        'user_name' => $feedback->user->name ?? 'Unknown',
                        'message_preview' => $feedback->message && $feedback->message->content 
                            ? substr($feedback->message->content, 0, 100) . '...' 
                            : 'No message content',
                        'conversation_title' => $feedback->message && $feedback->message->conversation 
                            ? $feedback->message->conversation->title 
                            : 'Untitled',
                    ];
                });

            // Feedback by time of day
            $driver = DB::connection()->getDriverName();
            
            if ($driver === 'pgsql') {
                // PostgreSQL: Use EXTRACT for hour
                $feedbackByHour = $feedbackQuery->clone()
                    ->selectRaw('EXTRACT(HOUR FROM created_at) as hour, rating, COUNT(*) as count')
                    ->groupBy(DB::raw('EXTRACT(HOUR FROM created_at)'), 'rating')
                    ->orderBy(DB::raw('EXTRACT(HOUR FROM created_at)'))
                    ->get()
                    ->groupBy('hour')
                    ->map(function ($hourData) {
                        $positive = $hourData->where('rating', 'up')->sum('count');
                        $negative = $hourData->where('rating', 'down')->sum('count');
                        return [
                            'hour' => (int)$hourData->first()->hour,
                            'positive' => $positive,
                            'negative' => $negative,
                            'total' => $positive + $negative,
                        ];
                    })
                    ->values();
            } else {
                // MySQL: Use HOUR function
                $feedbackByHour = $feedbackQuery->clone()
                    ->selectRaw('HOUR(created_at) as hour, rating, COUNT(*) as count')
                    ->groupBy('hour', 'rating')
                    ->orderBy('hour')
                    ->get()
                    ->groupBy('hour')
                    ->map(function ($hourData) {
                        $positive = $hourData->where('rating', 'up')->sum('count');
                        $negative = $hourData->where('rating', 'down')->sum('count');
                        return [
                            'hour' => $hourData->first()->hour,
                            'positive' => $positive,
                            'negative' => $negative,
                            'total' => $positive + $negative,
                        ];
                    })
                    ->values();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'dashboard' => [
                        'overview' => [
                            'total_feedback' => $totalFeedback,
                            'positive_feedback' => $positiveFeedback,
                            'negative_feedback' => $negativeFeedback,
                            'feedback_with_comments' => $feedbackWithComments,
                            'recent_feedback' => $recentFeedback,
                            'satisfaction_rate' => $satisfactionRate,
                        ],
                        'trends' => $feedbackTrends,
                        'common_negative_comments' => $commonNegativeComments,
                        'feedback_by_source' => $feedbackBySource,
                        'recent_feedback_details' => $recentFeedbackDetails,
                        'feedback_by_hour' => $feedbackByHour,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get feedback dashboard', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => 'Failed to get dashboard data'
            ], 500);
        }
    }

    /**
     * Export feedback data as CSV
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $feedback = Feedback::with(['message.conversation', 'user'])
                ->whereHas('message.conversation', function ($query) use ($user) {
                    $query->where('org_id', $user->org_id);
                })
                ->orderBy('created_at', 'desc')
                ->get();

            $csvData = [];
            $csvData[] = [
                'Date',
                'Rating',
                'Comment',
                'User',
                'Message Preview',
                'Conversation',
            ];

            foreach ($feedback as $item) {
                $csvData[] = [
                    $item->created_at->format('Y-m-d H:i:s'),
                    $item->rating === 'up' ? '👍 Positive' : '👎 Negative',
                    $item->comment ?? '',
                    $item->user->name ?? 'Unknown',
                    substr($item->message->content, 0, 100) . '...',
                    $item->message->conversation->title ?? 'Untitled',
                ];
            }

            return response()->json([
                'success' => true,
                'csv_data' => $csvData,
                'filename' => 'feedback_export_' . now()->format('Y-m-d_H-i-s') . '.csv',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to export feedback', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => 'Failed to export feedback'
            ], 500);
        }
    }

    /**
     * Get feedback for a specific conversation
     */
    public function conversationFeedback(Request $request, string $conversationId): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Verify conversation belongs to user's organization
            $conversation = Conversation::where('id', $conversationId)
                ->where('org_id', $user->org_id)
                ->firstOrFail();

            $feedback = Feedback::with(['message', 'user'])
                ->whereHas('message', function ($query) use ($conversationId) {
                    $query->where('conversation_id', $conversationId);
                })
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'rating' => $item->rating,
                        'comment' => $item->comment,
                        'created_at' => $item->created_at,
                        'user_name' => $item->user->name ?? 'Unknown',
                        'message_preview' => substr($item->message->content, 0, 150) . '...',
                    ];
                });

            return response()->json([
                'success' => true,
                'conversation_feedback' => $feedback,
                'conversation_title' => $conversation->title,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get conversation feedback', [
                'error' => $e->getMessage(),
                'conversation_id' => $conversationId,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => 'Failed to get conversation feedback'
            ], 500);
        }
    }

    /**
     * Get paginated feedback with filtering and sorting
     */
    private function getPaginatedFeedback(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Get query parameters
            $page = $request->get('page', 1);
            $perPage = min($request->get('per_page', 20), 100); // Max 100 per page
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $rating = $request->get('rating'); // 'up', 'down', or null for all
            $hasComment = $request->get('has_comment'); // true, false, or null for all
            $search = $request->get('search'); // Search in comments

            // Validate sort parameters
            $allowedSorts = ['created_at', 'rating', 'user_name'];
            if (!in_array($sortBy, $allowedSorts)) {
                $sortBy = 'created_at';
            }
            if (!in_array($sortOrder, ['asc', 'desc'])) {
                $sortOrder = 'desc';
            }

            // Build query
            $query = Feedback::with(['message.conversation', 'user'])
                ->whereHas('message.conversation', function ($q) use ($user) {
                    $q->where('org_id', $user->org_id);
                });

            // Apply filters
            if ($rating && in_array($rating, ['up', 'down'])) {
                $query->where('rating', $rating);
            }

            if ($hasComment !== null) {
                if ($hasComment === 'true') {
                    $query->whereNotNull('comment');
                } else {
                    $query->whereNull('comment');
                }
            }

            if ($search) {
                $query->where('comment', 'like', '%' . $search . '%');
            }

            // Apply sorting
            if ($sortBy === 'user_name') {
                $query->join('users', 'feedback.user_id', '=', 'users.id')
                      ->orderBy('users.name', $sortOrder)
                      ->select('feedback.*');
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Get paginated results
            $paginated = $query->paginate($perPage, ['*'], 'page', $page);

            // Transform data
            $feedback = $paginated->getCollection()->map(function ($item) {
                return [
                    'id' => $item->id,
                    'rating' => $item->rating,
                    'comment' => $item->comment,
                    'created_at' => $item->created_at,
                    'user_name' => $item->user->name ?? 'Unknown',
                    'user_email' => $item->user->email ?? '',
                    'message_preview' => $item->message && $item->message->content 
                        ? substr($item->message->content, 0, 150) . '...' 
                        : 'No message content',
                    'conversation_title' => $item->message && $item->message->conversation 
                        ? $item->message->conversation->title 
                        : 'Untitled',
                    'conversation_id' => $item->message && $item->message->conversation 
                        ? $item->message->conversation->id 
                        : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'feedback' => $feedback,
                    'pagination' => [
                        'current_page' => $paginated->currentPage(),
                        'last_page' => $paginated->lastPage(),
                        'per_page' => $paginated->perPage(),
                        'total' => $paginated->total(),
                        'from' => $paginated->firstItem(),
                        'to' => $paginated->lastItem(),
                    ],
                    'filters' => [
                        'rating' => $rating,
                        'has_comment' => $hasComment,
                        'search' => $search,
                        'sort_by' => $sortBy,
                        'sort_order' => $sortOrder,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get paginated feedback', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => 'Failed to get feedback data'
            ], 500);
        }
    }
}