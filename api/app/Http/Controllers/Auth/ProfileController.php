<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\User;
use App\Services\Bgg\BggClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function __construct(private readonly BggClient $bggClient) {}

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $bggUsername = $request->validated('bgg_username');
        $newEmail = $request->validated('email');
        $emailChanged = $newEmail !== $user->email;

        $attributes = [
            'name' => $request->validated('name'),
            'email' => $newEmail,
            'bgg_username' => $bggUsername,
            // ?? false (not just $user->discoverable) because a model
            // instance that never got the DB default re-fetched into its
            // in-memory attributes (e.g. straight after
            // User::factory()->create() with no explicit value) reads as
            // null here, not false - and this column is NOT NULL.
            'discoverable' => $request->validated('discoverable', $user->discoverable ?? false),
            // Same ?? fallback reasoning as discoverable above, but ??
            // true - these two default to true (see the users migration),
            // not false.
            'share_collection' => $request->validated('share_collection', $user->share_collection ?? true),
            'share_plays' => $request->validated('share_plays', $user->share_plays ?? true),
        ];

        // Best-effort: a BGG lookup failure (no token yet, unknown username,
        // BGG unreachable) never blocks saving the rest of the profile - the
        // avatar is a nice-to-have, not something worth failing the request
        // over. Only re-fetched when the username actually changed, so
        // saving the rest of the profile repeatedly doesn't hammer BGG.
        if (filled($bggUsername) && $bggUsername !== $user->bgg_username) {
            $attributes['avatar_url'] = $this->bggClient->fetchUserAvatar($bggUsername);
        } elseif (blank($bggUsername)) {
            $attributes['avatar_url'] = null;
        }

        $user->update($attributes);

        // Hallazgo de una auditoría de seguridad: cambiar el email dejaba
        // email_verified_at intacto, así que la cuenta quedaba con una
        // dirección nueva marcada como "verificada" sin haberla confirmado
        // nunca - la nueva dirección podría ni siquiera pertenecer a quien
        // hizo el cambio. Laravel resetea esto por convención al cambiar
        // el email (ver MustVerifyEmail), pero email_verified_at no está
        // (ni debe estar) en $fillable, así que no basta con meterlo en
        // $attributes de arriba - update() lo ignoraría en silencio.
        // forceFill() lo salta explícitamente, sin abrir esa columna a
        // asignación en masa desde ningún otro sitio.
        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null])->save();
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['user' => $user]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update(['password' => Hash::make($request->validated('password'))]);

        // Revoke every OTHER token - tokens never expire (see
        // config/sanctum.php), so a stolen token must stop working once
        // the legitimate owner changes their password. The token making
        // THIS request is deliberately kept alive, so changing your own
        // password doesn't log out the session you're using right now.
        $currentToken = $user->currentAccessToken();
        $user->tokens()
            ->when($currentToken, fn ($query) => $query->where('id', '!=', $currentToken->id))
            ->delete();

        return response()->json(status: 204);
    }
}
