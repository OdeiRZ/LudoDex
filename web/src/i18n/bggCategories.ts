import type { Locale } from '@/i18n'

// BGG's own category vocabulary is fixed and always in English, regardless
// of the app's own language setting - these are what actually gets stored
// (and sent back to the API for filtering) on every game, sourced straight
// from BGG's <link type="boardgamecategory"> or entered by hand. This map
// only affects the label shown to a Spanish-reading user; unrecognized
// categories (a hand-typed one, or a BGG category added after this list was
// last updated) just fall back to the original English name instead of
// showing nothing.
const ES_TRANSLATIONS: Record<string, string> = {
  'Abstract Strategy': 'Estrategia abstracta',
  'Action / Dexterity': 'Acción / Destreza',
  Adventure: 'Aventuras',
  'Age of Reason': 'Edad de la Razón',
  'American West': 'Salvaje Oeste',
  Ancient: 'Antigüedad',
  Animals: 'Animales',
  Arabian: 'Árabe',
  'Aviation / Flight': 'Aviación / Vuelo',
  Bluffing: 'Engaño',
  'Card Game': 'Juego de cartas',
  "Children's Game": 'Juego infantil',
  'City Building': 'Construcción de ciudades',
  'Civil War': 'Guerra Civil',
  Civilization: 'Civilización',
  'Collectible Components': 'Componentes coleccionables',
  'Comic Book / Strip': 'Cómic',
  Deduction: 'Deducción',
  Dice: 'Dados',
  Economic: 'Económico',
  Educational: 'Educativo',
  Electronic: 'Electrónico',
  Environmental: 'Medioambiental',
  'Expansion for Base-game': 'Expansión de un juego base',
  Exploration: 'Exploración',
  Fantasy: 'Fantasía',
  Farming: 'Agricultura',
  Fighting: 'Lucha',
  'Game System': 'Sistema de juego',
  Horror: 'Terror',
  Humor: 'Humor',
  'Industry / Manufacturing': 'Industria / Manufactura',
  Maze: 'Laberinto',
  Medical: 'Médico',
  Medieval: 'Medieval',
  Memory: 'Memoria',
  Miniatures: 'Miniaturas',
  'Modern Warfare': 'Guerra moderna',
  'Movies / TV / Radio theme': 'Cine / TV / Radio',
  'Murder / Mystery': 'Misterio / Asesinato',
  Mythology: 'Mitología',
  Nautical: 'Náutico',
  Negotiation: 'Negociación',
  'Novel-based': 'Basado en novela',
  Number: 'Números',
  'Party Game': 'Juego de fiesta',
  Pirates: 'Piratas',
  Political: 'Político',
  'Post-Napoleonic': 'Posnapoleónico',
  Prehistoric: 'Prehistórico',
  'Print & Play': 'Imprime y juega',
  Puzzle: 'Rompecabezas',
  Racing: 'Carreras',
  'Real-time': 'Tiempo real',
  Renaissance: 'Renacimiento',
  'Science Fiction': 'Ciencia ficción',
  'Space Exploration': 'Exploración espacial',
  'Spies / Secret Agents': 'Espías / Agentes secretos',
  'Territory Building': 'Construcción de territorio',
  Trains: 'Trenes',
  Transportation: 'Transporte',
  Travel: 'Viajes',
  Trivia: 'Trivial',
  'Video Game Theme': 'Temática de videojuego',
  Wargame: 'Juego de guerra',
  'Word Game': 'Juego de palabras',
  Zombies: 'Zombis',
}

export function translateCategory(category: string, locale: Locale): string {
  if (locale !== 'es') {
    return category
  }

  return ES_TRANSLATIONS[category] ?? category
}
