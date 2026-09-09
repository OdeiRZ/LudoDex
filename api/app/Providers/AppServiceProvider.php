<?php

namespace App\Providers;

use App\Mail\Transport\GmailApiTransport;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
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
        // Hallazgo de una auditoría de seguridad: esto estaba en min(6), por debajo
        // incluso del propio default de Laravel (min(8)), sin exigir letras ni
        // números - "123456" o "aaaaaa" eran contraseñas válidas. letters()+numbers()
        // es una barra mínima razonable sin ser tan estricta como para molestar en
        // un proyecto de portfolio (se descarta mixedCase()/symbols()); uncompromised()
        // (comprobar contra la base de contraseñas filtradas de Have I Been Pwned) se
        // descarta a propósito por ahora - añadiría una llamada HTTP real de la que
        // habría que simular en todos los tests que tocan cualquiera de los tres
        // formularios de abajo, sin aportar mucho más en un proyecto sin datos
        // sensibles. Cada Password::defaults() de la app (registro, reset de
        // contraseña, cambiarla desde el perfil) lee de este único sitio.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

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

        // Render bloquea SMTP saliente por completo (ver GmailApiTransport):
        // este driver envía por la API HTTPS de Gmail en su lugar, mismo
        // remitente (soporteludodex@gmail.com) que un dominio propio pero
        // sin necesitar uno - a diferencia de Resend en sandbox, entrega a
        // cualquier destinatario real, no solo al dueño de la cuenta.
        Mail::extend('gmail_api', fn () => new GmailApiTransport(
            (string) config('services.gmail.client_id'),
            (string) config('services.gmail.client_secret'),
            (string) config('services.gmail.refresh_token'),
        ));
    }
}
