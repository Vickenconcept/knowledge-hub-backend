<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>You're invited to {{ $organizationName }}</title>
  </head>
  <body style="font-family: Arial, sans-serif; background:#f6f8fa; padding:24px;">
    <div style="max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
      <div style="padding:20px 24px; border-bottom:1px solid #f1f5f9;">
        <h2 style="margin:0; font-size:18px; color:#111827;">Welcome to {{ $organizationName }}</h2>
      </div>
      <div style="padding:24px; color:#111827;">
        <p>Hi {{ $userName }},</p>
        <p>You’ve been invited to join <strong>{{ $organizationName }}</strong>.</p>
        <p>We’ve created a temporary password for your account:</p>
        <p style="background:#f9fafb; border:1px dashed #e5e7eb; padding:12px 14px; border-radius:6px; font-family: monospace;">
          Email: {{ $userEmail }}<br>
          Temporary Password: <strong>{{ $tempPassword }}</strong>
        </p>
        <p>Please log in and change your password as soon as possible.</p>
        <p style="margin-top:20px;">
          <a href="{{ $loginUrl }}" style="display:inline-block; background:#f97316; color:#ffffff; text-decoration:none; padding:10px 16px; border-radius:6px;">Log in</a>
        </p>
        <p style="color:#6b7280; font-size:12px; margin-top:24px;">If you did not expect this email, you can ignore it.</p>
      </div>
    </div>
  </body>
</html>


