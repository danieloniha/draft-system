<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') &middot; PickTurn</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    <div class="form-container" style="text-align:center">
        <p class="pick-status" style="font-size:3rem;font-weight:700;margin-bottom:0">@yield('code')</p>
        <h2 class="form-title">@yield('title')</h2>
        <p>@yield('message')</p>

        <div class="form-group">
            @auth
                <a href="{{ route('dashboard') }}" class="btn">Go to your sessions</a>
            @else
                <a href="{{ url('/') }}" class="btn">Go to the home page</a>
            @endauth
        </div>
        <div class="form-group">
            <a href="javascript:history.back()" class="btn-outline">Go back</a>
        </div>
    </div>
</body>
</html>
