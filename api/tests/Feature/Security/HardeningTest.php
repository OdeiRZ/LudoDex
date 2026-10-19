<?php

use App\Http\Middleware\ResolveClientIp;
use App\Models\User;
use Illuminate\Http\Request;

it('sets basic security headers on every response', function () {
    $response = $this->postJson('/api/login', []);

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Strict-Transport-Security'))->toContain('max-age=');
});

it('takes the real client IP from CF-Connecting-IP in production only', function () {
    $resolve = function (string $env, ?string $header): string {
        $this->app['env'] = $env;
        $request = Request::create('/api/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
        if ($header !== null) {
            $request->headers->set('CF-Connecting-IP', $header);
        }
        (new ResolveClientIp)->handle($request, fn () => response('ok'));

        return (string) $request->ip();
    };

    expect($resolve('production', '203.0.113.9'))->toBe('203.0.113.9')
        ->and($resolve('production', 'not-an-ip'))->toBe('10.0.0.1')
        ->and($resolve('production', null))->toBe('10.0.0.1')
        // Fuera de producción cualquiera podría mandar esa cabecera.
        ->and($resolve('local', '203.0.113.9'))->toBe('10.0.0.1');
});

it('does not trust X-Forwarded-For to identify the client', function () {
    $this->app['env'] = 'production';
    $request = Request::create('/api/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
    $request->headers->set('X-Forwarded-For', '198.51.100.77');
    (new ResolveClientIp)->handle($request, fn () => response('ok'));

    expect($request->ip())->toBe('10.0.0.1');
});

it('counts the login attempts per real client IP, so one client cannot lock out another', function () {
    $this->app['env'] = 'production';
    $intento = fn (string $ip) => $this->withHeaders(['CF-Connecting-IP' => $ip])
        ->postJson('/api/login', ['email' => 'nadie@example.invalid', 'password' => 'x']);

    foreach (range(1, 6) as $i) {
        $intento('203.0.113.1')->assertStatus(422);
    }

    // El cliente 1 agotó su cubo...
    $intento('203.0.113.1')->assertStatus(429);
    // ...y el cliente 2 sigue pudiendo intentarlo.
    $intento('203.0.113.2')->assertStatus(422);
});

it('expires a token after 120 days', function () {
    $user = User::factory()->create();
    $fresh = $user->createToken('reciente')->plainTextToken;
    $old = $user->createToken('antiguo');
    $old->accessToken->forceFill(['created_at' => now()->subDays(121)])->save();

    $this->withToken($fresh)->getJson('/api/user')->assertOk();
    // El guard recuerda al usuario autenticado entre peticiones de un mismo test.
    $this->app['auth']->forgetGuards();
    $this->withToken($old->plainTextToken)->getJson('/api/user')->assertUnauthorized();
});

it('keeps a token that is almost 120 days old', function () {
    $user = User::factory()->create();
    $casiCaducado = $user->createToken('casi');
    $casiCaducado->accessToken->forceFill(['created_at' => now()->subDays(119)])->save();

    $this->withToken($casiCaducado->plainTextToken)->getJson('/api/user')->assertOk();
});
