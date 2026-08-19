<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'bgg_id',
        'name',
        'image_url',
        'description',
        'description_es',
        'year_published',
        'min_age',
        'bgg_rank',
        'rating',
        'min_players',
        'max_players',
        'min_playtime_minutes',
        'max_playtime_minutes',
        'weight',
        'is_cooperative',
        'is_competitive',
        'has_campaign',
        'base_game_id',
    ];

    protected function casts(): array
    {
        return [
            'is_cooperative' => 'boolean',
            'is_competitive' => 'boolean',
            'has_campaign' => 'boolean',
            'weight' => 'float',
            'rating' => 'float',
        ];
    }

    /**
     * @return BelongsToMany<Mechanic, $this>
     */
    public function mechanics(): BelongsToMany
    {
        return $this->belongsToMany(Mechanic::class);
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function baseGame(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'base_game_id');
    }

    /**
     * @return HasMany<Game, $this>
     */
    public function expansions(): HasMany
    {
        return $this->hasMany(Game::class, 'base_game_id');
    }

    /**
     * @return HasMany<Play, $this>
     */
    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    /**
     * Shared by every BGG-sourced path that creates/updates a Game
     * (collection import, plays import's auto-created games, ...) so the
     * inference logic lives in exactly one place. Independent signals, not
     * a strict either/or: a team-based game can be both cooperative (within
     * a team) and competitive (between teams), and BGG has no direct "is
     * competitive" flag, so it's inferred as "not solo" instead of as the
     * negation of cooperative - that negation previously forced every
     * cooperative game to is_competitive = false, mislabeling
     * semi-cooperative/team games and, on a failed BGG detail fetch (empty
     * $tags), forcing is_competitive = true with no real signal either way.
     *
     * @param  list<string>  $mechanics
     * @param  list<string>  $categories
     * @return array{is_cooperative: bool, is_competitive: bool, has_campaign: bool}
     */
    public static function inferModeFromTags(array $mechanics, array $categories): array
    {
        $tags = [...$mechanics, ...$categories];

        return [
            'is_cooperative' => self::anyContains($tags, 'cooperative'),
            'is_competitive' => ! self::anyContains($tags, 'solo'),
            'has_campaign' => self::anyContains($tags, 'campaign') || self::anyContains($tags, 'legacy'),
        ];
    }

    /** @param  list<string>  $values */
    private static function anyContains(array $values, string $needle): bool
    {
        foreach ($values as $value) {
            if (str_contains(strtolower($value), $needle)) {
                return true;
            }
        }

        return false;
    }
}
