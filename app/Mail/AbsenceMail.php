<?php

namespace App\Mail;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Sent to each parent with an email address when a saved register marks their child absent.
 */
class AbsenceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AttendanceRecord $record, public User $guardian) {}

    public function envelope(): Envelope
    {
        $record = $this->record->loadMissing(['user', 'lesson.classGroup', 'lesson.slot.subject']);

        return new Envelope(subject: Str::before($record->user->name, ' ')." was absent from {$record->lesson->title()}");
    }

    public function content(): Content
    {
        $record = $this->record->loadMissing(['user', 'lesson.classGroup', 'lesson.slot.subject']);

        return new Content(markdown: 'mail.absence', with: [
            'guardianName' => Str::before($this->guardian->name, ' '),
            'childName' => Str::before($record->user->name, ' '),
            'className' => $record->lesson->title(),
            'when' => $record->lesson->localStart()->format('l j F \a\t H:i'),
            'dashboardUrl' => route('dashboard'),
        ]);
    }
}
