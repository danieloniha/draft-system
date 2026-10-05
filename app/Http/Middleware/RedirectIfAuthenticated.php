<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            // A guest (someone playing from a link without an account) may still reach login and
            // sign-up: this is how they get an account, so sending them away would strand them.
            if (Auth::guard($guard)->check() && ! Auth::guard($guard)->user()->isGuest()) {
                return redirect(RouteServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}
