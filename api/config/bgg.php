<?php

return [
    // BoardGameGeek now requires a registered application token, sent as a
    // Bearer token on every XML API request. Register at
    // https://boardgamegeek.com/using_the_xml_api to obtain one - the public,
    // unauthenticated XML API is a thing of the past. Without this set,
    // every BGG import request fails with a 401.
    'application_token' => env('BGG_APPLICATION_TOKEN'),
];
