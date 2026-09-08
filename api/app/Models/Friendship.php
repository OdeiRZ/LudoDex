<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Friendship extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_id',
        'recipient_id',
        'status',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `requester_id`/`recipient_id` only records who sent the request -
     * once `accepted` the relationship reads as symmetric, so callers that
     * just want "the other person" (not caring who requested whom) use
     * this instead of checking both columns themselves.
     */
    public function otherUserFor(User $user): User
    {
        return $this->requester_id === $user->id ? $this->recipient : $this->requester;
    }

    /**
     * @param  Builder<Friendship>  $query
     * @return Builder<Friendship>
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', 'accepted');
    }

    /**
     * @param  Builder<Friendship>  $query
     * @return Builder<Friendship>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
