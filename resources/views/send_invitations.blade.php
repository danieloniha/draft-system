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
        <h2 class="form-title">Add Players</h2>

        <form method="POST" action="{{ route('invite.teams', ['draft_id' => $draft_id]) }}">
            @csrf
            <div id="teams-form">
                @for ($i = 1; $i <= $no_of_teams; $i++)
                    <div class="form-group">
                        <label for="email{{ $i }}">Player {{ $i }} Email</label>
                        <input type="email" id="email{{ $i }}" name="emails[]"
                            value="{{ old('emails.' . ($i - 1)) }}"
                            placeholder="Enter player {{ $i }}'s email" required>
                        @error('emails.' . ($i - 1))
                            <p>{{ $message }}</p>
                        @enderror
                    </div>
                @endfor
            </div>
            <div class="form-group">
                <button type="submit" class="btn">Add Players</button>
            </div>
        </form>
    </div>

</body>

</html>
