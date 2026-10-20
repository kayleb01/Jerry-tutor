<x-mail::message>
# Hello {{ $tutorName }},

You're approved — you can now log in and see your classes.

<x-mail::button :url="$loginUrl">
Log in
</x-mail::button>

Thanks,<br>
Jerry Great Academy
</x-mail::message>
