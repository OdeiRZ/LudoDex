<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepLTranslationService
{
    /**
     * Null covers every "couldn't translate" case the same way (no key
     * configured, DeepL unreachable, a non-2xx response) - the caller
     * doesn't need to distinguish why, since the UI's fallback (show the
     * original English text with an "EN" badge) is identical either way.
     */
    public function translateToSpanish(string $text): ?string
    {
        $apiKey = config('deepl.api_key');

        if (blank($apiKey)) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout(config('deepl.timeout'))
                ->connectTimeout(config('deepl.connect_timeout'))
                ->withHeaders(['Authorization' => 'DeepL-Auth-Key '.$apiKey])
                ->post(config('deepl.base_url'), [
                    'text' => $text,
                    'source_lang' => 'EN',
                    'target_lang' => 'ES',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('DeepL translation failed to connect', ['exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            // Not fatal enough to throw over - a translation that doesn't
            // land this time (rate limit, monthly quota exhausted, a
            // transient 5xx) just leaves description_es unset, and the
            // next click tries again.
            Log::warning('DeepL translation request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('translations.0.text');
    }
}
