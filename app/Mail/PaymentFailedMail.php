<?php

namespace App\Mail;

use App\Enums\PaymentStatus;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an admin chases a failed subscription payment.
 */
class PaymentFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Subscription $subscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your Jerry Great Academy payment didn't go through");
    }

    public function content(): Content
    {
        $subscription = $this->subscription->loadMissing(['payer', 'plan', 'students']);
        $lastFailure = $subscription->payments()->where('status', PaymentStatus::Failed)->latest('created_at')->latest('id')->first();
        $graceEnds = $subscription->current_period_ends_at?->addDays((int) config('payments.grace_days'))->setTimezone(config('academy.timezone'));

        return new Content(markdown: 'mail.payment-failed', with: [
            'payerName' => $subscription->payer->name,
            'planName' => $subscription->plan->name,
            'childNames' => $subscription->students->pluck('name')->join(', ', ' and '),
            'reason' => $lastFailure?->failure_reason,
            'graceEnds' => $graceEnds?->isFuture() ? $graceEnds->format('l j F') : null,
            'billingUrl' => route('billing.index'),
        ]);
    }
}
