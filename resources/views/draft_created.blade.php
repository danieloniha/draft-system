<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Created</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    <div class="form-container">
        <h2 class="form-title">Session Created Successfully</h2>
        <p>
            {{ $draft->isMoneyMode()
                ? 'Nobody sees the results until you, as the host, start the draft from the session details page.'
                : 'Nobody can pick until you, as the host, start the draft from the session details page.' }}
        </p>

        @if ($draft->isPublic())
            @php($joinLink = route('public.join.form', ['public_token' => $draft->public_token]))
            <h4>Share this link so participants can join</h4>
            <div class="link-box">
                <code>{{ $joinLink }}</code>
                <button class="copy-link btn-logout" type="button" data-link="{{ $joinLink }}">Copy link</button>
            </div>
        @else
            <p>Each invited participant has their own join link.</p>
            <div class="form-group">
                <a href="{{ route('invitations.sent', ['draft_id' => $draft->id]) }}" class="btn-outline">View invite links</a>
            </div>
        @endif

        <div class="form-group">
            <a href="{{ route('draft.details', ['draft_id' => $draft->id]) }}" class="btn">Open session details</a>
        </div>

        <div class="form-group">
            <a href="{{ route('dashboard') }}" class="btn-outline">Go to Homepage</a>
        </div>
    </div>
    <script>
        document.querySelectorAll('.copy-link').forEach((button) => {
            button.addEventListener('click', async () => {
                await navigator.clipboard.writeText(button.dataset.link);
                button.textContent = 'Copied';
                setTimeout(() => button.textContent = 'Copy link', 1600);
            });
        });
    </script>
</body>
</html>
