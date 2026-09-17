<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation Links Ready</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    <div class="draft-details-container">
        <h1 class="form-title">Invitation links are ready</h1>
        <p>Share the matching link with each participant. They can register first if they do not have an account, using the email address you invited.</p>

        <div class="team-selection-container">
            <table>
                <thead>
                    <tr>
                        <th>Participant</th>
                        <th>Invitation link</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($teams as $team)
                        @php($inviteUrl = route('join.draft.form', ['token' => $team->token]))
                        <tr>
                            <td>{{ $team->email }}</td>
                            <td><button class="copy-link btn-logout" type="button" data-link="{{ $inviteUrl }}">Copy link</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="form-group" style="margin-top: 28px">
            <a class="btn" href="{{ route('show.selection.order', ['draft_id' => $draft->id]) }}">Set selection order</a>
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
