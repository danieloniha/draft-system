<x-mail::message>
# You're invited

@if ($hostName)
{{ $hostName }} has invited you to join the session **{{ $draftName }}**.
@else
You have been invited to join the session **{{ $draftName }}**.
@endif

You'll need an account using this email address. If you don't have one yet, you can sign up after opening the link.

<x-mail::button :url="$url">
Join session
</x-mail::button>

If the button doesn't work, copy this link into your browser:<br>
{{ $url }}
</x-mail::message>
