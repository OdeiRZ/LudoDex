<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel's own out-of-the-box default is min(8) - every Password::defaults()
        // call across the app (registration, password reset, changing it from the
        // profile page) reads from this one place.
        Password::defaults(fn () => Password::min(6));

        // Laravel's default reset link points at a server-rendered
        // "password.reset" web route, which doesn't exist here - this is an
        // API-only backend with a separate SPA. Point it at the frontend's
        // own reset-password page instead, carrying the token and email as
        // query params the same way that page reads them.
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
        });

        // Same "no web equivalent route" issue as above, one layer deeper:
        // Authenticate::redirectTo() calls route('login') eagerly, before
        // AuthenticationException even finishes constructing, whenever a
        // request doesn't carry Accept: application/json (any plain HTTP
        // client that doesn't bother setting it, e.g. Http::withToken(...)
        // ->get(...) - found directly, chasing an unrelated token issue).
        // That throws RouteNotFoundException instead - a 500, not the 401
        // this API-only backend should always return - and happens too
        // early for bootstrap/app.php's own AuthenticationException render()
        // override to ever see it. Skipping the redirect attempt entirely
        // is what lets that override run at all.
        Authenticate::redirectUsing(fn () => null);
    }
}
