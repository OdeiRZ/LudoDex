<?php

// La web (ludodex.pages.dev) y el API (onrender.com) están en dominios distintos:
// el navegador solo deja leer el ETag si el API lo expone, y repite la petición
// OPTIONS previa antes de cada llamada si no se le dice cuánto puede recordarla.

it('exposes the ETag header to the browser', function () {
    $response = $this->withHeaders(['Origin' => 'https://ludodex.pages.dev'])->getJson('/api/mechanics');

    expect(strtolower((string) $response->headers->get('Access-Control-Expose-Headers')))->toContain('etag');
});

it('lets the browser remember the preflight result for a while', function () {
    $response = $this->call('OPTIONS', '/api/bootstrap', [], [], [], [
        'HTTP_ORIGIN' => 'https://ludodex.pages.dev',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,if-none-match',
    ]);

    expect((int) $response->headers->get('Access-Control-Max-Age'))->toBeGreaterThanOrEqual(3600)
        ->and(strtolower((string) $response->headers->get('Access-Control-Allow-Headers')))->toContain('if-none-match');
});
