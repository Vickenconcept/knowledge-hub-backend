<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class InviteMail extends Mailable
{
    use Queueable, SerializesModels;


    public User $invitedUser;
    public string $tempPassword;
    public string $organizationName;
    /**
     * Create a new message instance.
     */
    public function __construct(User $invitedUser, string $tempPassword, string $organizationName = 'KHub')
    {
        $this->invitedUser = $invitedUser;
        $this->tempPassword = $tempPassword;
        $this->organizationName = $organizationName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'ve been invited to ' . $this->organizationName,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.invite_email',
            with: [
                'userName' => $this->invitedUser->name,
                'userEmail' => $this->invitedUser->email,
                'tempPassword' => $this->tempPassword,
                'organizationName' => $this->organizationName,
                'loginUrl' => config('app.frontend_url') ? rtrim(config('app.frontend_url'), '/') . '/login' : url('/')
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}