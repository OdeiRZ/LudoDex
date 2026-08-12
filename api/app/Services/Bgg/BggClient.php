<?php

namespace App\Services\Bgg;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
     * @return array{status: 'pending'|'error'|'ready', message?: string, items?: list<array{bgg_id: int, name: string, image_url: ?string, is_expansion: bool, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int, collection_status: 'owned'|'wishlist'}>}
     */
    public function fetchCollection(string $username): array
    {
        if (blank(config('bgg.application_token'))) {
            return [
                'status' => 'error',
                'message' => __('bgg.token_missing'),
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
     * @return array{status: 'pending'|'error'|'ready', message?: string, items?: list<array<string, mixed>>}
     */
    private function fetchCollectionBySubtype(string $username, string $subtype): array
    {
        $response = $this->httpClient()->get(self::BASE_URL.'/collection', [
            'username' => $username,
            'subtype' => $subtype,
            'stats' => 1,
        ]);

        if ($response->status() === 202) {
            return ['status' => 'pending'];
        }

        if (! $response->successful()) {
            return ['status' => 'error', 'message' => __('bgg.unreachable')];
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            return ['status' => 'error', 'message' => __('bgg.unexpected_response')];
        }

        if ($xml->getName() === 'errors') {
            $message = isset($xml->error->message)
                ? (string) $xml->error->message
                : __('bgg.user_not_found');

            return ['status' => 'error', 'message' => $message];
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
                'min_players' => $this->intOrNull($stats['minplayers'] ?? null),
                'max_players' => $this->intOrNull($stats['maxplayers'] ?? null),
                'min_playtime_minutes' => $this->intOrNull($stats['minplaytime'] ?? null),
                'max_playtime_minutes' => $this->intOrNull($stats['maxplaytime'] ?? null),
                'collection_status' => $isOwned ? 'owned' : 'wishlist',
            ];
        }

        return ['status' => 'ready', 'items' => $items];
    }

    /**
     * @param  list<int>  $bggIds
     * @return array<int, array{name: string, image_url: ?string, mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_id: ?int, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int}>
     */
    public function fetchGameDetails(array $bggIds): array
    {
        $details = [];
        $idsToFetch = [];

        foreach ($bggIds as $bggId) {
            $cached = Cache::get($this->cacheKey($bggId));

            if ($cached !== null) {
                $details[$bggId] = $cached;
            } else {
                $idsToFetch[] = $bggId;
            }
        }

        foreach (array_chunk($idsToFetch, 20) as $chunk) {
            $response = $this->httpClient()->get(self::BASE_URL.'/thing', [
                'id' => implode(',', $chunk),
                'stats' => 1,
            ]);

            if (! $response->successful()) {
                continue;
            }

            $xml = @simplexml_load_string($response->body());

            if ($xml === false) {
                continue;
            }

            foreach ($xml->item as $item) {
                $id = (int) $item['id'];
                $detail = $this->parseGameDetail($item);

                Cache::put($this->cacheKey($id), $detail, $this->cacheTtl());
                $details[$id] = $detail;
            }
        }

        return $details;
    }

    private function cacheKey(int $bggId): string
    {
        return "bgg:thing:{$bggId}";
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
     * @return array{name: string, image_url: ?string, mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_id: ?int, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int}
     */
    private function parseGameDetail(SimpleXMLElement $item): array
    {
        $mechanics = [];
        $categories = [];
        $baseGameBggId = null;

        foreach ($item->link as $link) {
            $type = (string) $link['type'];

            if ($type === 'boardgamemechanic') {
                $mechanics[] = (string) $link['value'];
            } elseif ($type === 'boardgamecategory') {
                $categories[] = (string) $link['value'];
            } elseif ($type === 'boardgameexpansion' && (string) $link['inbound'] === 'true') {
                $baseGameBggId = (int) $link['id'];
            }
        }

        $weight = isset($item->statistics->ratings->averageweight)
            ? (float) $item->statistics->ratings->averageweight['value']
            : null;

        $rating = isset($item->statistics->ratings->average)
            ? (float) $item->statistics->ratings->average['value']
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
            'mechanics' => $mechanics,
            'categories' => $categories,
            'weight' => $weight,
            'base_game_bgg_id' => $baseGameBggId,
            'year_published' => $this->intOrNull($item->yearpublished['value'] ?? null),
            // BGG's own site shows this value with a trailing "+" (e.g.
            // "Ages: 8+"), not as a plain number - kept as a string here
            // (like the CSV importer's bggrecagerange) rather than a plain
            // int, since it's a display convention, not an exact minimum.
            'min_age' => $this->minAgeOrNull($item->minage['value'] ?? null),
            'bgg_rank' => $this->extractBoardGameRank($item),
            'rating' => $rating,
            'min_players' => $this->intOrNull($item->minplayers['value'] ?? null),
            'max_players' => $this->intOrNull($item->maxplayers['value'] ?? null),
            'min_playtime_minutes' => $this->intOrNull($item->minplaytime['value'] ?? null),
            'max_playtime_minutes' => $this->intOrNull($item->maxplaytime['value'] ?? null),
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
     * Looks up a single game by its BGG id for the manual add/edit form's
     * "import from BGG" button - unlike fetchCollection, /thing answers
     * synchronously (no 202-while-queued state to poll).
     *
     * @return array{status: 'ready'|'error', message?: string, game?: array{bgg_id: int, name: string, image_url: ?string, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int, weight: ?float, mechanics: list<string>, categories: list<string>}}
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

        $response = $this->httpClient()->get(self::BASE_URL.'/thing', [
            'id' => $bggId,
            'stats' => 1,
        ]);

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
     * @param  array{name: string, image_url: ?string, mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_id: ?int, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int}  $detail
     * @return array{bgg_id: int, name: string, image_url: ?string, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, min_players: ?int, max_players: ?int, min_playtime_minutes: ?int, max_playtime_minutes: ?int, weight: ?float, mechanics: list<string>, categories: list<string>}
     */
    private function buildLookupResult(int $bggId, array $detail): array
    {
        return [
            'bgg_id' => $bggId,
            'name' => $detail['name'],
            'image_url' => $detail['image_url'],
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

        $response = $this->httpClient()->get(self::BASE_URL.'/user', ['name' => $username]);

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

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || (string) $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function httpClient(): PendingRequest
    {
        return Http::withToken((string) config('bgg.application_token'));
    }
}
