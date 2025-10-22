# Quick Test: Password Reset System

## 🚀 Quick Start

### 1. Make Sure Your Email is Configured
Check your `Backend/.env` file has these settings:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-gmail-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="KHub"

FRONTEND_URL=http://localhost:5173
```

**📧 Gmail App Password Setup:**
1. Go to: https://myaccount.google.com/apppasswords
2. Sign in to your Google Account
3. Click "Create" (or "Generate")
4. Enter "KHub" as the app name
5. Copy the 16-character password
6. Paste it in `MAIL_PASSWORD` in your `.env`

### 2. Test Email Configuration (Optional)
```bash
cd Backend
php artisan test:password-reset your-email@gmail.com
```

This will send a test email to verify your email settings are correct.

### 3. Test the Full Flow

**Step 1: Start Your Servers**
- Backend: `cd Backend && php artisan serve`
- Frontend: `cd Frontend && npm run dev`

**Step 2: Request Password Reset**
1. Go to: http://localhost:5173/login
2. Click **"Forgot password?"**
3. Enter your email
4. Click **"Send Reset Link"**
5. ✅ You should see: "Check Your Email"

**Step 3: Check Your Email**
1. Open your email inbox
2. Look for email with subject: **"Reset Your Password - KHub"**
3. Email should have:
   - Orange gradient header
   - "Reset Password" button
   - 60-minute expiration notice
   - Backup link at the bottom

**Step 4: Reset Your Password**
1. Click **"Reset Password"** button in the email
2. You'll be redirected to the reset page
3. Enter your new password (min. 8 characters)
4. Confirm the password
5. Click **"Reset Password"**
6. ✅ You should see: "Password Reset Successful!"

**Step 5: Login with New Password**
1. Click **"Go to Login Now"** (or wait 3 seconds for auto-redirect)
2. Login with your email and new password
3. ✅ You should be logged into the dashboard!

## 🐛 Troubleshooting

### Email Not Sending?

**Check Laravel Logs:**
```bash
tail -f Backend/storage/logs/laravel.log
```

**Common Issues:**
- ❌ Using regular Gmail password instead of App Password
- ❌ Wrong SMTP settings
- ❌ 2-Step Verification not enabled on Gmail
- ❌ Firewall blocking SMTP port 587

**Solution:**
1. Make sure 2-Step Verification is enabled on your Google Account
2. Generate a new App Password
3. Update `MAIL_PASSWORD` in `.env`
4. Clear config cache: `php artisan config:clear`

### Token Invalid or Expired?
- Tokens expire after **60 minutes**
- Request a new reset link
- Make sure the URL has both `token` and `email` parameters

### Frontend Page Not Loading?
1. Make sure frontend server is running: `npm run dev`
2. Check browser console for errors (F12)
3. Verify `FRONTEND_URL` in `.env` matches your frontend URL

## ✅ Success Indicators

- [ ] "Forgot password?" link appears on login page
- [ ] Forgot password page loads correctly
- [ ] Email is sent within a few seconds
- [ ] Email has professional design with orange branding
- [ ] Reset link redirects to reset password page
- [ ] Token validation works (shows form if valid, error if invalid/expired)
- [ ] Password can be reset successfully
- [ ] User can login with new password
- [ ] Old password no longer works

## 📝 Test with Different Scenarios

1. **Valid Token**
   - Request reset → Check email → Click link → Reset password → ✅ Success

2. **Expired Token**
   - Request reset → Wait 61 minutes → Click link → ❌ "This reset link has expired"

3. **Invalid Email**
   - Request reset with non-existent email → ❌ "The email address was not found"

4. **Password Mismatch**
   - Enter different passwords in the two fields → ❌ "Passwords do not match"

5. **Short Password**
   - Enter password less than 8 characters → ❌ "Password must be at least 8 characters"

6. **Multiple Requests**
   - Request reset twice → Only the latest token should work (old one is deleted)

## 🎨 UI Preview

### Forgot Password Page
- Clean, modern design
- Orange accent color
- Email input field
- Loading state with spinner
- Success state with check icon

### Reset Password Page
- Password strength requirements
- Show/hide password toggle
- Visual password validation
- Success animation
- Auto-redirect to login

### Password Reset Email
- Professional HTML email
- Orange gradient header with lock icon
- Clear call-to-action button
- Expiration warning
- Backup link for accessibility
- Responsive design

## 🔒 Security Features

✅ **Token Hashing** - Tokens stored as hashes in database  
✅ **60-Minute Expiration** - Old links automatically expire  
✅ **Single-Use Tokens** - Old tokens deleted on new request  
✅ **Password Validation** - Min 8 characters, must match confirmation  
✅ **Email Verification** - Only existing users can request resets  

---

**🎉 That's it! Your password reset system is ready to use!**

For full documentation, see: `Backend/PASSWORD_RESET_SETUP.md`

