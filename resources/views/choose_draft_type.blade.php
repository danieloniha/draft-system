<!-- resources/views/choose_draft_type.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose a Draft Type</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>

<div class="template-container">
    <div class="template-box">
        <a href="{{ route('create.draft.giveaway.form') }}">
            Giveaway
            <small>Participants take turns claiming items, one at a time, until none are left.</small>
        </a>
    </div>
    <div class="template-box">
        <a href="{{ route('create.draft.bidding.form') }}">
            Bidding
            <small>Items go up for auction one at a time. Participants bid, and the highest bid wins.</small>
        </a>
    </div>
</div>

</body>
</html>
