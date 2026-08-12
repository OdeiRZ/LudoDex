import { computed, type ComputedRef } from 'vue'
import type { UserGame } from '@/stores/games'

// Maps a base game's id to how many of its expansions are in the same
// collection - regardless of owned/wishlist status on either side, since
// this is just a "there's more to this game" indicator, not an ownership
// audit.
export function useExpansionCounts(collection: ComputedRef<UserGame[]>) {
  return computed(() => {
    const counts: Record<string, number> = {}

    for (const entry of collection.value) {
      const baseId = entry.game.base_game_id
      if (baseId !== null) {
        counts[baseId] = (counts[baseId] ?? 0) + 1
      }
    }

    return counts
  })
}
