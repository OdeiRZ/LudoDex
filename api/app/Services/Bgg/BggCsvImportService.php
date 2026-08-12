<?php

namespace App\Services\Bgg;

use App\Models\Game;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports a user's own collection from BGG's "Export as CSV" file - a
 * workaround for when BGG_APPLICATION_TOKEN isn't configured (or BGG's
 * application-approval process is still pending), since this file comes
 * from the user's own logged-in BGG session rather than the gated XML API.
 *
 * Deliberately narrower than the XML import: mechanics, categories and
 * image aren't in this export at all, and expansions are skipped entirely
 * because the file has no equivalent to the XML API's expansion -> base
 * game link, so an imported expansion could never be excluded from the
 * picker the way a properly-linked one is.
 */
class BggCsvImportService
{
    private const REQUIRED_COLUMNS = [
        'objectname', 'objectid', 'itemtype', 'own', 'wishlist', 'privatecomment', 'avgweight',
        'minplaytime', 'maxplaytime', 'yearpublished', 'rank', 'average', 'bggrecagerange',
        'minplayers', 'maxplayers',
    ];

    /**
     * @return array{imported_count: int, skipped_expansions_count: int, skipped_no_status_count: int, warnings: list<string>}
     */
    public function import(UploadedFile $file, User $user): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if ($header === false || array_diff(self::REQUIRED_COLUMNS, $header) !== []) {
            fclose($handle);

            throw ValidationException::withMessages(['file' => [__('bgg.csv_invalid_format')]]);
        }

        $importedCount = 0;
        $skippedExpansions = 0;
        $skippedNoStatus = 0;
        $warnings = [];

        DB::transaction(function () use ($handle, $header, $user, &$importedCount, &$skippedExpansions, &$skippedNoStatus) {
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== count($header)) {
                    continue;
                }

                $data = array_combine($header, $row);

                if ($data['itemtype'] === 'expansion') {
                    $skippedExpansions++;

                    continue;
                }

                $status = $this->resolveStatus($data);

                if ($status === null) {
                    $skippedNoStatus++;

                    continue;
                }

                $bggId = (int) $data['objectid'];

                if ($bggId <= 0) {
                    continue;
                }

                $attributes = ['name' => $data['objectname']];

                $weight = $this->parsePositiveFloat($data['avgweight']);
                if ($weight !== null) {
                    $attributes['weight'] = $weight;
                }

                $rating = $this->parsePositiveFloat($data['average']);
                if ($rating !== null) {
                    $attributes['rating'] = $rating;
                }

                $minPlaytime = $this->parsePositiveInt($data['minplaytime']);
                if ($minPlaytime !== null) {
                    $attributes['min_playtime_minutes'] = $minPlaytime;
                }

                $maxPlaytime = $this->parsePositiveInt($data['maxplaytime']);
                if ($maxPlaytime !== null) {
                    $attributes['max_playtime_minutes'] = $maxPlaytime;
                }

                $yearPublished = $this->parsePositiveInt($data['yearpublished']);
                if ($yearPublished !== null) {
                    $attributes['year_published'] = $yearPublished;
                }

                // 0 is BGG's own way of saying "unranked" (too few ratings
                // to place it), not "we don't know" - parsePositiveInt
                // already turns that into null, which is exactly what we
                // want stored.
                $rank = $this->parsePositiveInt($data['rank']);
                if ($rank !== null) {
                    $attributes['bgg_rank'] = $rank;
                }

                $minAge = trim($data['bggrecagerange']);
                if ($minAge !== '') {
                    $attributes['min_age'] = $minAge;
                }

                $minPlayers = $this->parsePositiveInt($data['minplayers']);
                if ($minPlayers !== null) {
                    $attributes['min_players'] = $minPlayers;
                }

                $maxPlayers = $this->parsePositiveInt($data['maxplayers']);
                if ($maxPlayers !== null) {
                    $attributes['max_players'] = $maxPlayers;
                }

                $mode = $this->parseMode($data['privatecomment']);
                if ($mode !== null) {
                    $attributes = [...$attributes, ...$mode];
                }

                $game = Game::updateOrCreate(['bgg_id' => $bggId], $attributes);

                $user->games()->updateOrCreate(['game_id' => $game->id], ['status' => $status]);

                $importedCount++;
            }
        });

        fclose($handle);

        return [
            'imported_count' => $importedCount,
            'skipped_expansions_count' => $skippedExpansions,
            'skipped_no_status_count' => $skippedNoStatus,
            'warnings' => array_slice($warnings, 0, 20),
        ];
    }

    /** @param  array<string, string>  $data */
    private function resolveStatus(array $data): ?string
    {
        if ($data['own'] === '1') {
            return 'owned';
        }

        if ($data['wishlist'] === '1') {
            return 'wishlist';
        }

        return null;
    }

    private function parsePositiveFloat(string $raw): ?float
    {
        $value = (float) $raw;

        return $value > 0 ? round($value, 2) : null;
    }

    private function parsePositiveInt(string $raw): ?int
    {
        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    /**
     * Some collections (namely the app author's own) annotate the
     * cooperative/competitive mode in the private comment's first line
     * (e.g. "Cooperativo - 1/4") - a personal convention, not something
     * any BGG export carries in a dedicated column, so unlike player
     * counts (read from BGG's own minplayers/maxplayers columns above)
     * there's no fallback source for this. Returning null when neither
     * keyword is found leaves the flags alone instead of forcing them to
     * false, the same way every other field here avoids overwriting a
     * game's existing good data with blank/default values from a row
     * that simply doesn't carry that information.
     *
     * @return array{is_cooperative: bool, is_competitive: bool}|null
     */
    private function parseMode(string $raw): ?array
    {
        $firstLine = mb_strtolower(trim(strtok($raw, "\n")));

        $isCooperative = str_contains($firstLine, 'cooperativo');
        $isCompetitive = str_contains($firstLine, 'competitivo');

        if (! $isCooperative && ! $isCompetitive) {
            return null;
        }

        return ['is_cooperative' => $isCooperative, 'is_competitive' => $isCompetitive];
    }
}
