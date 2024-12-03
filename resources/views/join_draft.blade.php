<!-- resources/views/join_draft.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Draft</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    <div class="form-container">
        <h2 class="form-title">Join Draft</h2>

        <form method="POST" action="{{ route('join.draft') }}">
            @csrf
            <div class="form-group">
                <label for="token">Enter Token</label>
                <input type="text" id="token" name="token" placeholder="Enter your invitation token" required>
            </div>

            <div class="form-group">
                <button type="submit" class="btn">Join Draft</button>
            </div>
        </form>
    </div>
</body>
</html>
