<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $attributes = [
            'email' => $request->email,
            'username' => $request->username,
            'password' => Hash::make($request->password),
            'is_guest' => false,
        ];

        $user = $request->user();

        if ($user?->isGuest()) {
            // Someone playing as a guest signing up keeps the seats they already hold: the same
            // row simply becomes a full account, rather than a second user being created.
            $user->update($attributes);
        } else {
            $user = User::create($attributes);
        }

        event(new Registered($user));

        Auth::login($user);

        // Carries on to where they were headed, like logging in does: someone who signed up from
        // a private invitation link lands back on it rather than on the dashboard.
        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
