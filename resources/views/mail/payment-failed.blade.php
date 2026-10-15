<x-mail::message>
# Hello {{ $payerName }},

We couldn't take this month's payment for your **{{ $planName }}**{{ $reason ? ' ('.strtolower($reason).')' : '' }}.

@if ($graceEnds)
{{ $childNames }} can keep using the quizzes until **{{ $graceEnds }}**. Please update your card before then so nothing is interrupted.
@else
Please update your card to restore {{ $childNames }}'s quiz access.
@endif

<x-mail::button :url="$billingUrl">
Update your card
</x-mail::button>

If you've already sorted this out, you can ignore this email.

Thanks,<br>
Jerry Great Academy
</x-mail::message>
