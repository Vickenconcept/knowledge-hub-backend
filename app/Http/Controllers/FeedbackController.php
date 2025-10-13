<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FeedbackController extends Controller
{
    /**
     * Store feedback for a message
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'message_id' => 'required|string|exists:messages,id',
                'rating' => 'required|in:up,down',
                'comment' => 'nullable|string|max:1000',
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Get the message to verify it belongs to user's organization
            $message = Message::with('conversation')->findOrFail($validated['message_id']);
            
            // Verify the message belongs to the user's organization
            if ($message->conversation->org_id !== $user->org_id) {
                return response()->json(['error' => 'Message not found'], 404);
            }

            // Create or update feedback (one feedback per user per message)
            $feedback = Feedback::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'message_id' => $validated['message_id'],
                ],
                [
                    'conversation_id' => $message->conversation_id,
                    'rating' => $validated['rating'],
                    'comment' => $validated['comment'] ?? null,
                ]
            );

            Log::info('Feedback submitted', [
                'user_id' => $user->id,
                'message_id' => $validated['message_id'],
                'rating' => $validated['rating'],
                'has_comment' => !empty($validated['comment']),
            ]);

            return response()->json([
                'success' => true,
                'feedback' => [
                    'id' => $feedback->id,
                    'rating' => $feedback->rating,
                    'comment' => $feedback->comment,
                    'created_at' => $feedback->created_at,
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Feedback submission failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Failed to submit feedback'
            ], 500);
        }
    }

    /**
     * Get feedback for a specific message
     */
    public function show(Request $request, string $messageId): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Get the message to verify it belongs to user's organization
            $message = Message::with('conversation')->findOrFail($messageId);
            
            if ($message->conversation->org_id !== $user->org_id) {
                return response()->json(['error' => 'Message not found'], 404);
            }

            // Get user's feedback for this message
            $feedback = Feedback::where('message_id', $messageId)
                ->where('user_id', $user->id)
                ->first();

            return response()->json([
                'success' => true,
                'feedback' => $feedback ? [
                    'id' => $feedback->id,
                    'rating' => $feedback->rating,
                    'comment' => $feedback->comment,
                    'created_at' => $feedback->created_at,
                ] : null
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get feedback', [
                'error' => $e->getMessage(),
                'message_id' => $messageId,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => 'Failed to get feedback'
            ], 500);
        }
    }

    /**
     * Get feedback analytics for admin
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Get feedback for user's organization
            $feedback = Feedback::with(['message.conversation', 'user'])
                ->whereHas('message.conversation', function ($query) use ($user) {
                    $query->where('org_id', $user->org_id);
                });

            // Overall stats
            $totalFeedback = $feedback->count();
            $positiveFeedback = $feedback->clone()->positive()->count();
            $negativeFeedback = $feedback->clone()->negative()->count();
            $feedbackWithComments = $feedback->clone()->withComments()->count();

            // Recent feedback (last 30 days)
            $recentFeedback = $feedback->clone()
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            // Feedback by rating
            $ratingBreakdown = $feedback->clone()
                ->selectRaw('rating, COUNT(*) as count')
                ->groupBy('rating')
                ->pluck('count', 'rating')
                ->toArray();

            // Most common negative feedback comments
            $commonNegativeComments = $feedback->clone()
                ->negative()
                ->withComments()
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

            return response()->json([
                'success' => true,
                'analytics' => [
                    'total_feedback' => $totalFeedback,
                    'positive_feedback' => $positiveFeedback,
                    'negative_feedback' => $negativeFeedback,
                    'feedback_with_comments' => $feedbackWithComments,
                    'recent_feedback' => $recentFeedback,
                    'rating_breakdown' => $ratingBreakdown,
                    'common_negative_comments' => $commonNegativeComments,
                    'satisfaction_rate' => $totalFeedback > 0 ? round(($positiveFeedback / $totalFeedback) * 100, 1) : 0,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get feedback analytics', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => 'Failed to get analytics'
            ], 500);
        }
    }
}