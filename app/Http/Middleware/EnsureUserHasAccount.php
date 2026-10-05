<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guests (people who joined a public session with just a name) cannot do everything an account
 * can. Two things need one: hosting a session, since a guest is only held in place by a cookie
 * and a session they created would be lost with it; and joining a private session, whose
 * invitation is tied to an email address.
 *
 * Usage: `account` (hosting) or `account:private` (joining a private session).
 */
class EnsureUserHasAccount
{
    public function handle(Request $request, Closure $next, string $for = 'host'): Response
    {
        if ($request->user()?->isGuest()) {
            // guest() remembers where they were headed, so signing up carries on to it.
            return redirect()->guest(route('register'))->with('status', match ($for) {
                'private' => 'Private sessions need an account. Sign up with the email address you were invited on to join.',
                default => 'Create an account to host your own session. Joining a public one never needs an account.',
            });
        }

        return $next($request);
    }
}
