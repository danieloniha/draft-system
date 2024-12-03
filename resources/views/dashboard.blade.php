<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Selection</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    <!-- User greeting and Logout Button -->
    <div class="header">
        <h1 class="user-greeting">Hi, {{ Auth::user()->name }}</h1>
        <form action="{{ route('logout') }}" method="POST" class="logout-form">
            @csrf
            <button type="submit" class="btn-logout">Log Out</button>
        </form>
    </div>

    <!-- Draft selection container -->
    <div class="draft-container">
        <div class="draft-box">
            <a href="{{ route('view.draft') }}">Create Draft</a>
        </div>
        <div class="draft-box">
            <a href="{{ route('join.draft.form') }}">Join Draft</a>
        </div>
    </div>
</body>
</html>
