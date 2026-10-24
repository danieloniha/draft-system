<!-- resources/views/choose_giveaway_mode.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>What are you giving away?</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>

<div class="mode-container">
    <h1 class="mode-title">What are you giving away?</h1>

    <div class="mode-grid">
        <a class="mode-card" href="{{ route('create.draft.giveaway.form', ['mode' => 'items']) }}">
            <img src="{{ asset('assets/images/giveaway-items.jpg') }}" alt="Everyday items spilling out of a bag">
            <span class="mode-card-body">
                <strong>Items</strong>
                <small>Participants take turns claiming items, one at a time, until none are left.</small>
            </span>
        </a>

        <a class="mode-card" href="{{ route('create.draft.giveaway.form', ['mode' => 'money']) }}">
            <img src="{{ asset('assets/images/giveaway-money.jpg') }}" alt="Stacks of naira notes">
            <span class="mode-card-body">
                <strong>Money</strong>
                <small>You set a payout table and everyone is ranked. The results are revealed when you start.</small>
            </span>
        </a>
    </div>

    <p><a href="{{ route('view.draft') }}">&larr; Back</a></p>
</div>

</body>
</html>
