<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an admin approves a tutor's application; they were told to wait for it.
 */
class TutorApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $tutor) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're approved");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.tutor-approved', with: [
            'tutorName' => $this->tutor->name,
            'loginUrl' => route('login'),
        ]);
    }
}
