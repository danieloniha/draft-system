<x-guest-layout>
    <x-slot name="title">Forgot password</x-slot>

    <h1 class="form-title">Forgot your password?</h1>
    <p class="auth-lede">Enter your email and we'll send you a link to choose a new one.</p>

    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
            @error('email')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <button type="submit" class="btn">Email reset link</button>
        </div>
    </form>

    <div class="auth-alt">
        <a class="btn-outline" href="{{ route('login') }}">Back to log in</a>
    </div>
</x-guest-layout>
