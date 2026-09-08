<?php

namespace App\Services\Games;

use App\Models\Game;
use App\Models\User;

class PlayStatsCalculator
{
    /**
     * Aggregates over the given user's *entire* play history - a total or
     * a "most played" pulled from just whatever page happens to be loaded
     * client-side would be wrong the moment there's more than one page, so
     * this always queries the full table rather than reusing whatever a
     * list already has in memory.
     *
     * Takes the User (not a pre-built plays() relation) so
     * PlayController::stats() and FriendPlayController::stats() can share
     * this unchanged, and so each of the two aggregate queries below gets
     * its own fresh `plays()` relation/query builder - calling
     * ->toBase() twice on a single already-built relation instance shares
     * its underlying query builder between both queries instead of
     * starting each from the user's own scoped plays().
     *
     * quantity is BGG's own count of same-day repeat plays bundled into
     * one row (see BggClient::fetchPlays' own docblock) - total_plays and
     * total_minutes both sum it (a play logged with quantity 3 counts as
     * 3 plays and 3x its own duration), matching how BGG's own play
     * stats page totals these same two fields.
     *
     * total_minutes only sums rows with a known duration_minutes - a play
     * BGG never got a length for is left out of the total rather than
     * counted as 0, so an all-unknown history reports 0 with
     * duration_known_plays also 0 (the frontend's own cue to show "n/d"
     * instead of "0 min").
     *
     * top_played ranks by summed quantity, capped at the top 3 - asked
     * for directly as a small ranked list rather than just the single
     * most-played game. Rolled up to each game's own base_game_id when it
     * has one (asked for directly): logging a play against an expansion
     * on BGG never also logs one against its base game, even though the
     * base is necessarily part of that same session (its rules/components
     * are what an expansion adds onto, not a separate thing) - without
     * this, a game played often through several different expansions
     * could rank lower than it actually should, split across each one
     * instead of counted as the one game it actually is.
     *
     * Each top_played entry also carries a `breakdown` - the same total
     * split back out by the specific game_id each play was actually
     * logged against, alongside the rolled-up total rather than instead
     * of it (kept after trying it out live against a real multi-
     * expansion play history) - but only once there's more than one
     * contributing row; null otherwise, so the common case
     * (a game with no expansions in the mix) doesn't carry a redundant
     * single-entry array repeating what `count` already says.
     *
     * @return array<string, mixed>
     */
    public function calculate(User $user): array
    {
        // toBase() drops down to the plain query builder (stdClass rows)
        // instead of the Eloquent one - these aggregate columns don't
        // exist on the Play model, so Eloquent has no business hydrating
        // a Play out of them.
        $totals = $user->plays()->toBase()
            ->selectRaw('COALESCE(SUM(quantity), 0) as total_plays')
            ->selectRaw('COUNT(DISTINCT game_id) as distinct_games')
            ->selectRaw('COALESCE(SUM(CASE WHEN duration_minutes IS NOT NULL THEN quantity * duration_minutes ELSE 0 END), 0) as total_minutes')
            ->selectRaw('COALESCE(SUM(CASE WHEN duration_minutes IS NOT NULL THEN quantity ELSE 0 END), 0) as duration_known_plays')
            ->first();

        // One row per actual game_id played (never per ranked/rolled-up
        // id) - small enough to fetch in full and do the rollup/ranking/
        // breakdown grouping in PHP rather than juggling several SQL
        // queries just to get both the ranking and its own breakdown out
        // of the same underlying rows.
        $playCounts = $user->plays()->toBase()
            ->join('games', 'games.id', '=', 'plays.game_id')
            ->select('plays.game_id', 'games.base_game_id')
            ->selectRaw('SUM(plays.quantity) as play_count')
            ->groupBy('plays.game_id', 'games.base_game_id')
            ->get();

        $topGroups = $playCounts
            ->groupBy(fn ($row) => $row->base_game_id ?? $row->game_id)
            ->map(fn ($rows, $rankedGameId) => [
                'ranked_game_id' => $rankedGameId,
                'total' => (int) $rows->sum('play_count'),
                'contributors' => $rows,
            ])
            ->sortByDesc('total')
            ->take(3)
            ->values();

        $gamesById = Game::select('id', 'name', 'image_url')
            ->whereIn('id', $topGroups->pluck('ranked_game_id')->merge(
                $topGroups->flatMap(fn (array $group) => $group['contributors']->pluck('game_id')),
            )->unique())
            ->get()
            ->keyBy('id');

        return [
            'total_plays' => (int) $totals->total_plays,
            'distinct_games' => (int) $totals->distinct_games,
            'total_minutes' => (int) $totals->total_minutes,
            'duration_known_plays' => (int) $totals->duration_known_plays,
            'top_played' => $topGroups->map(function (array $group) use ($gamesById) {
                $game = $gamesById[$group['ranked_game_id']];

                return [
                    'game' => [
                        'id' => $game->id,
                        'name' => $game->name,
                        'image_url' => $game->image_url,
                    ],
                    'count' => $group['total'],
                    'breakdown' => $group['contributors']->count() > 1
                        ? $group['contributors']
                            ->sortByDesc('play_count')
                            ->map(fn ($row) => [
                                'game' => [
                                    'id' => $gamesById[$row->game_id]->id,
                                    'name' => $gamesById[$row->game_id]->name,
                                    'image_url' => $gamesById[$row->game_id]->image_url,
                                ],
                                'count' => (int) $row->play_count,
                            ])
                            ->values()
                            ->all()
                        : null,
                ];
            })->values(),
        ];
    }
}
