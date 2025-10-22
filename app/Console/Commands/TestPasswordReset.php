<?php

namespace App\Console\Commands;

use App\Mail\PasswordResetMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestPasswordReset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:password-reset {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test password reset email';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $testToken = 'test-token-' . bin2hex(random_bytes(16));
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
        $resetUrl = "{$frontendUrl}/reset-password?token={$testToken}&email=" . urlencode($email);

        $this->info('📧 Sending test password reset email...');
        $this->info('To: ' . $email);
        $this->info('Reset URL: ' . $resetUrl);

        try {
            Mail::to($email)->send(new PasswordResetMail($resetUrl, 'Test User'));
            $this->info('✅ Test email sent successfully!');
            $this->info('');
            $this->info('Check your inbox at: ' . $email);
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to send test email');
            $this->error('Error: ' . $e->getMessage());
            $this->info('');
            $this->info('Please check your .env mail configuration:');
            $this->info('MAIL_MAILER=' . env('MAIL_MAILER'));
            $this->info('MAIL_HOST=' . env('MAIL_HOST'));
            $this->info('MAIL_PORT=' . env('MAIL_PORT'));
            $this->info('MAIL_USERNAME=' . env('MAIL_USERNAME'));
            $this->info('MAIL_FROM_ADDRESS=' . env('MAIL_FROM_ADDRESS'));
            return 1;
        }
    }
}

