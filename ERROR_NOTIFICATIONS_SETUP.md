# 📧 Error Email Notifications Setup

## Overview

Automatically receive email notifications whenever critical errors occur in your KHub application!

---

## ✅ Features

- 🚨 **Automatic error detection** - Catches all unhandled exceptions
- 📧 **Email notifications** - Sends detailed error reports to your email
- 🎯 **Smart filtering** - Skips non-critical errors (404s, validation errors, auth failures)
- 📊 **Rich error details** - Includes stack trace, URL, user info, timestamp
- 🎨 **Beautiful HTML emails** - Professional, responsive email template
- 🛡️ **Production-ready** - Only sends in production (configurable)

---

## 🔧 Setup Instructions

### Step 1: Configure Email Settings

Add these to your `.env` file:

```env
# Mail Configuration
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@khub.app
MAIL_FROM_NAME="KHub Error Alerts"

# Admin Email (where error notifications are sent)
ADMIN_EMAIL=vicken408@gmail.com

# Enable error email notifications
SEND_ERROR_EMAILS=true
```

### Step 2: Get Gmail App Password (Recommended)

If using Gmail:

1. Go to [Google Account Security](https://myaccount.google.com/security)
2. Enable **2-Step Verification** (if not already enabled)
3. Go to **App Passwords**: https://myaccount.google.com/apppasswords
4. Select **Mail** and **Other (Custom name)**
5. Enter "KHub Error Notifications"
6. Click **Generate**
7. Copy the 16-character password
8. Use it as `MAIL_PASSWORD` in `.env`

### Step 3: Test Email Configuration

Create a test route in `routes/web.php`:

```php
Route::get('/test-error-email', function () {
    throw new \Exception('This is a test error notification!');
});
```

Then visit: `http://localhost:8000/test-error-email`

You should receive an email with error details! 📧

### Step 4: Enable in Production

In your `.env` file:

```env
APP_ENV=production
SEND_ERROR_EMAILS=true
```

---

## 📋 What Gets Emailed

The error notification includes:

✅ **Error Message** - Clear description of what went wrong  
✅ **Environment** - Production/Staging/Local badge  
✅ **Timestamp** - Exact time the error occurred  
✅ **File & Line** - Where the error happened in your code  
✅ **URL** - The endpoint that caused the error  
✅ **HTTP Method** - GET/POST/PUT/DELETE  
✅ **IP Address** - User's IP address  
✅ **User ID** - Authenticated user (or "Guest")  
✅ **Stack Trace** - Full error trace for debugging  

---

## 🎯 Which Errors Get Emailed?

### ✅ **WILL Send Email:**
- Database connection failures
- API errors (OpenAI, Google, Slack, etc.)
- PHP fatal errors
- Unhandled exceptions
- Job failures (IngestConnectorJob, etc.)
- File upload errors
- Configuration errors

### ❌ **WON'T Send Email:**
- 404 Not Found errors
- Validation errors (form validation)
- Authentication failures (wrong password)
- Rate limiting errors
- Expected HTTP exceptions

---

## ⚙️ Configuration Options

### Environment-Based Sending

**Production Only (Recommended):**
```php
// In Handler.php line 61
$shouldSendEmail = env('SEND_ERROR_EMAILS', false) && app()->environment('production');
```

**All Environments (For Testing):**
```php
// In Handler.php line 61
$shouldSendEmail = env('SEND_ERROR_EMAILS', false);
```

### Custom Exception Filtering

Add exceptions you want to skip in `Handler.php`:

```php
$skipExceptions = [
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Validation\ValidationException::class,
    \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    \Symfony\Component\HttpKernel\Exception\HttpException::class,
    \App\Exceptions\CustomException::class, // Add your custom exceptions
];
```

### Multiple Admin Emails

To send to multiple admins:

```env
ADMIN_EMAIL=admin1@example.com,admin2@example.com
```

Then update `Handler.php`:

```php
$adminEmails = explode(',', env('ADMIN_EMAIL', ''));
foreach ($adminEmails as $email) {
    $message->to(trim($email));
}
```

---

## 🧪 Testing

### Test 1: Manual Error

```php
Route::get('/test-error', function () {
    throw new \Exception('Test error for email notification!');
});
```

### Test 2: Database Error

```php
Route::get('/test-db-error', function () {
    \App\Models\User::where('id', 999999999)->firstOrFail();
});
```

### Test 3: API Error

```php
Route::get('/test-api-error', function () {
    throw new \RuntimeException('OpenAI API key is invalid!');
});
```

Visit these routes and check your email inbox! 📬

---

## 📊 Alternative Email Providers

### Using SendGrid:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=your-sendgrid-api-key
MAIL_ENCRYPTION=tls
```

### Using Mailgun:

```env
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=your-domain.mailgun.org
MAILGUN_SECRET=your-mailgun-secret
```

### Using Amazon SES:

```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
```

---

## 🚨 Troubleshooting

### Not Receiving Emails?

1. **Check spam folder** - Error emails might be filtered
2. **Verify `.env` configuration**:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```
3. **Test mail configuration**:
   ```bash
   php artisan tinker
   >>> Mail::raw('Test email', function($msg) { $msg->to('your@email.com')->subject('Test'); });
   ```
4. **Check Laravel logs**:
   ```bash
   tail -f storage/logs/laravel.log | grep "Error notification"
   ```

### Emails Sent Too Frequently?

Add rate limiting to `Handler.php`:

```php
use Illuminate\Support\Facades\Cache;

protected function sendErrorNotification(Throwable $exception): void
{
    // ... existing checks ...
    
    // Rate limit: Only send 1 email per error type per 5 minutes
    $cacheKey = 'error_email_' . md5($exception->getMessage());
    
    if (Cache::has($cacheKey)) {
        return; // Already sent recently
    }
    
    Cache::put($cacheKey, true, now()->addMinutes(5));
    
    // ... send email ...
}
```

### Wrong Email Template?

Clear view cache:
```bash
php artisan view:clear
```

---

## 🎨 Customizing the Email Template

Edit `Backend/resources/views/emails/error-notification.blade.php`:

**Change Colors:**
```css
.header {
    background: linear-gradient(135deg, #your-color 0%, #your-color2 100%);
}
```

**Add More Info:**
```blade
<div class="info-label">Server:</div>
<div class="info-value">{{ gethostname() }}</div>
```

**Add Call-to-Action:**
```blade
<a href="https://your-monitoring-dashboard.com" style="...">
    View Dashboard
</a>
```

---

## 📈 Advanced: Integrate with Monitoring Tools

### Sentry Integration

```bash
composer require sentry/sentry-laravel
php artisan sentry:publish --dsn=your-dsn
```

### Slack Notifications

Add Slack webhook in `Handler.php`:

```php
use Illuminate\Support\Facades\Http;

Http::post('https://hooks.slack.com/services/YOUR/WEBHOOK/URL', [
    'text' => "🚨 Error: {$exception->getMessage()}",
]);
```

### Discord Notifications

```php
Http::post('https://discord.com/api/webhooks/YOUR/WEBHOOK', [
    'content' => "🚨 **Error Alert**\n```{$exception->getMessage()}```",
]);
```

---

## ✅ Best Practices

1. ✅ **Always test** in staging before production
2. ✅ **Use app passwords** for Gmail (not your main password)
3. ✅ **Set up rate limiting** to avoid email spam
4. ✅ **Monitor your logs** regularly even with email alerts
5. ✅ **Create email filters** to organize error notifications
6. ✅ **Set up backup notification** channel (Slack, Discord)
7. ✅ **Review and fix errors** promptly when notified

---

## 📝 Email Filter Rules (Gmail)

Create a filter to organize error emails:

1. Gmail → Settings → Filters → Create new filter
2. **From:** `noreply@khub.app`
3. **Subject:** `KHub Error Alert`
4. **Apply label:** `KHub/Errors`
5. **Star it** (for visibility)
6. **Never send to spam**

---

## 🎯 Production Checklist

Before deploying to production:

- [ ] Configured `MAIL_*` settings in `.env`
- [ ] Added `ADMIN_EMAIL`
- [ ] Set `SEND_ERROR_EMAILS=true`
- [ ] Set `APP_ENV=production`
- [ ] Tested with manual error route
- [ ] Cleared all caches (`php artisan optimize`)
- [ ] Checked email spam folder
- [ ] Set up email filters in inbox
- [ ] Added rate limiting (optional)
- [ ] Integrated with monitoring tools (optional)

---

**🎉 You're all set!** You'll now receive instant email notifications whenever errors occur in your KHub application!

**Documentation created:** 2025-10-20  
**Last updated:** 2025-10-20

