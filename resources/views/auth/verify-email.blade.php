<x-guest-layout>
    <x-slot name="title">Verify your email</x-slot>

    <h1 class="form-title">Verify your email</h1>
    <p class="auth-lede">Thanks for signing up! Before getting started, please verify your email address by clicking the link we just emailed you. If it didn't arrive, we'll gladly send another.</p>

    @if (session('status') == 'verification-link-sent')
        <p class="notice" role="status">A new verification link has been sent to the email address you provided during registration.</p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf

        <div class="form-group">
            <button type="submit" class="btn">Resend verification email</button>
        </div>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf

        <div class="form-group">
            <button type="submit" class="btn-outline">Log out</button>
        </div>
    </form>
</x-guest-layout>
