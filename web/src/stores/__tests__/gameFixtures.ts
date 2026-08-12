import type { Game, UserGame } from '@/stores/games'

export function makeGame(overrides: Partial<Game> = {}): Game {
  return {
    id: overrides.id ?? crypto.randomUUID(),
    bgg_id: null,
    base_game_id: null,
    name: 'Juego',
    image_url: null,
    year_published: null,
    min_age: null,
    bgg_rank: null,
    rating: null,
    min_players: null,
    max_players: null,
    min_playtime_minutes: null,
    max_playtime_minutes: null,
    weight: null,
    is_cooperative: false,
    is_competitive: false,
    has_campaign: false,
    mechanics: [],
    categories: [],
    ...overrides,
  }
}

export function makeEntry(game: Partial<Game> = {}, status: UserGame['status'] = 'owned'): UserGame {
  const builtGame = makeGame(game)
  return { id: builtGame.id, status, notes: null, game: builtGame }
}
