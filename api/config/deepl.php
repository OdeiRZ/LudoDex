<?php

return [
    // A DeepL Free plan key (ends in ":fx") - without one configured,
    // translation requests are skipped entirely rather than failing, and
    // the frontend falls back to showing the original English text with
    // an "EN" badge, same as if DeepL itself were down.
    'api_key' => env('DEEPL_API_KEY'),

    // Free plan keys only work against this endpoint, not the Pro one
    // (api.deepl.com) - DeepL rejects them there.
    'base_url' => env('DEEPL_BASE_URL', 'https://api-free.deepl.com/v2/translate'),

    'timeout' => (int) env('DEEPL_TIMEOUT', 10),
    'connect_timeout' => (int) env('DEEPL_CONNECT_TIMEOUT', 5),
];
