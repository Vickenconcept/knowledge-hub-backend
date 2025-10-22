<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestErrorEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:error-email';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test error email notification system';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧪 Testing error email notification system...');
        
        $adminEmail = env('ADMIN_EMAIL');
        
        if (empty($adminEmail)) {
            $this->error('❌ ADMIN_EMAIL not configured in .env file!');
            $this->info('Add this to your .env:');
            $this->info('ADMIN_EMAIL=your-email@example.com');
            return 1;
        }
        
        $this->info("📧 Sending test email to: {$adminEmail}");
        
        try {
            // Prepare test error data
            $errorData = [
                'message' => 'This is a TEST error notification. If you received this email, your error notification system is working correctly! ✅',
                'file' => 'Backend/app/Console/Commands/TestErrorEmail.php',
                'line' => 45,
                'trace' => "Test stack trace:\n#0 TestErrorEmail.php(45): handle()\n#1 Kernel.php(139): call()\n#2 Artisan.php(37): handle()",
                'url' => url('/test-error'),
                'method' => 'GET',
                'ip' => '127.0.0.1',
                'user_id' => 'Test User',
                'timestamp' => now()->toDateTimeString(),
                'environment' => app()->environment(),
            ];

            // Send test email
            Mail::send('emails.error-notification', $errorData, function ($message) use ($adminEmail) {
                $message->to($adminEmail)
                    ->subject('🧪 TEST: KHub Error Alert');
            });

            $this->info('');
            $this->info('✅ Test email sent successfully!');
            $this->info('📬 Check your inbox: ' . $adminEmail);
            $this->info('');
            $this->warn('⚠️  Don\'t forget to check your spam folder!');
            $this->info('');
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('');
            $this->error('❌ Failed to send test email!');
            $this->error('Error: ' . $e->getMessage());
            $this->info('');
            $this->info('💡 Troubleshooting steps:');
            $this->info('1. Check your .env mail configuration (MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD)');
            $this->info('2. Run: php artisan config:clear');
            $this->info('3. For Gmail, use an App Password: https://myaccount.google.com/apppasswords');
            $this->info('4. Check storage/logs/laravel.log for detailed error');
            $this->info('');
            
            return 1;
        }
    }
}

