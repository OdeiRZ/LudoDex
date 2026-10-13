<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token de Sanctum que no reescribe `last_used_at` en CADA petición.
 *
 * Sanctum guarda la hora de último uso con un UPDATE en cada petición
 * autenticada. Contra una base remota (Neon) es un viaje de ida y vuelta
 * más, con confirmación de escritura, antes de hacer nada útil: medido en
 * producción, una petición autenticada hacía 2 lecturas y esa escritura.
 * Aquí la escritura solo ocurre si la hora guardada tiene más de
 * `REFRESH_AFTER_MINUTES`: nada en la app depende de que sea exacta al
 * segundo (solo indica "este token se usó hace poco").
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public const REFRESH_AFTER_MINUTES = 5;

    public function save(array $options = []): bool
    {
        if ($this->shouldSkipLastUsedAtWrite()) {
            return true;
        }

        return parent::save($options);
    }

    /** Solo se omite el guardado que cambia únicamente last_used_at/updated_at y es reciente. */
    private function shouldSkipLastUsedAtWrite(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $dirty = array_keys($this->getDirty());

        if ($dirty === [] || array_diff($dirty, ['last_used_at', 'updated_at']) !== []) {
            return false;
        }

        $previous = $this->getOriginal('last_used_at');

        return $previous !== null
            && $previous->gt(now()->subMinutes(self::REFRESH_AFTER_MINUTES));
    }
}
