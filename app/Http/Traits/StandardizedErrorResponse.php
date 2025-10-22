<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait StandardizedErrorResponse
{
    /**
     * Create a standardized error response
     */
    protected function errorResponse(
        string $message,
        int $statusCode = 400,
        ?string $errorCode = null,
        array $details = [],
        ?\Exception $exception = null
    ): JsonResponse {
        $response = [
            'success' => false,
            'error' => $message,
            'status_code' => $statusCode,
            'timestamp' => now()->toISOString(),
        ];

        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }

        if (!empty($details)) {
            $response['details'] = $details;
        }

        // Log error for debugging
        if ($exception) {
            Log::error("API Error: {$message}", [
                'error_code' => $errorCode,
                'status_code' => $statusCode,
                'details' => $details,
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
        } else {
            Log::warning("API Error: {$message}", [
                'error_code' => $errorCode,
                'status_code' => $statusCode,
                'details' => $details
            ]);
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Create a validation error response
     */
    protected function validationErrorResponse(
        array $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return $this->errorResponse(
            message: $message,
            statusCode: 422,
            errorCode: 'VALIDATION_ERROR',
            details: ['validation_errors' => $errors]
        );
    }

    /**
     * Create a rate limit error response
     */
    protected function rateLimitErrorResponse(
        string $message = 'Rate limit exceeded',
        int $retryAfter = 60
    ): JsonResponse {
        return $this->errorResponse(
            message: $message,
            statusCode: 429,
            errorCode: 'RATE_LIMIT_EXCEEDED',
            details: ['retry_after' => $retryAfter]
        );
    }

    /**
     * Create a usage limit error response
     */
    protected function usageLimitErrorResponse(
        string $message,
        string $limitType,
        int $currentUsage,
        int $limit,
        string $tier
    ): JsonResponse {
        return $this->errorResponse(
            message: $message,
            statusCode: 429,
            errorCode: 'USAGE_LIMIT_EXCEEDED',
            details: [
                'limit_type' => $limitType,
                'current_usage' => $currentUsage,
                'limit' => $limit,
                'tier' => $tier,
                'upgrade_required' => true
            ]
        );
    }

    /**
     * Create a not found error response
     */
    protected function notFoundErrorResponse(
        string $resource = 'Resource'
    ): JsonResponse {
        return $this->errorResponse(
            message: "{$resource} not found",
            statusCode: 404,
            errorCode: 'NOT_FOUND'
        );
    }

    /**
     * Create an unauthorized error response
     */
    protected function unauthorizedErrorResponse(
        string $message = 'Unauthorized access'
    ): JsonResponse {
        return $this->errorResponse(
            message: $message,
            statusCode: 401,
            errorCode: 'UNAUTHORIZED'
        );
    }

    /**
     * Create a forbidden error response
     */
    protected function forbiddenErrorResponse(
        string $message = 'Access forbidden'
    ): JsonResponse {
        return $this->errorResponse(
            message: $message,
            statusCode: 403,
            errorCode: 'FORBIDDEN'
        );
    }

    /**
     * Create a server error response
     */
    protected function serverErrorResponse(
        string $message = 'Internal server error',
        ?\Exception $exception = null
    ): JsonResponse {
        return $this->errorResponse(
            message: $message,
            statusCode: 500,
            errorCode: 'INTERNAL_ERROR',
            exception: $exception
        );
    }
}
