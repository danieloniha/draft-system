<x-guest-layout>
    <x-slot name="title">Log in</x-slot>

    <h1 class="form-title">Welcome back</h1>
    <p class="auth-lede">Log in to host and manage your sessions.</p>

    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            @error('password')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div class="form-row-between">
            <label class="check-row" for="remember_me">
                <input id="remember_me" type="checkbox" name="remember"> Remember me
            </label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}">Forgot your password?</a>
            @endif
        </div>

        <div class="form-group">
            <button type="submit" class="btn">Log in</button>
        </div>
    </form>

    <div class="auth-alt">
        <p>New here?</p>
        <a class="btn-outline" href="{{ route('register') }}">Create an account</a>
        <p class="hint">Joining a public session? Just open its link &mdash; no account needed.</p>
    </div>
</x-guest-layout>
