<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The safe, public-facing shape of a User for anything friend-related
 * (search results, friend/request lists) - deliberately never exposes
 * `email`, so a friend-of-a-friend can't harvest email addresses just by
 * looking at who's on someone's friends list.
 *
 * @mixin User
 */
class FriendResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'bgg_username' => $this->bgg_username,
            'avatar_url' => $this->avatar_url,
        ];
    }
}
