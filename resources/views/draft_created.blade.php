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
        <p>{{ $draft->title }} is set up with its items, participants, and selection order.</p>
        <p>Nobody can pick until you, as the host, start the draft from the session details page.</p>

        <div class="form-group">
            <a href="{{ route('draft.details', ['draft_id' => $draft->id]) }}" class="btn">Open session details</a>
        </div>

        <div class="form-group">
            <a href="{{ route('draft.edit', ['draft_id' => $draft->id]) }}" class="btn">Edit session</a>
        </div>

        <div class="form-group">
            <a href="{{ route('dashboard') }}" class="btn">Go to Homepage</a>
        </div>
    </div>
</body>
</html>
