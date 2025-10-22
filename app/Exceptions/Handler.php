<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Send email notification for critical errors
            $this->sendErrorNotification($e);
        });
    }

    /**
     * Send email notification for critical errors
     */
    protected function sendErrorNotification(Throwable $exception): void
    {
        // Only send emails in production or if explicitly enabled
        $shouldSendEmail = env('SEND_ERROR_EMAILS', false) && app()->environment('production');
        
        // Or always send in development if you want to test
        // $shouldSendEmail = env('SEND_ERROR_EMAILS', false);
        
        if (!$shouldSendEmail) {
            return;
        }

        // Skip certain exceptions that are not critical
        $skipExceptions = [
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Validation\ValidationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Symfony\Component\HttpKernel\Exception\HttpException::class,
        ];

        foreach ($skipExceptions as $skipException) {
            if ($exception instanceof $skipException) {
                return;
            }
        }

        // Get admin email
        $adminEmail = env('ADMIN_EMAIL', 'vicken408@gmail.com');
        
        if (empty($adminEmail)) {
            return;
        }

        try {
            // Prepare error details
            $errorData = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'ip' => request()->ip(),
                'user_id' => auth()->id() ?? 'Guest',
                'timestamp' => now()->toDateTimeString(),
                'environment' => app()->environment(),
            ];

            // Send email
            Mail::send('emails.error-notification', $errorData, function ($message) use ($adminEmail, $exception) {
                $message->to($adminEmail)
                    ->subject('🚨 KHub Error Alert: ' . class_basename($exception));
            });

            Log::info('Error notification email sent', [
                'exception' => class_basename($exception),
                'admin_email' => $adminEmail,
            ]);

        } catch (\Exception $e) {
            // Log failure to send email, but don't throw exception
            Log::error('Failed to send error notification email', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

