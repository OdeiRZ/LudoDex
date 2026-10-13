<?php

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Sanctum reescribía last_used_at con un UPDATE en cada petición autenticada
// (ver PersonalAccessToken): contra Neon era una escritura de más por petición.

/**
 * Una petición como las de producción: cada una es un proceso nuevo, sin el usuario
 * ya resuelto en memoria. En un test el guard de Sanctum lo conserva entre llamadas y
 * la segunda ni siquiera pasaría por el token.
 */
function freshRequest(object $test, string $plain, string $path = '/api/user')
{
    app('auth')->forgetGuards();

    return $test->withToken($plain)->getJson($path);
}

function tokenUpdates(callable $callback): int
{
    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (str_starts_with(strtolower($query->sql), 'update "personal_access_tokens"')) {
            $writes++;
        }
    });

    $callback();

    return $writes;
}

it('writes last_used_at on the first use and then at most once every few minutes', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('test')->plainTextToken;

    $first = tokenUpdates(fn () => freshRequest($this, $plain)->assertOk());
    $second = tokenUpdates(fn () => freshRequest($this, $plain)->assertOk());
    $third = tokenUpdates(fn () => freshRequest($this, $plain)->assertOk());

    expect($first)->toBe(1)->and($second)->toBe(0)->and($third)->toBe(0);
});

it('refreshes last_used_at again once it is older than the window', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test');
    $plain = $token->plainTextToken;

    freshRequest($this, $plain)->assertOk();
    $stored = PersonalAccessToken::find($token->accessToken->id)->last_used_at;

    $this->travel(PersonalAccessToken::REFRESH_AFTER_MINUTES + 1)->minutes();
    freshRequest($this, $plain)->assertOk();

    expect(PersonalAccessToken::find($token->accessToken->id)->last_used_at->gt($stored))->toBeTrue();
});

it('does not skip any other write to the token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->accessToken;
    $token->forceFill(['last_used_at' => now()])->save();

    $token->forceFill(['name' => 'renombrado'])->save();

    expect(PersonalAccessToken::find($token->id)->name)->toBe('renombrado');
});

it('still authenticates with a token whose last_used_at was never written', function () {
    $user = User::factory()->create();

    freshRequest($this, $user->createToken('test')->plainTextToken, '/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});
