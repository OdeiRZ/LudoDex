<?php

namespace App\Services\Bgg;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class BggClient
{
    private const BASE_URL = 'https://boardgamegeek.com/xmlapi2';

    /**
     * BGG's collection export is itself asynchronous: the first request
     * queues it and answers 202 until it's ready. `subtype` only accepts one
     * value per request and defaults to boardgame-only, so expansions need a
     * second call - done here so callers don't have to know that.
     *
     * @return array{status: 'pending'|'error'|'ready', message?: string, transient?: bool, items?: list<array{bgg_id: int, name: string, image_url: ?string, is_expansion: bool, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int, collection_status: 'owned'|'wishlist'}>}
     */
    public function fetchCollection(string $username): array
    {
        if (blank(config('bgg.application_token'))) {
            return [
                'status' => 'error',
                'message' => __('bgg.token_missing'),
                'transient' => false,
            ];
        }

        $boardgames = $this->fetchCollectionBySubtype($username, 'boardgame');

        if ($boardgames['status'] !== 'ready') {
            return $boardgames;
        }

        $expansions = $this->fetchCollectionBySubtype($username, 'boardgameexpansion');

        if ($expansions['status'] !== 'ready') {
            return $expansions;
        }

        return [
            'status' => 'ready',
            'items' => [...$boardgames['items'], ...$expansions['items']],
        ];
    }

