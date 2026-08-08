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
}
