{{-- One way around the app from every signed-in page. Optional: $back (url) and $backLabel. --}}
@auth
    <nav class="app-nav" aria-label="Main">
        @isset($back)
            <a class="app-nav-back" href="{{ $back }}">&larr; {{ $backLabel ?? 'Back' }}</a>
        @endisset
        <span class="app-nav-spacer"></span>
        <a href="{{ route('dashboard') }}">Home</a>
        <a href="{{ route('wallet.show') }}">Wallet &middot; ₦{{ number_format(Auth::user()->balance) }}</a>
    </nav>
@endauth
