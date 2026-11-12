<!-- resources/views/choose_giveaway_mode.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>What Are You Giving Away?</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    @include('partials.app-nav', ['back' => route('view.draft'), 'backLabel' => 'Draft types'])

<div class="mode-choice">
    <h1 class="mode-choice-title">What are you giving away?</h1>

    <div class="mode-choice-grid">
        <a class="mode-card" href="{{ route('create.draft.giveaway.form', ['mode' => 'items']) }}">
            <img src="{{ asset('/assets/giveaway-items.svg') }}" alt="Gift boxes and a shopping bag">
            <span class="mode-card-body">
                <strong>Items</strong>
                <small>Participants take turns claiming items, one at a time, until none are left.</small>
            </span>
        </a>

        <a class="mode-card" href="{{ route('create.draft.giveaway.form', ['mode' => 'money']) }}">
            <img src="{{ asset('/assets/giveaway-money.svg') }}" alt="Naira notes and coins">
            <span class="mode-card-body">
                <strong>Money</strong>
                <small>You set a payout table and the winners are revealed live when you start.</small>
            </span>
        </a>
    </div>
</div>

</body>
</html>
