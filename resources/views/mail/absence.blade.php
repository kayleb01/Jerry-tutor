<x-mail::message>
# Hello {{ $guardianName }},

{{ $childName }} was marked absent from **{{ $className }}** on {{ $when }}.

If you weren't expecting this, or {{ $childName }} was there after all, please let the academy office know.

<x-mail::button :url="$dashboardUrl">
Open your dashboard
</x-mail::button>

Thanks,<br>
Jerry Great Academy
</x-mail::message>
