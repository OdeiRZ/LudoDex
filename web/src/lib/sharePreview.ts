// Lógica pura de la vista previa de un enlace público en chats. La usa la función de
// Cloudflare Pages `functions/c/[token].ts` (que la importa con ruta relativa porque
// allí no existe el alias `@`) y la prueban los tests de la app.

export interface SharePreview {
  ownerName: string
  gamesCount: number
}

const BOT_PATTERN =
  /whatsapp|telegrambot|discordbot|twitterbot|facebookexternalhit|facebot|slackbot|linkedinbot|skypeuripreview|signal|mastodon|embedly|pinterest|redditbot|iframely|bingpreview|googlebot/i

/** ¿Pide la página un rastreador de vistas previas (y no una persona con navegador)? */
export function isPreviewBot(userAgent: string | null): boolean {
  return userAgent !== null && BOT_PATTERN.test(userAgent)
}

export function pickLocale(acceptLanguage: string | null): 'es' | 'en' {
  return /^\s*en\b/i.test(acceptLanguage ?? '') ? 'en' : 'es'
}

export function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

/** Título y descripción de la tarjeta; sin datos (enlace caducado o inexistente) son neutros. */
export function previewTexts(
  preview: SharePreview | null,
  locale: 'es' | 'en',
): { title: string; description: string } {
  if (preview === null) {
    return locale === 'en'
      ? {
          title: 'LudoDex',
          description: 'Board game inventory: build your collection and decide what to play.',
        }
      : {
          title: 'LudoDex',
          description: 'Inventario de juegos de mesa: crea tu colección y decide a qué jugar.',
        }
  }
  const { ownerName, gamesCount } = preview
  if (locale === 'en') {
    return {
      title: `${ownerName}'s board game collection`,
      description: `${gamesCount} ${gamesCount === 1 ? 'game' : 'games'} on LudoDex`,
    }
  }
  return {
    title: `Colección de juegos de mesa de ${ownerName}`,
    description: `${gamesCount} ${gamesCount === 1 ? 'juego' : 'juegos'} en LudoDex`,
  }
}
