<?php

namespace App\Http\Resources;

use App\Models\UserGame;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserGame
 */
class UserGameResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'game' => new GameResource($this->whenLoaded('game')),
        ];
    }
}
