<?php

namespace App\Http\Resources;

use App\Models\Play;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Play
 */
class PlayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'played_at' => $this->played_at->toDateString(),
            'quantity' => $this->quantity,
            'duration_minutes' => $this->duration_minutes,
            'game' => [
                'id' => $this->game->id,
                'bgg_id' => $this->game->bgg_id,
                'name' => $this->game->name,
                'image_url' => $this->game->image_url,
            ],
        ];
    }
}
