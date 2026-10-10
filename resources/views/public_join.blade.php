<!-- resources/views/public_join.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join {{ $draft->name }}</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    @php($full = $count >= $draft->participant_limit)

    <div class="form-container">
        <h2 class="form-title">{{ $draft->name }}</h2>
        <p>{{ $draft->title }}</p>
        <p class="pick-status">
            Hosted by {{ $draft->creator?->username ?? 'someone' }} &middot;
            {{ $draft->type === 'bidding' ? 'Bidding' : 'Giveaway' }} &middot;
            {{ $count }} / {{ $draft->participant_limit }} joined
        </p>

        @if ($errors->has('join'))
            <p class="notice notice-error" role="alert">{{ $errors->first('join') }}</p>
        @endif

        @if ($isParticipant)
            <p>You have already joined this draft.</p>
            <div class="form-group">
                <a class="btn" href="{{ route('draft.details', ['draft_id' => $draft->id]) }}">Go to session details</a>
            </div>
        @elseif (! $draft->isPublic())
            <p>This link is no longer active.</p>
        @elseif ($draft->hasStarted())
            <p>This draft has already started, so it is no longer open to join.</p>
        @elseif ($full)
            <p>This draft is full.</p>
        @else
            <form method="POST" action="{{ route('public.join', ['public_token' => $draft->public_token]) }}">
                @csrf

                {{-- Nobody logged in: no account is needed, just a name to be known by. --}}
                @guest
                    <div class="form-group">
                        <label for="username">Your name</label>
                        <input type="text" id="username" name="username" value="{{ old('username') }}" maxlength="50" placeholder="What should everyone call you?" required autofocus>
                        <p class="hint">No account needed &mdash; this is just the name others will see.</p>
                        @error('username')
                            <p>{{ $message }}</p>
                        @enderror
                    </div>
                @endguest

                <div class="form-group">
                    <label for="email">Your email</label>
                    <input type="email" id="email" name="email" value="{{ old('email', auth()->user()?->email) }}" maxlength="255" placeholder="you@example.com" required autocomplete="email">
                    <p class="hint">The host uses this to reach you about the session.</p>
                    @error('email')
                        <p>{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <button type="submit" class="btn">Join this draft</button>
                </div>
            </form>
        @endif
    </div>
</body>
</html>
