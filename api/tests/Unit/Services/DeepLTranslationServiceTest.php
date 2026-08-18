<?php

use App\Services\DeepLTranslationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('returns the translated text from a successful DeepL response', function () {
    config(['deepl.api_key' => 'fake-key:fx']);
    Http::fake(fn () => Http::response([
        'translations' => [['detected_source_language' => 'EN', 'text' => 'Un juego de mesa clásico.']],
    ]));

    $result = (new DeepLTranslationService)->translateToSpanish('A classic board game.');

    expect($result)->toBe('Un juego de mesa clásico.');
});

it('sends the API key and text as DeepL expects', function () {
    config(['deepl.api_key' => 'fake-key:fx', 'deepl.base_url' => 'https://api-free.deepl.com/v2/translate']);
    Http::fake(fn () => Http::response(['translations' => [['text' => 'Hola']]]));

    (new DeepLTranslationService)->translateToSpanish('Hello');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api-free.deepl.com/v2/translate'
            && $request->hasHeader('Authorization', 'DeepL-Auth-Key fake-key:fx')
            && $request['text'] === 'Hello'
            && $request['source_lang'] === 'EN'
            && $request['target_lang'] === 'ES';
    });
});

it('returns null without calling DeepL at all when no API key is configured', function () {
    config(['deepl.api_key' => null]);
    Http::fake(fn () => Http::response('should not be called', 500));

    $result = (new DeepLTranslationService)->translateToSpanish('Hello');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

it('returns null instead of throwing when DeepL answers with an error status', function () {
    config(['deepl.api_key' => 'fake-key:fx']);
    Http::fake(fn () => Http::response(['message' => 'Quota exceeded'], 456));

    $result = (new DeepLTranslationService)->translateToSpanish('Hello');

    expect($result)->toBeNull();
});

it('returns null instead of throwing when DeepL cannot be reached', function () {
    config(['deepl.api_key' => 'fake-key:fx']);
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $result = (new DeepLTranslationService)->translateToSpanish('Hello');

    expect($result)->toBeNull();
});
