<x-guest-layout>
    <x-slot name="title">Confirm password</x-slot>

    <h1 class="form-title">Confirm your password</h1>
    <p class="auth-lede">This is a secure area. Please confirm your password before continuing.</p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autofocus autocomplete="current-password">
            @error('password')
                <p>{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <button type="submit" class="btn">Confirm</button>
        </div>
    </form>
</x-guest-layout>
