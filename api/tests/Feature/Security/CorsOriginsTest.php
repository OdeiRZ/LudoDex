<?php

// CORS (config/cors.php): solo el frontend propio, sus vistas previas y
// localhost pueden leer el API desde un navegador.

it('lets the app frontend read the API from the browser', function (string $origin) {
    $response = $this->withHeaders(['Origin' => $origin])->getJson('/api/user');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe($origin);
})->with(['https://ludodex.pages.dev', 'http://localhost:5173', 'http://127.0.0.1:4173', 'https://abc123.ludodex.pages.dev']);

it('does not let any other site read it', function (string $origin) {
    $response = $this->withHeaders(['Origin' => $origin])->getJson('/api/user');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
})->with(['https://evil.example', 'https://ludodex.pages.dev.evil.example', 'http://localhost.evil.example']);
