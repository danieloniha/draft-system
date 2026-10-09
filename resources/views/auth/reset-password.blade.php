<x-guest-layout>
    <x-slot name="title">Reset password</x-slot>

    <h1 class="form-title">Choose a new password</h1>
    <p class="auth-lede">Enter your email and the new password you'd like to use.</p>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username">
            @error('email')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
            <p class="hint">Minimum 6 characters.</p>
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
            <button type="submit" class="btn">Reset password</button>
        </div>
    </form>
</x-guest-layout>
