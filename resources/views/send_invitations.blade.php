<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Teams</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>

<body>

    <div class="form-container">
        <h2 class="form-title">Add Interests</h2>

        <form method="POST" action="{{ route('invite.teams', ['draft_id' => $draft_id]) }}">
            @csrf
            <div id="teams-form">
                @for ($i = 1; $i <= $no_of_teams; $i++)
                    <div class="form-group">
                        <label for="username{{ $i }}">Player {{ $i }}</label>
                        <input type="username" id="username{{ $i }}" name="username[]"
                            placeholder="Enter players username {{ $i }}" required>
                    </div>
                @endfor
            </div>
            <div class="form-group">
                <button type="submit" class="btn">Send Invitations</button>
            </div>
        </form>
    </div>

</body>

</html>
