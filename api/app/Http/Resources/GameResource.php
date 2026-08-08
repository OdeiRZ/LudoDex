<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Game
 */
class GameResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bgg_id' => $this->bgg_id,
            'base_game_id' => $this->base_game_id,
            'name' => $this->name,
            'image_url' => $this->image_url,
            'min_players' => $this->min_players,
            'max_players' => $this->max_players,
            'min_playtime_minutes' => $this->min_playtime_minutes,
            'max_playtime_minutes' => $this->max_playtime_minutes,
            'weight' => $this->weight,
            'is_cooperative' => $this->is_cooperative,
            'is_competitive' => $this->is_competitive,
            'has_campaign' => $this->has_campaign,
            'mechanics' => $this->whenLoaded('mechanics', fn () => $this->mechanics->pluck('name')),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->pluck('name')),
        ];
    }
}
