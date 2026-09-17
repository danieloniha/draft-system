<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PickTurn</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    <header class="header">
        <span class="user-greeting">Simple, fair group selections.</span>
        @if (Route::has('login'))
            <nav>
                @auth
                    <a class="btn" href="{{ url('/dashboard') }}">Go to dashboard</a>
                @else
                    <a class="btn-logout" href="{{ route('login') }}">Log in</a>
                    @if (Route::has('register'))
                        <a class="btn" style="margin-left: 8px" href="{{ route('register') }}">Get started</a>
                    @endif
                @endauth
            </nav>
        @endif
    </header>

    <main class="form-container" style="width: min(720px, 100%); margin-top: 9vh; text-align: center;">
        <p style="margin-bottom: 12px; color: var(--accent); font-size: .82rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;">Group decisions, made easy</p>
        <h1 class="form-title" style="font-size: clamp(2.25rem, 6vw, 4.25rem);">Give everyone a fair turn.</h1>
        <p style="max-width: 520px; margin: 20px auto 28px; font-size: 1.1rem;">Create a session, invite your group, and let PickTurn manage the selection order while everyone focuses on the choices.</p>
        @auth
            <a href="{{ url('/dashboard') }}" class="btn">Open dashboard</a>
        @else
            <a href="{{ route('register') }}" class="btn">Create your first session</a>
        @endauth
    </main>
</body>
</html>
