<?php

return [
    // Only used by translations:sync (see App\Console\Commands\SyncTranslations)
    // - a personal-use, run-from-local-only command, so a single fixed token
    // is enough; no need for a per-environment credential store.
    'api_url' => env('PROD_API_URL'),
    'api_token' => env('PROD_API_TOKEN'),
];
