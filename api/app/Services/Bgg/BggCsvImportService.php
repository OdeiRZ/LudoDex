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

        DB::transaction(function () use ($handle, $header, $user, &$importedCount, &$skippedExpansions, &$skippedNoStatus, &$warnings) {
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

                $parsed = $this->parsePrivateComment($data['privatecomment']);

                if ($parsed !== null) {
                    $attributes = [...$attributes, ...$parsed];
                } else {
                    $warnings[] = __('bgg.csv_unparsed_mode', ['name' => $data['objectname']]);
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
     * The user annotates every collection entry's "private comment" with
     * their own mode and player count (e.g. "Cooperativo - 1/4",
     * "Competitivo - 2", "Solitario"), sometimes followed by a free-text
     * review on later lines - deliberately more trustworthy here than
     * BGG's own box-reported min/maxplayers columns in the same export,
     * since it reflects how they actually play a game rather than what
     * the publisher printed (confirmed against real data: 48 of 281 rows
     * in the reference export disagree with BGG's own numbers).
     *
     * @return array{is_cooperative: bool, is_competitive: bool, min_players: int, max_players: ?int}|null
     */
    private function parsePrivateComment(string $raw): ?array
    {
        $firstLine = trim(strtok($raw, "\n"));

        if (preg_match('/^([^-]+?)\s*-\s*(\d+)(?:\s*\/\s*(\d+|X))?\s*$/ui', $firstLine, $m)) {
            $mode = $m[1];
            $min = (int) $m[2];

            if (! isset($m[3])) {
                $max = $min;
            } elseif (ctype_digit($m[3])) {
                $max = (int) $m[3];
            } else {
                $max = null;
            }
        } elseif (mb_strtolower($firstLine) === 'solitario') {
            $mode = $firstLine;
            $min = 1;
            $max = 1;
        } else {
            return null;
        }

        $modeLower = mb_strtolower($mode);

        return [
            'is_cooperative' => str_contains($modeLower, 'cooperativo'),
            'is_competitive' => str_contains($modeLower, 'competitivo'),
            'min_players' => $min,
            'max_players' => $max,
        ];
    }
}
