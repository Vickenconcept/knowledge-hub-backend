# Password Reset System Setup Guide

## Overview
This app now has a complete password reset functionality that allows users to reset their passwords via email.

## Features
- ✅ Forgot Password page with email input
- ✅ Password reset email with secure token
- ✅ Reset Password page with password confirmation
- ✅ Token expiration (60 minutes)
- ✅ Beautiful, user-friendly UI
- ✅ Secure token hashing
- ✅ Full validation and error handling

## Setup Instructions

### 1. Run Migration
First, make sure the `password_resets` table is created:

```bash
cd Backend
php artisan migrate
```

### 2. Configure Email Settings
Make sure your `.env` file has the correct mail configuration:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="${APP_NAME}"

# Frontend URL for reset link
FRONTEND_URL=http://localhost:5173
```

**For Gmail:**
- Use App Password instead of your regular password
- Generate App Password: Google Account → Security → 2-Step Verification → App passwords

### 3. Test the Flow

#### Manual Testing Steps:

1. **Request Password Reset**
   - Go to login page: `http://localhost:5173/login`
   - Click "Forgot password?"
   - Enter your email address
   - Click "Send Reset Link"
   - You should see a success message

2. **Check Email**
   - Check your inbox for the password reset email
   - Email should have:
     - Professional design
     - Orange branding
     - "Reset Password" button
     - Backup link in case button doesn't work
     - 60-minute expiration notice

3. **Reset Password**
   - Click the "Reset Password" button in the email
   - You'll be redirected to: `http://localhost:5173/reset-password?token=...&email=...`
   - Enter your new password (min. 8 characters)
   - Confirm your new password
   - Click "Reset Password"
   - You should see a success message

4. **Login with New Password**
   - After 3 seconds, you'll be redirected to the login page
   - Or click "Go to Login Now" immediately
   - Login with your new password
   - Success! 🎉

## Database Schema

### `password_resets` Table
```sql
- email (string, indexed)
- token (string, hashed)
- created_at (timestamp)
```

## API Endpoints

### 1. Request Password Reset
```http
POST /api/auth/password/reset-link
Content-Type: application/json

{
  "email": "user@example.com"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Password reset link has been sent to your email address."
}
```

### 2. Verify Reset Token
```http
POST /api/auth/password/verify-token
Content-Type: application/json

{
  "email": "user@example.com",
  "token": "random-64-char-token"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Token is valid."
}
```

### 3. Reset Password
```http
POST /api/auth/password/reset
Content-Type: application/json

{
  "email": "user@example.com",
  "token": "random-64-char-token",
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Your password has been reset successfully. You can now login with your new password."
}
```

## Frontend Routes

- `/forgot-password` - Request password reset
- `/reset-password?token=XXX&email=YYY` - Reset password form
- `/login` - Login page (has "Forgot password?" link)

## Security Features

1. **Token Hashing**: Tokens are hashed in the database
2. **Token Expiration**: Tokens expire after 60 minutes
3. **Single Use**: Old tokens are deleted when a new one is requested
4. **Password Validation**: Minimum 8 characters, must match confirmation
5. **Rate Limiting**: Laravel's built-in rate limiting on API routes

## Troubleshooting

### Email Not Sending
1. Check `.env` mail configuration
2. For Gmail, make sure you're using an App Password, not your regular password
3. Check Laravel logs: `Backend/storage/logs/laravel.log`
4. Test email configuration:
   ```bash
   php artisan tinker
   Mail::raw('Test email', function($msg) { $msg->to('your-email@example.com')->subject('Test'); });
   ```

### Token Invalid or Expired
1. Tokens expire after 60 minutes
2. Old tokens are deleted when a new one is requested
3. Make sure the URL parameters (`token` and `email`) are correct

### Frontend Not Loading
1. Make sure both frontend and backend are running
2. Check browser console for errors
3. Verify `FRONTEND_URL` in `.env` matches your frontend URL

## Files Created/Modified

### Backend
- `database/migrations/XXXX_create_password_resets_table.php` - Database migration
- `app/Mail/PasswordResetMail.php` - Email notification class
- `resources/views/emails/password-reset.blade.php` - Email template
- `app/Http/Controllers/PasswordResetController.php` - API controller
- `routes/api.php` - Added password reset routes

### Frontend
- `src/components/ForgotPasswordPage.tsx` - Forgot password form
- `src/components/ResetPasswordPage.tsx` - Reset password form
- `src/lib/apiClient.ts` - Added password reset API methods
- `src/App.tsx` - Added password reset routes

## Customization

### Email Design
Edit `Backend/resources/views/emails/password-reset.blade.php` to customize:
- Colors and branding
- Email copy
- Layout and styling

### Token Expiration
Edit `Backend/app/Http/Controllers/PasswordResetController.php`:
```php
// Change from 60 minutes to your desired duration
if ($createdAt->addMinutes(60)->isPast()) {
```

### Password Requirements
Edit `Frontend/src/components/ResetPasswordPage.tsx`:
```typescript
// Change minimum password length
if (password.length < 8) {
```

## Testing Checklist

- [ ] User can request password reset
- [ ] Email is sent with reset link
- [ ] Reset link expires after 60 minutes
- [ ] User can reset password with valid token
- [ ] User can login with new password
- [ ] Old tokens are deleted when new one is requested
- [ ] Error messages are clear and helpful
- [ ] UI is responsive on mobile
- [ ] Email looks good in different email clients
- [ ] Password validation works correctly

## Support

For issues or questions:
1. Check Laravel logs: `Backend/storage/logs/laravel.log`
2. Check browser console for frontend errors
3. Verify email configuration in `.env`
4. Test API endpoints directly with Postman or cURL

---

**✅ Your password reset system is now fully functional!**

