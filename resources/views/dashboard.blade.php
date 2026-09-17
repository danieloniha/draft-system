<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PickTurn</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    <div class="header">
        <h1 class="user-greeting">Welcome back, {{ Auth::user()->username }}</h1>
        <form action="{{ route('logout') }}" method="POST" class="logout-form">
            @csrf
            <button type="submit" class="btn-logout">Log Out</button>
        </form>
    </div>

    <div class="draft-container">
        <div class="draft-box">
            <a href="{{ route('view.draft') }}">Create a session</a>
        </div>
        <div class="draft-box">
            <a href="{{ route('join.draft.form') }}">Join a session</a>
        </div>
    </div>
</body>
</html>
