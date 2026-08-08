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
        $attributes = [
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'bgg_username' => $bggUsername,
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

        return response()->json(['user' => $user]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update(['password' => Hash::make($request->validated('password'))]);

        return response()->json(status: 204);
    }
}
