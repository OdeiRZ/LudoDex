<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromHeader
{
    private const SUPPORTED_LOCALES = ['es', 'en'];

    /**
     * Handle an incoming request.
     *
     * The frontend sends its selected locale as a plain "es"/"en" value, but
     * this also tolerates a real browser-style Accept-Language header
     * (e.g. "en-US,en;q=0.9") by only looking at the primary subtag.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $preferred = substr((string) $request->header('Accept-Language'), 0, 2);

        if (in_array($preferred, self::SUPPORTED_LOCALES, true)) {
            app()->setLocale($preferred);
        }

        return $next($request);
    }
}
