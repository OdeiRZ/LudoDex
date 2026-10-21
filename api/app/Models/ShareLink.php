<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Enlace público de solo lectura a la colección ("lo tengo") de un usuario.
 */
class ShareLink extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'token', 'expires_at', 'views_count', 'last_viewed_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'views_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 32 bytes aleatorios (256 bits) en base64 seguro para URL: 43 caracteres.
     */
    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Enlaces vigentes: sin caducidad, o con una fecha todavía por llegar.
     *
     * @param  Builder<ShareLink>  $query
     * @return Builder<ShareLink>
     */
    public function scopeActive($query)
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public static function isWellFormedToken(string $token): bool
    {
        return Str::length($token) === 43 && preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1;
    }
}
