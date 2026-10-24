{{-- Shown once a session is over, so the final page is never a dead end. Pages reveal it with #after-results. --}}
<div id="after-results" class="current-player" style="display:none;">
    @auth
        @if (auth()->user()->isGuest())
            <p class="pick-status">Want to keep your place in this session and host your own? Create an account.</p>
            <a class="btn" href="{{ route('register') }}">Sign up</a>
            <a class="btn-outline" href="{{ route('dashboard') }}">Go to homepage</a>
        @else
            <a class="btn" href="{{ route('dashboard') }}">Go to homepage</a>
        @endif
    @else
        {{-- Logged out (e.g. the session expired): the homepage needs an account, so go to login. --}}
        <a class="btn" href="{{ route('login') }}">Log in</a>
    @endauth
</div>
