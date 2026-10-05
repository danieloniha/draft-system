<x-guest-layout>
    <x-slot name="title">Create an account</x-slot>

    <h1 class="form-title">Create your account</h1>
    <p class="auth-lede">You need an account to host sessions and to join private ones. Public sessions can be joined with just a name.</p>

    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
            @error('username')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">
            @error('email')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
            @error('password')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <label for="password_confirmation">Confirm password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            @error('password_confirmation')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <button type="submit" class="btn">Create account</button>
        </div>
    </form>

    <div class="auth-alt">
        <p>Already have an account?</p>
        <a class="btn-outline" href="{{ route('login') }}">Log in</a>
    </div>
</x-guest-layout>