    /**
     * @return array{status: 'pending'|'error'|'ready', message?: string, transient?: bool, items?: list<array<string, mixed>>}
     */
    private function fetchCollectionBySubtype(string $username, string $subtype): array
    {
        try {
            $response = $this->httpClient()->get(self::BASE_URL.'/collection', [
                'username' => $username,
                'subtype' => $subtype,
                'stats' => 1,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('BGG collection request failed to connect', ['subtype' => $subtype, 'exception' => $e->getMessage()]);

            return ['status' => 'error', 'message' => __('bgg.unreachable'), 'transient' => true];
        }

        if ($response->status() === 202) {
            return ['status' => 'pending'];
        }

        // A non-2xx response or garbled body is BGG (or the network) having
        // a bad moment, not evidence the request itself is wrong - worth
        // retrying. A well-formed <errors> response (bad username) below is
        // not: retrying it would just get the same answer.
        if (! $response->successful()) {
            return ['status' => 'error', 'message' => __('bgg.unreachable'), 'transient' => true];
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            return ['status' => 'error', 'message' => __('bgg.unexpected_response'), 'transient' => true];
        }

        if ($xml->getName() === 'errors') {
            $message = isset($xml->error->message)
                ? (string) $xml->error->message
                : __('bgg.user_not_found');

            return ['status' => 'error', 'message' => $message, 'transient' => false];
        }

        $items = [];

        foreach ($xml->item as $item) {
            $status = $item->status;
            $isOwned = (string) $status['own'] === '1';
            $isWishlisted = (string) $status['wishlist'] === '1';

            if (! $isOwned && ! $isWishlisted) {
                continue;
            }

            $stats = $item->stats;

            $items[] = [
                'bgg_id' => (int) $item['objectid'],
                'name' => (string) $item->name,
                'image_url' => isset($item->image) && (string) $item->image !== '' ? (string) $item->image : null,
                'is_expansion' => (string) $item['subtype'] === 'boardgameexpansion',
                'min_players' => $this->positiveIntOrNull($stats['minplayers'] ?? null),
                'max_players' => $this->positiveIntOrNull($stats['maxplayers'] ?? null),
                'min_playtime_minutes' => $this->positiveIntOrNull($stats['minplaytime'] ?? null),
                'max_playtime_minutes' => $this->positiveIntOrNull($stats['maxplaytime'] ?? null),
                'collection_status' => $isOwned ? 'owned' : 'wishlist',
            ];
        }

        return ['status' => 'ready', 'items' => $items];
    }

    /**
     * A user's entire play history, paginated internally - BGG caps /plays
     * at 100 <play> elements per page. Unlike /collection, this answers
     * synchronously (no 202-while-queued state to poll), same as /thing.
     * Never cached: unlike game metadata, a user's plays change constantly
     * (new ones logged, old ones edited), so caching would just mean
     * serving stale history.
     *
     * @return array{status: 'ready'|'error', message?: string, transient?: bool, plays?: list<array{bgg_play_id: int, bgg_id: int, name: string, played_at: string, quantity: int, duration_minutes: ?int}>}
     */
    public function fetchPlays(string $username): array
    {
        if (blank(config('bgg.application_token'))) {
            return [
                'status' => 'error',
                'message' => __('bgg.token_missing'),
                'transient' => false,
            ];
        }

        $plays = [];

        // Defensive cap, not an expected limit - a real account's history
        // paginates out well before 500 pages (50,000 plays). Guards
        // against looping forever on a malformed response that never
        // reports fewer than 100 plays.
        for ($page = 1; $page <= 500; $page++) {
            try {
                $response = $this->httpClient()->get(self::BASE_URL.'/plays', [
                    'username' => $username,
                    'page' => $page,
                ]);
            } catch (ConnectionException $e) {
                Log::warning('BGG /plays request failed to connect', ['page' => $page, 'exception' => $e->getMessage()]);

                return ['status' => 'error', 'message' => __('bgg.unreachable'), 'transient' => true];
            }

            if (! $response->successful()) {
                return ['status' => 'error', 'message' => __('bgg.unreachable'), 'transient' => true];
            }

            $xml = @simplexml_load_string($response->body());

            if ($xml === false) {
                return ['status' => 'error', 'message' => __('bgg.unexpected_response'), 'transient' => true];
            }

            if ($xml->getName() === 'errors') {
                $message = isset($xml->error->message)
                    ? (string) $xml->error->message
                    : __('bgg.user_not_found');

                return ['status' => 'error', 'message' => $message, 'transient' => false];
            }

            $pagePlayCount = 0;

            foreach ($xml->play as $play) {
                $pagePlayCount++;

                if (! isset($play->item)) {
                    continue;
                }

                $plays[] = [
                    'bgg_play_id' => (int) $play['id'],
                    'bgg_id' => (int) $play->item['objectid'],
                    'name' => (string) $play->item['name'],
                    'played_at' => (string) $play['date'],
                    'quantity' => $this->positiveIntOrNull($play['quantity'] ?? null) ?? 1,
                    'duration_minutes' => $this->positiveIntOrNull($play['length'] ?? null),
                ];
            }

            if ($pagePlayCount < 100) {
                break;
            }
        }

        return ['status' => 'ready', 'plays' => $plays];
    }

    /**
     * @param  list<int>  $bggIds
     * @return array<int, array{name: string, image_url: ?string, description: ?string, mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_ids: list<int>, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int}>
     */
    public function fetchGameDetails(array $bggIds): array
    {
        $details = [];
        $idsToFetch = [];

        // One query for the whole batch instead of one per id - with
        // CACHE_STORE=database (Neon in production), a ~100-game collection
        // otherwise meant ~100 individual round trips just to find out
        // what's already cached, each paying the connection's own network
        // latency - the actual cause of imports going from seconds to
        // minutes once caching was added, not BGG itself being slower.
        $cached = Cache::many(array_map($this->cacheKey(...), $bggIds));

        foreach ($bggIds as $bggId) {
            $value = $cached[$this->cacheKey($bggId)] ?? null;

            if ($value !== null) {
                $details[$bggId] = $value;
            } else {
                $idsToFetch[] = $bggId;
            }
        }

        $toCache = [];

        foreach (array_chunk($idsToFetch, 20) as $chunk) {
            try {
                $response = $this->httpClient()->get(self::BASE_URL.'/thing', [
                    'id' => implode(',', $chunk),
                    'stats' => 1,
                ]);
            } catch (ConnectionException $e) {
                Log::warning('BGG /thing request failed to connect, skipping chunk', ['ids' => $chunk, 'exception' => $e->getMessage()]);

                continue;
            }

            // A failed chunk (up to 20 games) is silently missing from the
            // result below - callers still treat the import as complete, so
            // this is the only record that some games didn't get their
            // weight/rating/mechanics/categories this time around.
            if (! $response->successful()) {
                Log::warning('BGG /thing request failed, skipping chunk', ['ids' => $chunk, 'status' => $response->status()]);

                continue;
            }

            $xml = @simplexml_load_string($response->body());

            if ($xml === false) {
                Log::warning('BGG /thing returned an unparsable response, skipping chunk', ['ids' => $chunk]);

                continue;
            }

            foreach ($xml->item as $item) {
                $id = (int) $item['id'];
                $detail = $this->parseGameDetail($item);

                $toCache[$this->cacheKey($id)] = $detail;
                $details[$id] = $detail;
            }
        }

        // Same batching reasoning as the read above, for the write side -
        // one query to store every newly-fetched detail instead of one per
        // game.
        if ($toCache !== []) {
            Cache::putMany($toCache, $this->cacheTtl());
        }

        return $details;
    }

    // Versioned so a change to what parseGameDetail() puts in the cached
    // array (like adding 'description' in 0.6.0) can't silently keep
    // serving an older shape missing the new key until the 24h TTL happens
    // to expire on its own - bump this suffix whenever that shape changes
    // again. Discovered live: a reimport within the TTL window reused
    // pre-0.6.0 cached entries with no 'description' key at all, so every
    // already-cached game kept showing no description despite the feature
    // being deployed and working for anything not yet cached.
    private function cacheKey(int $bggId): string
    {
        return "bgg:thing:v2:{$bggId}";
    }

    /**
     * Best-effort name lookup for BGG ids we may already know about from an
     * earlier cached /thing call, without triggering a new one - used to
     * name a game we don't have a Game row for at all (e.g. an expansion's
     * base game that isn't in the same CSV/collection being imported). One
     * Cache::many() call for every id at once instead of one per id, same
     * batching reasoning as fetchGameDetails() above. An id absent from the
     * result means a cache miss (or an empty cached name) rather than a
     * live BGG call, since this is only ever for a "nice to have" detail in
     * a message, not something worth the extra request on its own.
     *
     * @param  list<int>  $bggIds
     * @return array<int, string>
     */
    public function getCachedGameNames(array $bggIds): array
    {
        if ($bggIds === []) {
            return [];
        }

        $cached = Cache::many(array_map($this->cacheKey(...), $bggIds));

        $names = [];

        foreach ($bggIds as $bggId) {
            $value = $cached[$this->cacheKey($bggId)] ?? null;

            if ($value !== null && $value['name'] !== '') {
                $names[$bggId] = $value['name'];
            }
        }

        return $names;
    }

    private function cacheTtl(): int
    {
        return (int) config('bgg.cache_ttl');
    }

    /**
     * The single source of truth for everything /thing knows about a game -
     * shared by the bulk import path (fetchGameDetails) and the single-game
     * lookup path (fetchGameByBggId), and what gets cached per BGG id. The
     * bulk path only reads a handful of these keys back out (see
     * BggImportService::upsertGames, which sources name/image/player counts
     * from the /collection call instead), the rest exist for the lookup
     * path - keeping one shape here instead of two nearly-identical parsers
     * is what makes caching a single entry per id possible.
     *
     * @return array{name: string, image_url: ?string, description: ?string, mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_ids: list<int>, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int}
     */
    private function parseGameDetail(SimpleXMLElement $item): array
    {
        $mechanics = [];
        $categories = [];
        // An expansion can carry more than one inbound boardgameexpansion
        // link - e.g. a deck sold as an expansion for both the base game
        // and a bundled reimplementation of it. Collecting all of them
        // (instead of keeping only the last one seen) lets the caller pick
        // whichever candidate is actually the one present in a given
        // collection/CSV, rather than silently linking to - or failing to
        // link to anything at all because of - one that happens to be last
        // in BGG's own response order.
        $baseGameBggIds = [];

        foreach ($item->link as $link) {
            $type = (string) $link['type'];

            if ($type === 'boardgamemechanic') {
                $mechanics[] = (string) $link['value'];
            } elseif ($type === 'boardgamecategory') {
                $categories[] = (string) $link['value'];
            } elseif ($type === 'boardgameexpansion' && (string) $link['inbound'] === 'true') {
                $baseGameBggIds[] = (int) $link['id'];
            }
        }

        // BGG reports these with far more precision than anyone needs (4-5
        // decimal places is typical, e.g. "2.2809") - the game/edit form's
        // number inputs only accept two (step="0.01", matching the DB
        // columns' own decimal(_,2)), so an unrounded value filled in via
        // "Rellenar desde BGG" failed the browser's own native validation
        // on save. Rounds to two decimals here, the same as the CSV
        // importer's parsePositiveFloat() already does, including treating
        // a reported 0 (no votes yet) as "no data" rather than a real zero.
        $weight = isset($item->statistics->ratings->averageweight)
            ? $this->positiveFloatOrNull((string) $item->statistics->ratings->averageweight['value'])
            : null;

        $rating = isset($item->statistics->ratings->average)
            ? $this->positiveFloatOrNull((string) $item->statistics->ratings->average['value'])
            : null;

        $name = '';

        foreach ($item->name as $nameLink) {
            if ((string) $nameLink['type'] === 'primary') {
                $name = (string) $nameLink['value'];

                break;
            }
        }

        return [
            'name' => $name,
            'image_url' => isset($item->image) && (string) $item->image !== '' ? (string) $item->image : null,
            'description' => $this->cleanDescription(isset($item->description) ? (string) $item->description : null),
            'mechanics' => $mechanics,
            'categories' => $categories,
            'weight' => $weight,
            'base_game_bgg_ids' => $baseGameBggIds,
            'year_published' => $this->positiveIntOrNull($item->yearpublished['value'] ?? null),
            // BGG's own site shows this value with a trailing "+" (e.g.
            // "Ages: 8+"), not as a plain number - kept as a string here
            // (like the CSV importer's bggrecagerange) rather than a plain
            // int, since it's a display convention, not an exact minimum.
            'min_age' => $this->minAgeOrNull($item->minage['value'] ?? null),
            'bgg_rank' => $this->extractBoardGameRank($item),
            'rating' => $rating,
            'min_players' => $this->positiveIntOrNull($item->minplayers['value'] ?? null),
            'max_players' => $this->positiveIntOrNull($item->maxplayers['value'] ?? null),
            'min_playtime_minutes' => $this->positiveIntOrNull($item->minplaytime['value'] ?? null),
            'max_playtime_minutes' => $this->positiveIntOrNull($item->maxplaytime['value'] ?? null),
        ];
    }

    /**
     * The overall "Board Game Rank" is one of several ranks BGG tracks per
     * game (family/subtype ranks like "Strategy Game Rank" are ignored
     * here) - reported as the literal string "Not Ranked" rather than a
     * missing attribute when a game has too few ratings to place, so this
     * can't just cast to int.
     */
    private function extractBoardGameRank(SimpleXMLElement $item): ?int
    {
        if (! isset($item->statistics->ratings->ranks->rank)) {
            return null;
        }

        foreach ($item->statistics->ratings->ranks->rank as $rank) {
            if ((string) $rank['name'] === 'boardgame') {
                $value = (string) $rank['value'];

                return ctype_digit($value) ? (int) $value : null;
            }
        }

        return null;
    }

    private function minAgeOrNull(mixed $value): ?string
    {
        if ($value === null || (string) $value === '' || (int) $value <= 0) {
            return null;
        }

        return ((string) (int) $value).'+';
    }

    /**
     * BGG's description field is itself HTML, but entity-encoded on top of
     * XML's own encoding - SimpleXML only unwinds the outer (XML) layer, so
     * a raw cast still contains literal entities like "&amp;quot;" and
     * "&amp;#10;" instead of real characters. Decoding twice unwinds both.
     */
    private function cleanDescription(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = html_entity_decode(
            html_entity_decode($raw, ENT_QUOTES | ENT_HTML5),
            ENT_QUOTES | ENT_HTML5
        );

        // BGG's own line breaks arrive as literal "\n" pairs (there's no
        // real HTML markup to speak of) - collapsing 3+ in a row down to a
        // single blank line keeps paragraph breaks without the runs of
        // empty lines BGG's raw text tends to have between them.
        $normalized = trim((string) preg_replace('/\n{3,}/', "\n\n", $decoded));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Looks up a single game by its BGG id for the manual add/edit form's
     * "import from BGG" button - unlike fetchCollection, /thing answers
     * synchronously (no 202-while-queued state to poll).
     *
     * @return array{status: 'ready'|'error', message?: string, game?: array{bgg_id: int, name: string, image_url: ?string, description: ?string, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int, weight: ?float, mechanics: list<string>, categories: list<string>}}
     */
    public function fetchGameByBggId(int $bggId): array
    {
        if (blank(config('bgg.application_token'))) {
            return [
                'status' => 'error',
                'message' => __('bgg.token_missing'),
            ];
        }

        $cached = Cache::get($this->cacheKey($bggId));

        if ($cached !== null) {
            return ['status' => 'ready', 'game' => $this->buildLookupResult($bggId, $cached)];
        }

        try {
            $response = $this->httpClient()->get(self::BASE_URL.'/thing', [
                'id' => $bggId,
                'stats' => 1,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('BGG /thing lookup failed to connect', ['bgg_id' => $bggId, 'exception' => $e->getMessage()]);

            return ['status' => 'error', 'message' => __('bgg.unreachable')];
        }

        if (! $response->successful()) {
            return ['status' => 'error', 'message' => __('bgg.unreachable')];
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false || ! isset($xml->item)) {
            return ['status' => 'error', 'message' => __('bgg.game_not_found')];
        }

        $detail = $this->parseGameDetail($xml->item);
        Cache::put($this->cacheKey($bggId), $detail, $this->cacheTtl());

        return ['status' => 'ready', 'game' => $this->buildLookupResult($bggId, $detail)];
    }

    /**
     * @param  array{name: string, image_url: ?string, description: ?string, mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_ids: list<int>, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int}  $detail
     * @return array{bgg_id: int, name: string, image_url: ?string, description: ?string, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int, weight: ?float, mechanics: list<string>, categories: list<string>}
     */
    private function buildLookupResult(int $bggId, array $detail): array
    {
        return [
            'bgg_id' => $bggId,
            'name' => $detail['name'],
            'image_url' => $detail['image_url'],
            'description' => $detail['description'],
            'year_published' => $detail['year_published'],
            'min_age' => $detail['min_age'],
            'bgg_rank' => $detail['bgg_rank'],
            'rating' => $detail['rating'],
            'min_players' => $detail['min_players'],
            'max_players' => $detail['max_players'],
            'min_playtime_minutes' => $detail['min_playtime_minutes'],
            'max_playtime_minutes' => $detail['max_playtime_minutes'],
            'weight' => $detail['weight'],
            'mechanics' => $detail['mechanics'],
            'categories' => $detail['categories'],
        ];
    }

    /**
     * Best-effort lookup for the profile's "usa mi avatar de BGG" feature -
     * returns null (never throws) on any failure: missing token, unknown
     * username, or no avatar set on that BGG account. The exact "no avatar"
     * representation in BGG's response isn't confirmed against the real API
     * yet (the application token is still pending approval - see README),
     * so this treats a missing tag, an empty value and a literal "N/A" all
     * the same way, defensively.
     */
    public function fetchUserAvatar(string $username): ?string
    {
        if (blank(config('bgg.application_token'))) {
            return null;
        }

        try {
            $response = $this->httpClient()->get(self::BASE_URL.'/user', ['name' => $username]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false || (string) $xml['id'] === '') {
            return null;
        }

        $avatar = isset($xml->avatarlink) ? (string) $xml->avatarlink['value'] : '';

        return ($avatar !== '' && $avatar !== 'N/A') ? $avatar : null;
    }

    /**
     * Every caller of this (year published, min/max players, min/max
     * playtime) treats 0 the same way the CSV importer's own
     * parsePositiveInt() does: "BGG has no data for this", not a literal
     * zero - a game can't have a 0 minimum player count or be published in
     * year zero, and BGG reports an unset max as 0 rather than omitting it.
     * A real /thing response for a game with no year data confirmed this:
     * BGG returned yearpublished value="0" instead of leaving it out.
     */
    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || (string) $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * Same convention as BggCsvImportService::parsePositiveFloat(): 0 (or
     * blank) means "no data yet", not a real zero, and the result is
     * rounded to two decimals to match both the DB columns' own
     * decimal(_,2) precision and the edit form's step="0.01" inputs.
     */
    private function positiveFloatOrNull(string $value): ?float
    {
        $floatValue = (float) $value;

        return $floatValue > 0 ? round($floatValue, 2) : null;
    }

    private function httpClient(): PendingRequest
    {
        return Http::withToken((string) config('bgg.application_token'))
            ->timeout((int) config('bgg.timeout'))
            ->connectTimeout((int) config('bgg.connect_timeout'));
    }
}
