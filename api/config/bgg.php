<?php

return [
    // BoardGameGeek now requires a registered application token, sent as a
    // Bearer token on every XML API request. Register at
    // https://boardgamegeek.com/using_the_xml_api to obtain one - the public,
    // unauthenticated XML API is a thing of the past. Without this set,
    // every BGG import request fails with a 401.
    'application_token' => env('BGG_APPLICATION_TOKEN'),

    // BGG's own XML API terms of use ask that results be cached rather than
    // re-requested - a game's mechanics/categories/rank/rating barely change
    // day to day, and the same popular games keep coming up across imports
    // (different users, or the same user re-importing). Keyed per BGG id in
    // fetchGameDetails()/fetchGameByBggId(), not the /collection endpoint
    // itself (that one reflects a specific user's current owned/wishlist
    // status, which caching would make stale on purpose).
    'cache_ttl' => (int) env('BGG_CACHE_TTL', 60 * 60 * 24),

    // Without an explicit timeout, a hung BGG connection ties up the PHP
    // worker handling the request indefinitely - imports and lookups both
    // run synchronously in-request, there's no queue in front of them.
    'timeout' => (int) env('BGG_TIMEOUT', 15),
    'connect_timeout' => (int) env('BGG_CONNECT_TIMEOUT', 5),
];
