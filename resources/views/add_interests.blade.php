<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Interests</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>

<div class="form-container">
    <h2 class="form-title">Add Interests</h2>
    
    <form method="POST" action="{{ route('store.interests', ['draft_id' => $draft_id]) }}">
        @csrf

        <div id="interests-form">
            @for ($i = 1; $i <= $no_of_interests; $i++)
                <div class="form-group">
                    <label for="interest_{{ $i }}">Interest {{ $i }}</label>
                    <input type="text" id="interest_{{ $i }}" name="interests[]" placeholder="Enter interest {{ $i }}" required>
                </div>
            @endfor
        </div>

        <div class="form-group">
            <button type="submit" class="btn">Submit Interests</button>
        </div>
    </form>
</div>

</body>
</html>
