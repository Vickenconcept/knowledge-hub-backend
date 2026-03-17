<?php

namespace App\Services\Core;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailService
{
    public static function sendWelcomeEmailForPasswordSignup(User $user): void
    {
        $subject = 'Welcome to Knowledge Hub';
        $text = "Hi {$user->name},\n\n"
              . "Welcome to Knowledge Hub! Your account has been created successfully.\n\n"
              . "You can now log in using this email address and the password you chose during signup.\n\n"
              . "Best,\n"
              . "The Knowledge Hub Team";

        $html = nl2br(e($text));

        self::sendViaResend($user->email, $subject, $text, $html);
    }

    public static function sendWelcomeEmailForGoogleSignup(User $user): void
    {
        $subject = 'Welcome to Knowledge Hub (Google sign-in)';
        $text = "Hi {$user->name},\n\n"
              . "Welcome to Knowledge Hub! Your account has been created using Google sign-in.\n\n"
              . "You can log in anytime by choosing the \"Continue with Google\" option and using this email address: {$user->email}.\n\n"
              . "Best,\n"
              . "The Knowledge Hub Team";

        $html = nl2br(e($text));

        self::sendViaResend($user->email, $subject, $text, $html);
    }

    public static function sendPasswordResetEmail(User $user, string $resetUrl): void
    {
        $subject = 'Reset Your Password - KHub';
        $text = "Hi {$user->name},\n\n"
              . "We received a request to reset the password for your KHub account.\n\n"
              . "Click the link below to choose a new password:\n{$resetUrl}\n\n"
              . "This link will expire in 60 minutes. If you didn't request a password reset, you can safely ignore this email.\n\n"
              . "Best,\n"
              . "The KHub Team";

        $html = nl2br(e($text));

        self::sendViaResend($user->email, $subject, $text, $html);
    }

    protected static function sendViaResend(string $to, string $subject, string $text, string $html): void
    {
        $apiKey = env('RESEND_API_KEY');
        // Support both RESEND_FROM_EMAIL and RESEND_EMAIL_FROM
        $from = env('RESEND_FROM_EMAIL') ?: env('RESEND_EMAIL_FROM');

        if (!$apiKey || !$from) {
            Log::warning('Resend email skipped: missing RESEND_API_KEY or RESEND_FROM_EMAIL');
            return;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->post('https://api.resend.com/emails', [
                    'from' => $from,
                    'to' => [$to],
                    'subject' => $subject,
                    'text' => $text,
                    'html' => $html,
                ]);

            if ($response->failed()) {
                Log::error('Resend email send failed', [
                    'to' => $to,
                    'subject' => $subject,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Resend email exception', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

