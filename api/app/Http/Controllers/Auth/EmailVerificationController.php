<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * No usa el middleware `signed` a propósito - éste abortaría con la
     * página de error por defecto de Laravel antes de llegar aquí, y quien
     * pulsa este enlace es un navegador, no un cliente de la API. La firma
     * se comprueba a mano para poder redirigir siempre al frontend con
     * ?ok=0/1, tanto si falla como si acierta - misma experiencia que
     * cualquier otro enlace de email de esta app. Mismo patrón que
     * MIRA_MarketLens.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        if (! $request->hasValidSignature()) {
            return redirect("{$frontendUrl}/verify-email?ok=0");
        }

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect("{$frontendUrl}/verify-email?ok=0");
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect("{$frontendUrl}/verify-email?ok=1");
    }

    public function resend(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => __('auth.verification.already_verified')]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => __('auth.verification.resent')]);
    }
}
