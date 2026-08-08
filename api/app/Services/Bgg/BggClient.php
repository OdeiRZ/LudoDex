<?php

namespace App\Services\Bgg;

use Illuminate\Http\Client\PendingRequest;
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
                'message' => 'Falta configurar BGG_APPLICATION_TOKEN (BoardGameGeek exige un token de aplicación registrado; ver https://boardgamegeek.com/using_the_xml_api).',
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
            return ['status' => 'error', 'message' => 'No se pudo contactar con BoardGameGeek.'];
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            return ['status' => 'error', 'message' => 'Respuesta inesperada de BoardGameGeek.'];
        }

        if ($xml->getName() === 'errors') {
            $message = isset($xml->error->message)
                ? (string) $xml->error->message
                : 'Usuario de BoardGameGeek no encontrado.';

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
     * @return array<int, array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_id: ?int}>
     */
    public function fetchGameDetails(array $bggIds): array
    {
        $details = [];

        foreach (array_chunk($bggIds, 20) as $chunk) {
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
                $details[(int) $item['id']] = $this->parseGameDetail($item);
            }
        }

        return $details;
    }

    /**
     * @return array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_id: ?int}
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

        return [
            'mechanics' => $mechanics,
            'categories' => $categories,
            'weight' => $weight,
            'base_game_bgg_id' => $baseGameBggId,
        ];
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
